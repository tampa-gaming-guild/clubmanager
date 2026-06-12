<?php
namespace App;

use PDO;
use Exception;

/**
 * Billing Helper
 * Handles local billing transactions ledger, local subscription states, and CiviCRM sync.
 */
class BillingHelper {

    /**
     * Get all local subscription plans
     * @return array
     */
    public static function getSubscriptionPlans(): array {
        try {
            $db = Database::getAppConnection();
            $stmt = $db->query("SELECT *, price as minimum_fee FROM tgg_subscription_plans ORDER BY price ASC");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Get local subscription details for a contact
     * @param int $contactId
     * @return array|null
     */
    public static function getMemberSubscriptionDetails(int $contactId): ?array {
        try {
            $db = Database::getAppConnection();
            $stmt = $db->prepare("
                SELECT s.status, s.join_date, s.start_date, s.end_date, p.name as plan_name, p.name as membership_name, p.price, p.duration_unit, p.duration_interval
                FROM tgg_subscriptions s
                INNER JOIN tgg_subscription_plans p ON s.plan_id = p.id
                WHERE s.contact_id = :contact_id
                LIMIT 1
            ");
            $stmt->execute(['contact_id' => $contactId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($row) {
                // Compute dynamic is_active flag locally based on end date
                $today = date('Y-m-d');
                $row['is_active'] = (strtolower($row['status']) === 'active' && strtotime($row['end_date']) >= strtotime($today)) ? 1 : 0;
                $row['status_label'] = $row['is_active'] ? 'Current' : 'Expired';
            }
            
            return $row ?: null;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Process a successful Stripe checkout session idempotently
     * Logs to local transaction ledger, updates local subscription status, and synchronizes to CiviCRM databases.
     * @param array $session Stripe Checkout Session array
     * @return bool True if processed successfully
     * @throws Exception
     */
    public static function processCheckoutSession(array $session): bool {
        $sessionId = $session['id'] ?? '';
        if (empty($sessionId)) {
            throw new Exception("Invalid checkout session payload: missing ID.");
        }

        $appDb = Database::getAppConnection();
        $civiDb = Database::getCiviConnection();

        // 1. Check Idempotency - has this session already been logged?
        $stmt = $appDb->prepare("SELECT id FROM tgg_billing_ledger WHERE stripe_session_id = :session_id LIMIT 1");
        $stmt->execute(['session_id' => $sessionId]);
        if ($stmt->fetch()) {
            return true; // Already processed
        }

        $metadata = $session['metadata'] ?? null;
        if (!$metadata || !isset($metadata['contact_id'], $metadata['plan_id'])) {
            throw new Exception("Missing metadata (contact_id or plan_id) in Stripe Checkout session.");
        }

        $contactId = (int)$metadata['contact_id'];
        $planId = (int)$metadata['plan_id'];
        $action = $metadata['action'] ?? 'join';
        $amountTotal = (float)(($session['amount_total'] ?? 0) / 100);
        $currency = strtolower($session['currency'] ?? 'usd');
        $paymentIntentId = $session['payment_intent'] ?? $session['id'];

        // Get local plan details
        $planStmt = $appDb->prepare("SELECT * FROM tgg_subscription_plans WHERE id = :id LIMIT 1");
        $planStmt->execute(['id' => $planId]);
        $plan = $planStmt->fetch(PDO::FETCH_ASSOC);
        if (!$plan) {
            throw new Exception("Local plan ID {$planId} not found.");
        }

        // 2. Start Transactions on both databases to ensure integrity
        $appDb->beginTransaction();
        $civiDb->beginTransaction();

        try {
            // A. Log transaction locally in tgg_billing_ledger
            $insertLedger = $appDb->prepare("
                INSERT INTO tgg_billing_ledger (contact_id, plan_id, stripe_session_id, payment_intent_id, amount, currency, payment_status, action_type)
                VALUES (:contact_id, :plan_id, :stripe_session_id, :payment_intent_id, :amount, :currency, 'paid', :action_type)
            ");
            $insertLedger->execute([
                'contact_id' => $contactId,
                'plan_id' => $planId,
                'stripe_session_id' => $sessionId,
                'payment_intent_id' => $paymentIntentId,
                'amount' => $amountTotal,
                'currency' => $currency,
                'action_type' => $action
            ]);

            // B. Calculate start and end dates
            $durationInterval = (int)$plan['duration_interval'];
            $durationUnit = strtolower($plan['duration_unit']);

            // Query existing local subscription
            $subStmt = $appDb->prepare("SELECT join_date, end_date FROM tgg_subscriptions WHERE contact_id = :contact_id LIMIT 1");
            $subStmt->execute(['contact_id' => $contactId]);
            $existingSub = $subStmt->fetch(PDO::FETCH_ASSOC);

            $today = date('Y-m-d');
            $startDate = $today;

            if ($existingSub && $action === 'renew') {
                $existingEndDate = $existingSub['end_date'];
                // If existing subscription is still active, start renewal from day after current expiry
                if (strtotime($existingEndDate) >= strtotime($today)) {
                    $startDate = date('Y-m-d', strtotime($existingEndDate . ' +1 day'));
                }
            }

            $unitString = $durationUnit === 'month' ? 'month' : 'year';
            $endDate = date('Y-m-d', strtotime($startDate . " +{$durationInterval} {$unitString}"));

            // C. Insert or update local subscription
            if ($existingSub) {
                $updateSub = $appDb->prepare("
                    UPDATE tgg_subscriptions 
                    SET plan_id = :plan_id, status = 'active', start_date = :start_date, end_date = :end_date 
                    WHERE contact_id = :contact_id
                ");
                $updateSub->execute([
                    'plan_id' => $planId,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'contact_id' => $contactId
                ]);
            } else {
                $joinDate = $today;
                if ($action === 'renew') {
                    // Try to fetch existing join_date from CiviCRM
                    $civiMemStmt = $civiDb->prepare("SELECT join_date FROM civicrm_membership WHERE contact_id = :contact_id LIMIT 1");
                    $civiMemStmt->execute(['contact_id' => $contactId]);
                    $civiMemRow = $civiMemStmt->fetch(PDO::FETCH_ASSOC);
                    if ($civiMemRow && !empty($civiMemRow['join_date'])) {
                        $joinDate = $civiMemRow['join_date'];
                    }
                }

                $insertSub = $appDb->prepare("
                    INSERT INTO tgg_subscriptions (contact_id, plan_id, status, join_date, start_date, end_date)
                    VALUES (:contact_id, :plan_id, 'active', :join_date, :start_date, :end_date)
                ");
                $insertSub->execute([
                    'contact_id' => $contactId,
                    'plan_id' => $planId,
                    'join_date' => $joinDate,
                    'start_date' => $startDate,
                    'end_date' => $endDate
                ]);
            }

            // D. Sync to CiviCRM Contribution Table
            $insertContribution = $civiDb->prepare("
                INSERT INTO civicrm_contribution (contact_id, financial_type_id, receive_date, total_amount, trxn_id, contribution_status_id) 
                VALUES (:contact_id, 1, NOW(), :total_amount, :trxn_id, 1)
            ");
            $insertContribution->execute([
                'contact_id' => $contactId,
                'total_amount' => $amountTotal,
                'trxn_id' => $paymentIntentId
            ]);

            // E. Sync to CiviCRM Membership Table
            $civiMembershipTypeId = (int)$plan['civicrm_membership_type_id'];
            $memberQuery = $civiDb->prepare("SELECT id FROM civicrm_membership WHERE contact_id = :contact_id LIMIT 1");
            $memberQuery->execute(['contact_id' => $contactId]);
            $existingCiviMembership = $memberQuery->fetch(PDO::FETCH_ASSOC);

            if ($existingCiviMembership) {
                // Update CiviCRM (status_id = 2 represents 'Current' status)
                $updateMembership = $civiDb->prepare("
                    UPDATE civicrm_membership 
                    SET membership_type_id = :membership_type_id, start_date = :start_date, end_date = :end_date, status_id = 2 
                    WHERE id = :id
                ");
                $updateMembership->execute([
                    'membership_type_id' => $civiMembershipTypeId,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'id' => (int)$existingCiviMembership['id']
                ]);
            } else {
                // Insert new CiviCRM Membership record
                $insertMembership = $civiDb->prepare("
                    INSERT INTO civicrm_membership (contact_id, membership_type_id, join_date, start_date, end_date, status_id) 
                    VALUES (:contact_id, :membership_type_id, :join_date, :start_date, :end_date, 2)
                ");
                $insertMembership->execute([
                    'contact_id' => $contactId,
                    'membership_type_id' => $civiMembershipTypeId,
                    'join_date' => $today,
                    'start_date' => $startDate,
                    'end_date' => $endDate
                ]);
            }

            // Commit both transactions
            $appDb->commit();
            $civiDb->commit();
            return true;

        } catch (Exception $e) {
            if ($appDb->inTransaction()) {
                $appDb->rollBack();
            }
            if ($civiDb->inTransaction()) {
                $civiDb->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Add or update a membership plan locally and sync to CiviCRM
     * @param array $data Plan attributes
     * @return bool
     * @throws Exception
     */
    public static function savePlan(array $data): bool {
        $appDb = Database::getAppConnection();
        $civiDb = Database::getCiviConnection();

        $id = isset($data['id']) ? (int)$data['id'] : null;
        $name = trim($data['name'] ?? '');
        $description = trim($data['description'] ?? '');
        $price = (float)($data['price'] ?? 0);
        $durationUnit = strtolower($data['duration_unit'] ?? 'year');
        $durationInterval = (int)($data['duration_interval'] ?? 1);

        if (empty($name)) {
            throw new Exception("Plan name cannot be empty.");
        }
        if ($price < 0) {
            throw new Exception("Price cannot be negative.");
        }
        if ($durationInterval <= 0) {
            throw new Exception("Duration interval must be greater than zero.");
        }
        if (!in_array($durationUnit, ['month', 'year'])) {
            throw new Exception("Invalid duration unit. Allowed units are 'month' or 'year'.");
        }

        $appDb->beginTransaction();
        $civiDb->beginTransaction();

        try {
            if ($id) {
                // Update existing plan
                // 1. Retrieve the corresponding CiviCRM membership type ID
                $planStmt = $appDb->prepare("SELECT civicrm_membership_type_id FROM tgg_subscription_plans WHERE id = :id LIMIT 1");
                $planStmt->execute(['id' => $id]);
                $plan = $planStmt->fetch(PDO::FETCH_ASSOC);
                if (!$plan) {
                    throw new Exception("Plan ID {$id} not found.");
                }
                $civiTypeId = (int)$plan['civicrm_membership_type_id'];

                // 2. Update CiviCRM table
                $updateCivi = $civiDb->prepare("
                    UPDATE civicrm_membership_type 
                    SET name = :name, title = :title, frontend_title = :frontend_title, description = :description, minimum_fee = :price, duration_unit = :duration_unit, duration_interval = :duration_interval 
                    WHERE id = :id
                ");
                $updateCivi->execute([
                    'name' => $name,
                    'title' => $name,
                    'frontend_title' => $name,
                    'description' => $description,
                    'price' => $price,
                    'duration_unit' => $durationUnit,
                    'duration_interval' => $durationInterval,
                    'id' => $civiTypeId
                ]);

                // 3. Update Local table
                $updateLocal = $appDb->prepare("
                    UPDATE tgg_subscription_plans 
                    SET name = :name, description = :description, price = :price, duration_unit = :duration_unit, duration_interval = :duration_interval 
                    WHERE id = :id
                ");
                $updateLocal->execute([
                    'name' => $name,
                    'description' => $description,
                    'price' => $price,
                    'duration_unit' => $durationUnit,
                    'duration_interval' => $durationInterval,
                    'id' => $id
                ]);

            } else {
                // Insert new plan
                // 1. Insert into CiviCRM first to generate the membership type ID
                $insertCivi = $civiDb->prepare("
                    INSERT INTO civicrm_membership_type (domain_id, name, title, frontend_title, description, member_of_contact_id, financial_type_id, minimum_fee, duration_unit, duration_interval, period_type, is_active, visibility) 
                    VALUES (1, :name, :title, :frontend_title, :description, 1, 2, :price, :duration_unit, :duration_interval, 'rolling', 1, 'Public')
                ");
                $insertCivi->execute([
                    'name' => $name,
                    'title' => $name,
                    'frontend_title' => $name,
                    'description' => $description,
                    'price' => $price,
                    'duration_unit' => $durationUnit,
                    'duration_interval' => $durationInterval
                ]);
                $civiTypeId = (int)$civiDb->lastInsertId();

                // 2. Insert into Local table
                $insertLocal = $appDb->prepare("
                    INSERT INTO tgg_subscription_plans (name, description, price, duration_unit, duration_interval, civicrm_membership_type_id) 
                    VALUES (:name, :description, :price, :duration_unit, :duration_interval, :civicrm_membership_type_id)
                ");
                $insertLocal->execute([
                    'name' => $name,
                    'description' => $description,
                    'price' => $price,
                    'duration_unit' => $durationUnit,
                    'duration_interval' => $durationInterval,
                    'civicrm_membership_type_id' => $civiTypeId
                ]);
            }

            $appDb->commit();
            $civiDb->commit();
            return true;

        } catch (Exception $e) {
            if ($appDb->inTransaction()) {
                $appDb->rollBack();
            }
            if ($civiDb->inTransaction()) {
                $civiDb->rollBack();
            }
            throw $e;
        }
    }
}
