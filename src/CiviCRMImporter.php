<?php
namespace App;

use Exception;
use PDO;

/**
 * CiviCRM Data Importer
 * Synchronizes contacts and memberships from CiviCRM tables to local app database.
 */
class CiviCRMImporter {

    /**
     * Test connection to both databases
     * @return array Array with status information
     */
    public static function testConnections(): array {
        $status = ['app' => false, 'civi' => false, 'errors' => []];
        try {
            $appDb = Database::getAppConnection();
            $status['app'] = true;
        } catch (Exception $e) {
            $status['errors'][] = "App DB Error: " . $e->getMessage();
        }

        try {
            $civiDb = Database::getCiviConnection();
            $status['civi'] = true;
        } catch (Exception $e) {
            $status['errors'][] = "CiviCRM DB Error: " . $e->getMessage();
        }

        return $status;
    }

    /**
     * Run full import/sync process from CiviCRM
     * @return array Import statistics
     * @throws Exception
     */
    public static function runSync(): array {
        $appDb = Database::getAppConnection();
        $civiDb = Database::getCiviConnection();

        $stats = [
            'plans_synced' => 0,
            'contacts_scanned' => 0,
            'settings_created' => 0,
            'settings_updated' => 0,
            'contributions_synced' => 0,
            'errors' => []
        ];

        // 0. Sync CiviCRM membership types to local subscription plans first,
        // since contacts' memberships below are matched against these plans.
        try {
            $membershipTypes = $civiDb->query("SELECT id, name, description, minimum_fee, duration_unit, duration_interval FROM civicrm_membership_type")->fetchAll();

            $upsertPlanStmt = $appDb->prepare("
                INSERT INTO tgg_subscription_plans (name, description, price, duration_unit, duration_interval, civicrm_membership_type_id, active)
                VALUES (:name, :description, :price, :duration_unit, :duration_interval, :civicrm_membership_type_id, 'active')
                ON DUPLICATE KEY UPDATE
                    name = VALUES(name),
                    description = VALUES(description),
                    price = VALUES(price),
                    duration_unit = VALUES(duration_unit),
                    duration_interval = VALUES(duration_interval)
            ");

            foreach ($membershipTypes as $type) {
                $upsertPlanStmt->execute([
                    'name' => $type['name'],
                    'description' => $type['description'],
                    'price' => $type['minimum_fee'],
                    'duration_unit' => $type['duration_unit'],
                    'duration_interval' => $type['duration_interval'],
                    'civicrm_membership_type_id' => (int)$type['id']
                ]);
                $stats['plans_synced']++;
            }
        } catch (Exception $e) {
            $stats['errors'][] = "Failed syncing membership types: " . $e->getMessage();
        }

        // 1. Fetch all contacts from CiviCRM (including deleted status and names)
        $query = "SELECT c.id, c.display_name, c.first_name, c.last_name, e.email, p.phone, c.is_deleted
                  FROM civicrm_contact c
                  INNER JOIN civicrm_email e ON e.contact_id = c.id AND e.is_primary = 1
                  LEFT JOIN civicrm_phone p ON p.contact_id = c.id AND p.is_primary = 1";
        
        $stmt = $civiDb->prepare($query);
        $stmt->execute();
        $contacts = $stmt->fetchAll();

        $stats['contacts_scanned'] = count($contacts);

        // Prepare statements for local contacts insertion/update
        $insertContactStmt = $appDb->prepare("
            INSERT INTO tgg_contacts (id, display_name, first_name, last_name, email, phone, is_deleted)
            VALUES (:id, :display_name, :first_name, :last_name, :email, :phone, :is_deleted)
            ON DUPLICATE KEY UPDATE
                display_name = VALUES(display_name),
                first_name = VALUES(first_name),
                last_name = VALUES(last_name),
                email = VALUES(email),
                phone = VALUES(phone),
                is_deleted = VALUES(is_deleted)
        ");

        // Prepare statements for local checking and insertion
        $checkStmt = $appDb->prepare("SELECT contact_id, role FROM tgg_member_settings WHERE contact_id = :contact_id LIMIT 1");
        $insertStmt = $appDb->prepare("INSERT INTO tgg_member_settings (contact_id, password_hash, role, is_profile_public, public_fields) 
                                       VALUES (:contact_id, :password_hash, :role, 1, :public_fields)");

        // Users must use the forgot password flow to reset their password on first login
        $defaultPublicFields = json_encode(['display_name', 'membership_type', 'membership_status']);

        foreach ($contacts as $contact) {
            $contactId = (int)$contact['id'];
            
            try {
                // A. Sync Contact table details locally
                $insertContactStmt->execute([
                    'id' => $contactId,
                    'display_name' => $contact['display_name'],
                    'first_name' => $contact['first_name'],
                    'last_name' => $contact['last_name'],
                    'email' => strtolower(trim($contact['email'])),
                    'phone' => $contact['phone'],
                    'is_deleted' => (int)$contact['is_deleted']
                ]);

                // B. Sync Credentials Settings record locally
                $checkStmt->execute(['contact_id' => $contactId]);
                $localUser = $checkStmt->fetch();

                if (!$localUser) {
                    // Create local credentials record
                    $role = 'member';

                    // Generate secure random token password
                    $randomPassword = bin2hex(random_bytes(32));
                    $securePasswordHash = password_hash($randomPassword, PASSWORD_DEFAULT);

                    $insertStmt->execute([
                        'contact_id' => $contactId,
                        'password_hash' => $securePasswordHash,
                        'role' => $role,
                        'public_fields' => $defaultPublicFields
                    ]);
                    $stats['settings_created']++;
                } else {
                    $stats['settings_updated']++;
                }

                // C. Sync CiviCRM membership details to local tgg_subscriptions
                $memQuery = $civiDb->prepare("
                    SELECT membership_type_id, join_date, start_date, end_date, s.is_active
                    FROM civicrm_membership m
                    INNER JOIN civicrm_membership_status s ON m.status_id = s.id
                    WHERE m.contact_id = :contact_id
                    ORDER BY m.end_date DESC
                    LIMIT 1
                ");
                $memQuery->execute(['contact_id' => $contactId]);
                $civiMem = $memQuery->fetch();

                if ($civiMem) {
                    $civiMemTypeId = (int)$civiMem['membership_type_id'];
                    $status = $civiMem['is_active'] ? 'active' : 'expired';
                    
                    // Find matching local plan
                    $planQuery = $appDb->prepare("SELECT id FROM tgg_subscription_plans WHERE civicrm_membership_type_id = :civi_type LIMIT 1");
                    $planQuery->execute(['civi_type' => $civiMemTypeId]);
                    $localPlan = $planQuery->fetch();
                    
                    if ($localPlan) {
                        $planId = (int)$localPlan['id'];
                        
                        // Insert or Update local subscription
                        $subCheck = $appDb->prepare("SELECT contact_id FROM tgg_subscriptions WHERE contact_id = :contact_id LIMIT 1");
                        $subCheck->execute(['contact_id' => $contactId]);
                        
                        if ($subCheck->fetch()) {
                            $subUpdate = $appDb->prepare("
                                UPDATE tgg_subscriptions 
                                SET plan_id = :plan_id, status = :status, join_date = :join_date, start_date = :start_date, end_date = :end_date 
                                WHERE contact_id = :contact_id
                            ");
                            $subUpdate->execute([
                                'plan_id' => $planId,
                                'status' => $status,
                                'join_date' => $civiMem['join_date'],
                                'start_date' => $civiMem['start_date'],
                                'end_date' => $civiMem['end_date'],
                                'contact_id' => $contactId
                            ]);
                        } else {
                            $subInsert = $appDb->prepare("
                                INSERT INTO tgg_subscriptions (contact_id, plan_id, status, join_date, start_date, end_date) 
                                VALUES (:contact_id, :plan_id, :status, :join_date, :start_date, :end_date)
                            ");
                            $subInsert->execute([
                                'contact_id' => $contactId,
                                'plan_id' => $planId,
                                'status' => $status,
                                'join_date' => $civiMem['join_date'],
                                'start_date' => $civiMem['start_date'],
                                'end_date' => $civiMem['end_date']
                            ]);
                        }
                    }
                }
            } catch (Exception $e) {
                $stats['errors'][] = "Failed syncing contact #{$contactId}: " . $e->getMessage();
            }
        }

        // 2. Sync CiviCRM Contributions (Payments) to local tgg_billing_ledger
        try {
            // Cache local plans by price
            $plansRaw = $appDb->query("SELECT id, price FROM tgg_subscription_plans")->fetchAll();
            $plansByPrice = [];
            foreach ($plansRaw as $p) {
                $plansByPrice[(int)round($p['price'])] = (int)$p['id'];
            }

            // Fetch completed contributions from CiviCRM
            $contribQuery = $civiDb->prepare("
                SELECT id, contact_id, receive_date, total_amount, trxn_id
                FROM civicrm_contribution
                WHERE contribution_status_id = 1
                ORDER BY receive_date ASC, id ASC
            ");
            $contribQuery->execute();
            $contributions = $contribQuery->fetchAll();

            $insertLedger = $appDb->prepare("
                INSERT INTO tgg_billing_ledger (contact_id, plan_id, stripe_session_id, payment_intent_id, amount, currency, payment_status, action_type, created_at)
                VALUES (:contact_id, :plan_id, :stripe_session_id, :payment_intent_id, :amount, 'usd', 'paid', :action_type, :created_at)
                ON DUPLICATE KEY UPDATE
                    amount = VALUES(amount),
                    created_at = VALUES(created_at)
            ");

            $contactContribsCount = [];

            foreach ($contributions as $contrib) {
                $cid = (int)$contrib['contact_id'];
                $amount = (float)$contrib['total_amount'];
                $trxnId = $contrib['trxn_id'];
                $civiId = (int)$contrib['id'];

                // Map contribution amount to closest local plan ID
                $amountRounded = (int)round($amount);
                $planId = $plansByPrice[$amountRounded] ?? 1; // Fallback to plan 1

                // Deduce join vs renew action based on order
                if (!isset($contactContribsCount[$cid])) {
                    $contactContribsCount[$cid] = 0;
                }
                $actionType = ($contactContribsCount[$cid] > 0) ? 'renew' : 'join';
                $contactContribsCount[$cid]++;

                $stripeSessionId = "civi_contrib_" . $civiId;
                $paymentIntentId = !empty($trxnId) ? $trxnId : "civi_contrib_" . $civiId;

                $insertLedger->execute([
                    'contact_id' => $cid,
                    'plan_id' => $planId,
                    'stripe_session_id' => $stripeSessionId,
                    'payment_intent_id' => $paymentIntentId,
                    'amount' => $amount,
                    'action_type' => $actionType,
                    'created_at' => $contrib['receive_date']
                ]);

                $stats['contributions_synced']++;
            }
        } catch (Exception $e) {
            $stats['errors'][] = "Failed syncing contributions: " . $e->getMessage();
        }

        return $stats;
    }

    /**
     * Get active membership tiers from local subscription plans
     * @return array
     * @throws Exception
     */
    public static function getMembershipTiers(): array {
        $appDb = Database::getAppConnection();
        $query = "SELECT civicrm_membership_type_id AS id, name, description, price AS minimum_fee, duration_unit, duration_interval 
                  FROM tgg_subscription_plans 
                  ORDER BY price ASC";
        return $appDb->query($query)->fetchAll();
    }

    /**
     * Get details for a specific contact's membership from local database
     * @param int $contactId
     * @return array|null
     * @throws Exception
     */
    public static function getMemberMembershipDetails(int $contactId): ?array {
        $appDb = Database::getAppConnection();
        $query = "SELECT s.plan_id as membership_id, s.join_date, s.start_date, s.end_date,
                         p.name as membership_name, p.price as minimum_fee,
                         CASE
                             WHEN (s.status = 'active' AND s.end_date >= CURRENT_DATE() AND s.join_date >= DATE_SUB(CURRENT_DATE(), INTERVAL 30 DAY)) THEN 'New'
                             WHEN (s.status = 'active' AND s.end_date >= CURRENT_DATE()) THEN 'Current'
                             WHEN (s.status = 'active' AND s.end_date >= DATE_SUB(CURRENT_DATE(), INTERVAL 30 DAY)) THEN 'Grace Period'
                             ELSE 'Expired'
                         END as status_label,
                         CASE
                             WHEN (s.status = 'active' AND s.end_date >= CURRENT_DATE() AND s.join_date >= DATE_SUB(CURRENT_DATE(), INTERVAL 30 DAY)) THEN 'New'
                             WHEN (s.status = 'active' AND s.end_date >= CURRENT_DATE()) THEN 'Current'
                             WHEN (s.status = 'active' AND s.end_date >= DATE_SUB(CURRENT_DATE(), INTERVAL 30 DAY)) THEN 'Grace Period'
                             ELSE 'Expired'
                         END as status_name,
                         CASE WHEN (s.status = 'active' AND s.end_date >= DATE_SUB(CURRENT_DATE(), INTERVAL 30 DAY)) THEN 1 ELSE 0 END as is_active
                  FROM tgg_subscriptions s
                  INNER JOIN tgg_subscription_plans p ON s.plan_id = p.id
                  WHERE s.contact_id = :contact_id
                  LIMIT 1";
        
        $stmt = $appDb->prepare($query);
        $stmt->execute(['contact_id' => $contactId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Get a list of all members with membership status from local database
     * @return array
     * @throws Exception
     */
    public static function getMembersList(): array {
        $appDb = Database::getAppConnection();
        $query = "SELECT c.id, c.display_name, c.first_name, c.last_name, c.email, c.phone,
                         s.join_date, s.start_date, s.end_date,
                         p.name as membership_name,
                         CASE
                             WHEN (s.status = 'active' AND s.end_date >= CURRENT_DATE() AND s.join_date >= DATE_SUB(CURRENT_DATE(), INTERVAL 30 DAY)) THEN 'New'
                             WHEN (s.status = 'active' AND s.end_date >= CURRENT_DATE()) THEN 'Current'
                             WHEN (s.status = 'active' AND s.end_date >= DATE_SUB(CURRENT_DATE(), INTERVAL 30 DAY)) THEN 'Grace Period'
                             ELSE 'Expired'
                         END as status_label,
                         CASE WHEN (s.status = 'active' AND s.end_date >= DATE_SUB(CURRENT_DATE(), INTERVAL 30 DAY)) THEN 1 ELSE 0 END as is_active
                  FROM tgg_contacts c
                  LEFT JOIN tgg_subscriptions s ON s.contact_id = c.id
                  LEFT JOIN tgg_subscription_plans p ON s.plan_id = p.id
                  WHERE c.is_deleted = 0
                  ORDER BY c.display_name ASC";
        $members = $appDb->query($query)->fetchAll();
        
        if (empty($members)) {
            return [];
        }
        
        // Resolve privacy display names
        $contactIds = array_column($members, 'id');
        $formattedNames = self::getFormattedNames($contactIds);
        
        foreach ($members as &$m) {
            $cid = (int)$m['id'];
            $m['display_name'] = $formattedNames[$cid] ?? $m['display_name'];
        }
        
        // Re-sort members by their privacy-aware display name
        usort($members, function($a, $b) {
            return strcasecmp($a['display_name'] ?? '', $b['display_name'] ?? '');
        });
        
        return $members;
    }

    /**
     * Get display names mapping from local contacts according to privacy preferences
     * @param array $contactIds
     * @return array
     */
    public static function getFormattedNames(array $contactIds): array {
        $contactIds = array_values(array_unique($contactIds));
        if (empty($contactIds)) {
            return [];
        }
        
        $appDb = Database::getAppConnection();
        
        $placeholders = implode(',', array_fill(0, count($contactIds), '?'));
        
        // Fetch local contact names
        $civiStmt = $appDb->prepare("SELECT id, display_name FROM tgg_contacts WHERE id IN ({$placeholders})");
        $civiStmt->execute($contactIds);
        $civiContacts = $civiStmt->fetchAll(PDO::FETCH_UNIQUE);
        
        // Fetch custom display names from local settings
        $settingsStmt = $appDb->prepare("SELECT contact_id, custom_display_name FROM tgg_member_settings WHERE contact_id IN ({$placeholders})");
        $settingsStmt->execute($contactIds);
        $localSettings = $settingsStmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        $namesMap = [];
        foreach ($contactIds as $cid) {
            $cid = (int)$cid;
            $customName = $localSettings[$cid] ?? '';
            if (trim($customName) !== '') {
                $namesMap[$cid] = trim($customName);
            } elseif (isset($civiContacts[$cid])) {
                $namesMap[$cid] = $civiContacts[$cid]['display_name'];
            } else {
                $namesMap[$cid] = "Member #{$cid}";
            }
        }
        
        return $namesMap;
    }

    /**
     * Get display name for a single contact ID according to their privacy preferences.
     * @param int $contactId Contact ID
     * @return string Formatted display name
     */
    public static function getFormattedName(int $contactId): string {
        $map = self::getFormattedNames([$contactId]);
        return $map[$contactId] ?? "Member #{$contactId}";
    }
}
