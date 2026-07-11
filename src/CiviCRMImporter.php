<?php
namespace App;

use Exception;

/**
 * CiviCRM Data Importer
 * One-time synchronization of contacts and memberships from CiviCRM tables into the
 * local app database. Once the club has gone live and the local tables (tgg_*) are the
 * source of truth, this class -- along with admin/import.php and the CIVI_DB_* env vars
 * / Database::getCiviConnection() -- is not needed for normal operation and can be
 * safely deleted. For day-to-day membership lookups, see MembershipService instead.
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
            'founders_flagged' => 0,
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

                // Ensure a default rate exists for this plan in tgg_subscription_rates
                $planId = $appDb->query("SELECT id FROM tgg_subscription_plans WHERE civicrm_membership_type_id = " . (int)$type['id'])->fetchColumn();
                if ($planId) {
                    $rateCheck = $appDb->prepare("SELECT id FROM tgg_subscription_rates WHERE plan_id = :plan_id LIMIT 1");
                    $rateCheck->execute(['plan_id' => $planId]);
                    if (!$rateCheck->fetch()) {
                        $freq = 'monthly';
                        if ($type['duration_unit'] === 'year') {
                            $freq = 'annual';
                        } elseif ($type['duration_unit'] === 'day') {
                            $freq = 'daily';
                        }
                        $insertRate = $appDb->prepare("
                            INSERT INTO tgg_subscription_rates (plan_id, name, price, billing_frequency, inactive)
                            VALUES (:plan_id, :name, :price, :billing_frequency, 0)
                        ");
                        $insertRate->execute([
                            'plan_id' => $planId,
                            'name' => $type['name'] . ' - Standard',
                            'price' => $type['minimum_fee'],
                            'billing_frequency' => $freq
                        ]);
                    }
                }
            }
        } catch (Exception $e) {
            $stats['errors'][] = "Failed syncing membership types: " . $e->getMessage();
        }

        // 1. Fetch all contacts from CiviCRM (including deleted status and names)
        $query = "SELECT c.id, c.display_name, c.first_name, c.last_name, e.email, p.phone, c.is_deleted, c.is_opt_out
                  FROM civicrm_contact c
                  INNER JOIN civicrm_email e ON e.contact_id = c.id AND e.is_primary = 1
                  LEFT JOIN civicrm_phone p ON p.contact_id = c.id AND p.is_primary = 1";
        
        $stmt = $civiDb->prepare($query);
        $stmt->execute();
        $contacts = $stmt->fetchAll();

        $stats['contacts_scanned'] = count($contacts);

        // Prepare statements for local contacts insertion/update
        $insertContactStmt = $appDb->prepare("
            INSERT INTO tgg_contacts (id, display_name, first_name, last_name, email, phone, is_deleted, is_opt_out)
            VALUES (:id, :display_name, :first_name, :last_name, :email, :phone, :is_deleted, :is_opt_out)
            ON DUPLICATE KEY UPDATE
                display_name = VALUES(display_name),
                first_name = VALUES(first_name),
                last_name = VALUES(last_name),
                email = VALUES(email),
                phone = VALUES(phone),
                is_deleted = VALUES(is_deleted),
                is_opt_out = VALUES(is_opt_out)
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
                    'phone' => normalize_phone((string)($contact['phone'] ?? '')) ?: null,
                    'is_deleted' => (int)$contact['is_deleted'],
                    'is_opt_out' => (int)$contact['is_opt_out']
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

                    $joinDate  = $civiMem['join_date']  ?? $civiMem['start_date'] ?? $civiMem['end_date'];
                    $startDate = $civiMem['start_date'] ?? $joinDate;
                    $endDate   = $civiMem['end_date'];

                    if (!$joinDate || !$startDate || !$endDate) {
                        $stats['errors'][] = "Skipped subscription sync for contact #{$contactId}: membership record has null date fields (join={$civiMem['join_date']}, start={$civiMem['start_date']}, end={$civiMem['end_date']})";
                    } else {
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
                                    SET plan_id = :plan_id, status = :status, join_date = :join_date, start_date = :start_date, end_date = :end_date,
                                        rate_id = COALESCE(rate_id, (SELECT id FROM tgg_subscription_rates WHERE plan_id = {$planId} LIMIT 1))
                                    WHERE contact_id = :contact_id
                                ");
                                $subUpdate->execute([
                                    'plan_id'    => $planId,
                                    'status'     => $status,
                                    'join_date'  => $joinDate,
                                    'start_date' => $startDate,
                                    'end_date'   => $endDate,
                                    'contact_id' => $contactId
                                ]);
                            } else {
                                $subInsert = $appDb->prepare("
                                    INSERT INTO tgg_subscriptions (contact_id, plan_id, status, join_date, start_date, end_date, rate_id)
                                    VALUES (:contact_id, :plan_id, :status, :join_date, :start_date, :end_date, (SELECT id FROM tgg_subscription_rates WHERE plan_id = {$planId} LIMIT 1))
                                ");
                                $subInsert->execute([
                                    'contact_id' => $contactId,
                                    'plan_id'    => $planId,
                                    'status'     => $status,
                                    'join_date'  => $joinDate,
                                    'start_date' => $startDate,
                                    'end_date'   => $endDate
                                ]);
                            }
                        }
                    }
                }

                // D. Check for an active Founder membership and permanently flag the member.
                // Once set, the flag is never cleared — it is a badge of honour.
                try {
                    $founderQuery = $civiDb->prepare("
                        SELECT COUNT(*) FROM civicrm_membership m
                        INNER JOIN civicrm_membership_type mt ON m.membership_type_id = mt.id
                        INNER JOIN civicrm_membership_status s ON m.status_id = s.id
                        WHERE m.contact_id = :contact_id
                          AND mt.name = 'Founder'
                          AND s.is_active = 1
                    ");
                    $founderQuery->execute(['contact_id' => $contactId]);
                    if ((int)$founderQuery->fetchColumn() > 0) {
                        $appDb->prepare("UPDATE tgg_member_settings SET is_founder = 1 WHERE contact_id = :contact_id AND is_founder = 0")
                              ->execute(['contact_id' => $contactId]);
                        if ($appDb->query("SELECT ROW_COUNT()")->fetchColumn() > 0) {
                            $stats['founders_flagged']++;
                        }
                    }
                } catch (Exception $e) {
                    $stats['errors'][] = "Failed checking founder status for contact #{$contactId}: " . $e->getMessage();
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
            $fallbackPlanId = null;
            foreach ($plansRaw as $p) {
                $plansByPrice[(int)round($p['price'])] = (int)$p['id'];
                $fallbackPlanId = $fallbackPlanId ?? (int)$p['id'];
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
                try {
                    $cid = (int)$contrib['contact_id'];
                    $amount = (float)$contrib['total_amount'];
                    $trxnId = $contrib['trxn_id'];
                    $civiId = (int)$contrib['id'];

                    // Map contribution amount to closest local plan ID
                    $amountRounded = (int)round($amount);
                    $planId = $plansByPrice[$amountRounded] ?? $fallbackPlanId;

                    if (!$planId) {
                        $stats['errors'][] = "Skipped contribution #{$civiId}: no local subscription plans exist";
                        continue;
                    }

                    // Deduce join vs renew action based on order
                    if (!isset($contactContribsCount[$cid])) {
                        $contactContribsCount[$cid] = 0;
                    }
                    $actionType = ($contactContribsCount[$cid] > 0) ? 'renew' : 'join';
                    $contactContribsCount[$cid]++;

                    $stripeSessionId = "civi_contrib_" . $civiId;
                    $paymentIntentId = !empty($trxnId) ? $trxnId : "civi_contrib_" . $civiId;

                    $insertLedger->execute([
                        'contact_id'       => $cid,
                        'plan_id'          => $planId,
                        'stripe_session_id' => $stripeSessionId,
                        'payment_intent_id' => $paymentIntentId,
                        'amount'           => $amount,
                        'action_type'      => $actionType,
                        'created_at'       => $contrib['receive_date']
                    ]);

                    $stats['contributions_synced']++;
                } catch (Exception $e) {
                    $stats['errors'][] = "Failed syncing contribution #{$contrib['id']} (contact #{$contrib['contact_id']}): " . $e->getMessage();
                }
            }
        } catch (Exception $e) {
            $stats['errors'][] = "Failed syncing contributions: " . $e->getMessage();
        }

        return $stats;
    }
}
