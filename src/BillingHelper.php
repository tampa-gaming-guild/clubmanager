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
     * Add a number of calendar months/years to a date and subtract one day, giving the
     * last day of an N-month/year membership period that starts on $startDate (e.g. a
     * 1-month join on 2026-06-05 ends 2026-07-04; a 1-month renewal starting the day
     * after a 2026-07-04 expiry ends 2026-08-04).
     *
     * If $startDate is itself the last calendar day of its month (e.g. Jan 31, or Feb 29
     * in a leap year), the period instead anchors to the last day of the target month
     * (e.g. Jan 31 -> Feb 28) rather than clamping to a fixed day-of-month. Without this,
     * a single short month (February) would permanently lock the renewal day onto a
     * smaller number forever after (Jan 31 -> Feb 27/28 -> Mar 27/28 -> ... even though
     * March has 31 days). Anchoring to month-end instead self-heals on the very next
     * period, since "day after the last day of a month" is always the 1st of the next
     * month, which never needs clamping again: Jan 31 -> Feb 28 -> Mar 31 -> Apr 30 -> ...
     * @param string $startDate 'Y-m-d'
     * @param int $interval
     * @param string $unit 'month' or 'year'
     * @return string 'Y-m-d'
     */
    private static function addPeriodMinusOneDay(string $startDate, int $interval, string $unit): string {
        $months = (strtolower($unit) === 'year') ? $interval * 12 : $interval;

        $dt = new \DateTime($startDate);
        $day = (int)$dt->format('j');
        $isLastDayOfStartMonth = ($day === (int)$dt->format('t'));

        $dt->setDate((int)$dt->format('Y'), (int)$dt->format('n'), 1);
        $dt->modify("+{$months} month");

        $daysInTargetMonth = (int)$dt->format('t');

        if ($isLastDayOfStartMonth) {
            $dt->setDate((int)$dt->format('Y'), (int)$dt->format('n'), $daysInTargetMonth);
            return $dt->format('Y-m-d');
        }

        $dt->setDate((int)$dt->format('Y'), (int)$dt->format('n'), min($day, $daysInTargetMonth));
        $dt->modify('-1 day');

        return $dt->format('Y-m-d');
    }

    /**
     * Get local subscription plans
     * @param bool $onlyActive If true, only retrieves active plans
     * @return array
     */
    public static function getSubscriptionPlans(bool $onlyActive = false): array {
        try {
            $db = Database::getAppConnection();
            $sql = "SELECT *, price as minimum_fee FROM tgg_subscription_plans";
            if ($onlyActive) {
                $sql .= " WHERE active = 'active'";
            }
            $sql .= " ORDER BY price ASC";
            $stmt = $db->query($sql);
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
                $today = date('Y-m-d');
                $isActiveStatus = strtolower($row['status']) === 'active';
                // Positive once end_date is in the past; negative/zero while still within the paid period.
                $daysSinceExpiry = (strtotime($today) - strtotime($row['end_date'])) / 86400;

                // Members remain in good standing (is_active) through a 30-day grace window past expiry,
                // matching the 'Grace Period' entry in tgg_membership_statuses.
                $row['is_active'] = ($isActiveStatus && $daysSinceExpiry <= 30) ? 1 : 0;

                if (!$row['is_active']) {
                    $row['status_label'] = 'Expired';
                } elseif ($daysSinceExpiry > 0) {
                    $row['status_label'] = 'Grace Period';
                } else {
                    // Members read as "New" for their first 30 days after joining, then "Current".
                    $daysSinceJoin = (strtotime($today) - strtotime($row['join_date'])) / 86400;
                    $row['status_label'] = ($daysSinceJoin < 30) ? 'New' : 'Current';
                }
            }
            
            return $row ?: null;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Process a successful Stripe checkout session idempotently
     * Logs to local transaction ledger, updates local subscription status.
     * @param array $session Stripe Checkout Session array
     * @return bool True if processed successfully
     * @throws Exception
     */
    public static function processCheckoutSession(array $session): bool {
        $sessionId = $session['id'] ?? '';
        if (empty($sessionId)) {
            throw new Exception("Invalid checkout session payload: missing ID.");
        }

        if (($session['payment_status'] ?? '') !== 'paid') {
            throw new Exception("Payment not yet confirmed (status: " . ($session['payment_status'] ?? 'none') . ")");
        }

        $appDb = Database::getAppConnection();

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

        // 2. Start Transaction
        $appDb->beginTransaction();

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
            $existingEndDate = null;

            if ($existingSub) {
                $existingEndDate = $existingSub['end_date'];
            }

            if ($existingEndDate && $action === 'renew') {
                // Extend from the day after the old expiry if the membership is still active,
                // or lapsed by 30 days or less (a grace window). Beyond 30 days lapsed, start a
                // brand-new period from today instead of stacking the term on a stale expiry.
                // join_date is never touched by the UPDATE below, so this never resets how long
                // someone has been a member -- it only affects this period's start/end dates.
                $daysSinceExpiry = (strtotime($today) - strtotime($existingEndDate)) / 86400;
                if ($daysSinceExpiry <= 30) {
                    $startDate = date('Y-m-d', strtotime($existingEndDate . ' +1 day'));
                }
            }

            if ($durationUnit === 'day') {
                // Daily payment should never change the expiration date
                $endDate = $existingEndDate ? $existingEndDate : $today;
            } else {
                $unitString = $durationUnit;
                if (!in_array($unitString, ['month', 'year'])) {
                    $unitString = 'year';
                }
                $endDate = self::addPeriodMinusOneDay($startDate, $durationInterval, $unitString);
            }

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

            $appDb->commit();

            // Send confirmation email
            try {
                $contactQuery = $appDb->prepare("
                    SELECT display_name, email 
                    FROM tgg_contacts 
                    WHERE id = :contact_id LIMIT 1
                ");
                $contactQuery->execute(['contact_id' => $contactId]);
                $contact = $contactQuery->fetch(PDO::FETCH_ASSOC);

                if ($contact && !empty($contact['email'])) {
                    $loginUrl = rtrim($_ENV['BASE_URL'] ?? 'http://localhost/member', '/') . '/index.php';
                    $placeholders = [
                        'display_name' => $contact['display_name'] ?? 'Member',
                        'tier_name' => $plan['name'] ?? 'Membership Tier',
                        'amount' => number_format($amountTotal, 2),
                        'start_date' => $startDate,
                        'end_date' => $endDate,
                        'login_url' => $loginUrl
                    ];
                    MailHelper::sendTemplate($contact['email'], 'payment_received', $placeholders, $contactId, null);

                    // New (non-Trial) members don't set a password at signup, so once their
                    // first payment clears, send a welcome email with a link they can use to
                    // set one up if they ever want portal access -- it's optional, not required.
                    if ($action === 'join') {
                        $rawToken = Auth::createPasswordSetupToken($contact['email'], '+7 days');
                        $setPasswordLink = rtrim($_ENV['BASE_URL'] ?? 'http://localhost/member', '/') . '/reset-password.php?token=' . $rawToken;
                        MailHelper::sendTemplate($contact['email'], 'signup', [
                            'display_name' => $contact['display_name'] ?? 'Member',
                            'tier_name' => $plan['name'] ?? 'Membership Tier',
                            'start_date' => $startDate,
                            'end_date' => $endDate,
                            'set_password_link' => $setPasswordLink
                        ], $contactId, null);
                    }
                }
            } catch (Exception $mailEx) {
                // Log mail exception but do not interrupt the checkout flow
                error_log("Failed to send activation email: " . $mailEx->getMessage());
            }

            return true;

        } catch (Exception $e) {
            if ($appDb->inTransaction()) {
                $appDb->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Check whether a plan is the one-time, non-renewable Trial membership.
     * @param array $plan Plan row (must include 'name')
     * @return bool
     */
    public static function isTrialPlan(array $plan): bool {
        return strcasecmp(trim($plan['name'] ?? ''), 'Trial') === 0;
    }

    /**
     * Check whether an email address has already used (or has a pending) Trial membership.
     * Checked across all contacts with this email, including soft-deleted ones, since the
     * Trial is limited to one per person ever, not just once per active account.
     * @param string $email
     * @return bool
     */
    public static function hasUsedOrPendingTrial(string $email): bool {
        $appDb = Database::getAppConnection();
        $email = strtolower(trim($email));

        $stmt = $appDb->prepare("
            SELECT 1 FROM tgg_billing_ledger bl
            INNER JOIN tgg_subscription_plans p ON p.id = bl.plan_id
            INNER JOIN tgg_contacts c ON c.id = bl.contact_id
            WHERE LOWER(p.name) = 'trial' AND LOWER(c.email) = :email
            LIMIT 1
        ");
        $stmt->execute(['email' => $email]);
        if ($stmt->fetch()) {
            return true;
        }

        $stmt = $appDb->prepare("
            SELECT 1 FROM tgg_trial_verifications v
            INNER JOIN tgg_contacts c ON c.id = v.contact_id
            WHERE LOWER(c.email) = :email AND v.expires_at >= NOW()
            LIMIT 1
        ");
        $stmt->execute(['email' => $email]);
        return (bool)$stmt->fetch();
    }

    /**
     * Activate a verified Trial membership: creates the local subscription and logs
     * a $0.00 ledger entry (the ledger entry is what makes the trial show up as "used"
     * for the lifetime one-trial-per-email check).
     * @param int $contactId
     * @param int $planId
     * @return array Activation details (start_date, end_date, plan)
     * @throws Exception
     */
    public static function activateTrial(int $contactId, int $planId): array {
        $appDb = Database::getAppConnection();

        $planStmt = $appDb->prepare("SELECT * FROM tgg_subscription_plans WHERE id = :id LIMIT 1");
        $planStmt->execute(['id' => $planId]);
        $plan = $planStmt->fetch(PDO::FETCH_ASSOC);
        if (!$plan || !self::isTrialPlan($plan)) {
            throw new Exception("Trial plan not found.");
        }

        $intervalDays = (int)$plan['duration_interval'];
        $today = date('Y-m-d');
        $endDate = date('Y-m-d', strtotime($today . " +{$intervalDays} days"));

        $appDb->beginTransaction();

        try {
            $uniqueId = uniqid('trial_', true);
            $insertLedger = $appDb->prepare("
                INSERT INTO tgg_billing_ledger (contact_id, plan_id, stripe_session_id, payment_intent_id, amount, currency, payment_status, action_type)
                VALUES (:contact_id, :plan_id, :stripe_session_id, :payment_intent_id, 0.00, 'usd', 'paid', 'join')
            ");
            $insertLedger->execute([
                'contact_id' => $contactId,
                'plan_id' => $planId,
                'stripe_session_id' => $uniqueId,
                'payment_intent_id' => $uniqueId
            ]);

            $insertSub = $appDb->prepare("
                INSERT INTO tgg_subscriptions (contact_id, plan_id, status, join_date, start_date, end_date)
                VALUES (:contact_id, :plan_id, 'active', :join_date, :start_date, :end_date)
                ON DUPLICATE KEY UPDATE plan_id = VALUES(plan_id), status = 'active', join_date = VALUES(join_date), start_date = VALUES(start_date), end_date = VALUES(end_date)
            ");
            $insertSub->execute([
                'contact_id' => $contactId,
                'plan_id' => $planId,
                'join_date' => $today,
                'start_date' => $today,
                'end_date' => $endDate
            ]);

            $appDb->commit();

            return ['start_date' => $today, 'end_date' => $endDate, 'plan' => $plan];
        } catch (Exception $e) {
            if ($appDb->inTransaction()) {
                $appDb->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Add or update a membership plan locally
     * @param array $data Plan attributes
     * @return bool
     * @throws Exception
     */
    public static function savePlan(array $data): bool {
        $appDb = Database::getAppConnection();

        $id = isset($data['id']) ? (int)$data['id'] : null;
        $name = trim($data['name'] ?? '');
        $description = trim($data['description'] ?? '');
        $price = (float)($data['price'] ?? 0);
        $durationUnit = strtolower($data['duration_unit'] ?? 'year');
        $durationInterval = (int)($data['duration_interval'] ?? 1);
        $active = trim($data['active'] ?? 'active');
        if (!in_array($active, ['active', 'inactive'])) {
            $active = 'active';
        }

        if (empty($name)) {
            throw new Exception("Plan name cannot be empty.");
        }
        if ($price < 0) {
            throw new Exception("Price cannot be negative.");
        }
        if ($durationInterval <= 0) {
            throw new Exception("Duration interval must be greater than zero.");
        }
        if (!in_array($durationUnit, ['day', 'month', 'year'])) {
            throw new Exception("Invalid duration unit. Allowed units are 'day', 'month', or 'year'.");
        }

        $appDb->beginTransaction();

        try {
            if ($id) {
                // Update existing plan
                $updateLocal = $appDb->prepare("
                    UPDATE tgg_subscription_plans 
                    SET name = :name, description = :description, price = :price, duration_unit = :duration_unit, duration_interval = :duration_interval, active = :active 
                    WHERE id = :id
                ");
                $updateLocal->execute([
                    'name' => $name,
                    'description' => $description,
                    'price' => $price,
                    'duration_unit' => $durationUnit,
                    'duration_interval' => $durationInterval,
                    'active' => $active,
                    'id' => $id
                ]);

            } else {
                // Insert new plan
                // Generate next civi membership type id locally to maintain schema compatibility
                $maxCiviId = (int)$appDb->query("SELECT MAX(civicrm_membership_type_id) FROM tgg_subscription_plans")->fetchColumn();
                $civiTypeId = $maxCiviId + 1;

                $insertLocal = $appDb->prepare("
                    INSERT INTO tgg_subscription_plans (name, description, price, duration_unit, duration_interval, civicrm_membership_type_id, active) 
                    VALUES (:name, :description, :price, :duration_unit, :duration_interval, :civicrm_membership_type_id, :active)
                ");
                $insertLocal->execute([
                    'name' => $name,
                    'description' => $description,
                    'price' => $price,
                    'duration_unit' => $durationUnit,
                    'duration_interval' => $durationInterval,
                    'civicrm_membership_type_id' => $civiTypeId,
                    'active' => $active
                ]);
            }

            $appDb->commit();
            return true;

        } catch (Exception $e) {
            if ($appDb->inTransaction()) {
                $appDb->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Process an offline/manual membership renewal or extension
     * @param int $contactId
     * @param int $planId
     * @param string $paymentMethod 'cash', 'check', 'complimentary', 'volunteer credit'
     * @param string $action 'join' or 'renew'
     * @param string $levelChangeMode 'extend_current' or 'change_level'
     * @return bool True if successful
     * @throws Exception
     */
    public static function processOfflineRenewal(
        int $contactId,
        int $planId,
        string $paymentMethod,
        string $action = 'renew',
        string $levelChangeMode = 'extend_current',
        string $durationMode = 'standard',
        string $customDate = null,
        float $customAmount = null
    ): bool {
        $appDb = Database::getAppConnection();

        // Validate payment method
        $validMethods = ['cash', 'check', 'complimentary', 'volunteer credit'];
        if (!in_array(strtolower($paymentMethod), $validMethods)) {
            throw new Exception("Invalid offline payment method: " . htmlspecialchars($paymentMethod));
        }

        // Get local plan details
        $planStmt = $appDb->prepare("SELECT * FROM tgg_subscription_plans WHERE id = :id LIMIT 1");
        $planStmt->execute(['id' => $planId]);
        $plan = $planStmt->fetch(PDO::FETCH_ASSOC);
        if (!$plan) {
            throw new Exception("Local plan ID {$planId} not found.");
        }

        // Generate custom identifiers
        $paymentMethodLabel = ucwords($paymentMethod);
        $uniqueId = uniqid('offline_', true);
        $paymentIntentId = 'offline_' . str_replace(' ', '_', strtolower($paymentMethod)) . '_' . time();
        $amountTotal = ($customAmount !== null) ? $customAmount : (($durationMode === 'standard') ? (float)$plan['price'] : 0.00);
        $currency = 'usd';

        $appDb->beginTransaction();

        try {
            // A. Log transaction locally in tgg_billing_ledger
            $insertLedger = $appDb->prepare("
                INSERT INTO tgg_billing_ledger (contact_id, plan_id, stripe_session_id, payment_intent_id, amount, currency, payment_status, action_type)
                VALUES (:contact_id, :plan_id, :stripe_session_id, :payment_intent_id, :amount, :currency, 'paid', :action_type)
            ");
            $insertLedger->execute([
                'contact_id' => $contactId,
                'plan_id' => $planId,
                'stripe_session_id' => $uniqueId,
                'payment_intent_id' => $paymentIntentId,
                'amount' => $amountTotal,
                'currency' => $currency,
                'action_type' => $action
            ]);

            // Query existing local subscription
            $subStmt = $appDb->prepare("SELECT plan_id, join_date, end_date FROM tgg_subscriptions WHERE contact_id = :contact_id LIMIT 1");
            $subStmt->execute(['contact_id' => $contactId]);
            $existingSub = $subStmt->fetch(PDO::FETCH_ASSOC);

            // Resolve final plan ID based on levelChangeMode
            $finalPlanId = $planId;

            $isCustomDuration = in_array($durationMode, ['1_month', '1_year', 'custom_date']);
            $resolvedChangeMode = $isCustomDuration ? 'extend_current' : $levelChangeMode;

            if ($resolvedChangeMode === 'extend_current') {
                if ($existingSub) {
                    $finalPlanId = (int)$existingSub['plan_id'];
                }
            }

            $today = date('Y-m-d');
            $startDate = $today;

            if ($existingSub && $action === 'renew') {
                $existingEndDate = $existingSub['end_date'];
                // Extend from the day after the old expiry if still active, or lapsed by 30 days or
                // less (grace period). Beyond that, start a fresh period from today instead. join_date
                // is never touched here (no UPDATE below includes it), so this never resets how long
                // someone has been a member.
                $daysSinceExpiry = (strtotime($today) - strtotime($existingEndDate)) / 86400;
                if ($daysSinceExpiry <= 30) {
                    $startDate = date('Y-m-d', strtotime($existingEndDate . ' +1 day'));
                }
            }

            if ($durationMode === '1_month') {
                $endDate = self::addPeriodMinusOneDay($startDate, 1, 'month');
            } elseif ($durationMode === '1_year') {
                $endDate = self::addPeriodMinusOneDay($startDate, 1, 'year');
            } elseif ($durationMode === 'custom_date') {
                $endDate = date('Y-m-d', strtotime($customDate));
            } else {
                $durationInterval = (int)$plan['duration_interval'];
                $durationUnit = strtolower($plan['duration_unit']);
                if ($durationUnit === 'day') {
                    // Daily payment should never change the expiration date
                    $existingEndDate = null;
                    if ($existingSub) {
                        $existingEndDate = $existingSub['end_date'];
                    }
                    $endDate = $existingEndDate ? $existingEndDate : $today;
                } else {
                    $unitString = $durationUnit;
                    if (!in_array($unitString, ['month', 'year'])) {
                        $unitString = 'year';
                    }
                    $endDate = self::addPeriodMinusOneDay($startDate, $durationInterval, $unitString);
                }
            }

            // C. Insert or update local subscription
            if ($existingSub) {
                $updateSub = $appDb->prepare("
                    UPDATE tgg_subscriptions 
                    SET plan_id = :plan_id, status = 'active', start_date = :start_date, end_date = :end_date 
                    WHERE contact_id = :contact_id
                ");
                $updateSub->execute([
                    'plan_id' => $finalPlanId,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'contact_id' => $contactId
                ]);
            } else {
                $joinDate = $today;
                $insertSub = $appDb->prepare("
                    INSERT INTO tgg_subscriptions (contact_id, plan_id, status, join_date, start_date, end_date)
                    VALUES (:contact_id, :plan_id, 'active', :join_date, :start_date, :end_date)
                ");
                $insertSub->execute([
                    'contact_id' => $contactId,
                    'plan_id' => $finalPlanId,
                    'join_date' => $joinDate,
                    'start_date' => $startDate,
                    'end_date' => $endDate
                ]);
            }

            // Commit transaction
            $appDb->commit();

            // Send confirmation email for offline renewal
            try {
                $contactQuery = $appDb->prepare("
                    SELECT display_name, email 
                    FROM tgg_contacts 
                    WHERE id = :contact_id LIMIT 1
                ");
                $contactQuery->execute(['contact_id' => $contactId]);
                $contact = $contactQuery->fetch(PDO::FETCH_ASSOC);

                if ($contact && !empty($contact['email'])) {
                    $loginUrl = rtrim($_ENV['BASE_URL'] ?? 'http://localhost/member', '/') . '/index.php';
                    $placeholders = [
                        'display_name' => $contact['display_name'] ?? 'Member',
                        'tier_name' => $plan['name'] ?? 'Membership Tier',
                        'amount' => number_format($amountTotal, 2),
                        'start_date' => $startDate,
                        'end_date' => $endDate,
                        'login_url' => $loginUrl
                    ];
                    
                    $senderId = $_SESSION['user']['contact_id'] ?? null;
                    MailHelper::sendTemplate($contact['email'], 'payment_received', $placeholders, $contactId, $senderId);
                }
            } catch (Exception $mailEx) {
                // Log mail exception but do not interrupt the renewal success
                error_log("Failed to send offline renewal receipt email: " . $mailEx->getMessage());
            }

            return true;

        } catch (Exception $e) {
            if ($appDb->inTransaction()) {
                $appDb->rollBack();
            }
            throw $e;
        }
    }
}
