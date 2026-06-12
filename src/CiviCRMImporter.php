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
            'contacts_scanned' => 0,
            'settings_created' => 0,
            'settings_updated' => 0,
            'errors' => []
        ];

        // 1. Fetch all non-deleted contacts from CiviCRM who have primary emails
        $query = "SELECT c.id, c.display_name, e.email, p.phone
                  FROM civicrm_contact c
                  INNER JOIN civicrm_email e ON e.contact_id = c.id AND e.is_primary = 1
                  LEFT JOIN civicrm_phone p ON p.contact_id = c.id AND p.is_primary = 1
                  WHERE c.is_deleted = 0";
        
        $stmt = $civiDb->prepare($query);
        $stmt->execute();
        $contacts = $stmt->fetchAll();

        $stats['contacts_scanned'] = count($contacts);

        // 2. Prepare statements for local checking and insertion
        $checkStmt = $appDb->prepare("SELECT contact_id, role FROM tgg_member_settings WHERE contact_id = :contact_id LIMIT 1");
        $insertStmt = $appDb->prepare("INSERT INTO tgg_member_settings (contact_id, password_hash, role, is_profile_public, public_fields) 
                                       VALUES (:contact_id, :password_hash, :role, 1, :public_fields)");

        // We will set a default password of 'change_me_123' if creating new accounts
        // Users should renew or reset their password on first login
        $defaultPasswordHash = password_hash('change_me_123', PASSWORD_DEFAULT);
        $defaultPublicFields = json_encode(['display_name', 'membership_type', 'membership_status']);

        foreach ($contacts as $contact) {
            $contactId = (int)$contact['id'];
            
            try {
                // Check if they already exist locally
                $checkStmt->execute(['contact_id' => $contactId]);
                $localUser = $checkStmt->fetch();

                if (!$localUser) {
                    // Create local credentials record
                    // First contact imported will be set as admin automatically if none exist, otherwise member
                    $isAdminCheck = $appDb->query("SELECT COUNT(*) FROM tgg_member_settings WHERE role = 'admin'")->fetchColumn();
                    $role = ($isAdminCheck == 0 && $contactId == 1) ? 'admin' : 'member';

                    $insertStmt->execute([
                        'contact_id' => $contactId,
                        'password_hash' => $defaultPasswordHash,
                        'role' => $role,
                        'public_fields' => $defaultPublicFields
                    ]);
                    $stats['settings_created']++;
                } else {
                    $stats['settings_updated']++;
                }

                // Sync CiviCRM membership details to local tgg_subscriptions
                $memQuery = $civiDb->prepare("
                    SELECT membership_type_id, join_date, start_date, end_date, is_active
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

        return $stats;
    }

    /**
     * Get active membership tiers directly from CiviCRM
     * @return array
     * @throws Exception
     */
    public static function getMembershipTiers(): array {
        $civiDb = Database::getCiviConnection();
        $query = "SELECT id, name, description, minimum_fee, duration_unit, duration_interval 
                  FROM civicrm_membership_type 
                  ORDER BY minimum_fee ASC";
        return $civiDb->query($query)->fetchAll();
    }

    /**
     * Get details for a specific contact's membership
     * @param int $contactId
     * @return array|null
     * @throws Exception
     */
    public static function getMemberMembershipDetails(int $contactId): ?array {
        $civiDb = Database::getCiviConnection();
        $query = "SELECT m.id as membership_id, m.join_date, m.start_date, m.end_date,
                         t.name as membership_name, t.minimum_fee,
                         s.name as status_name, s.label as status_label, s.is_active
                  FROM civicrm_membership m
                  INNER JOIN civicrm_membership_type t ON m.membership_type_id = t.id
                  INNER JOIN civicrm_membership_status s ON m.status_id = s.id
                  WHERE m.contact_id = :contact_id
                  ORDER BY m.end_date DESC
                  LIMIT 1";
        
        $stmt = $civiDb->prepare($query);
        $stmt->execute(['contact_id' => $contactId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Get a list of all members with membership status
     * @return array
     * @throws Exception
     */
    public static function getMembersList(): array {
        $civiDb = Database::getCiviConnection();
        $query = "SELECT c.id, c.display_name, e.email, p.phone,
                         m.join_date, m.start_date, m.end_date,
                         t.name as membership_name,
                         s.label as status_label, s.is_active
                  FROM civicrm_contact c
                  INNER JOIN civicrm_email e ON e.contact_id = c.id AND e.is_primary = 1
                  LEFT JOIN civicrm_phone p ON p.contact_id = c.id AND p.is_primary = 1
                  LEFT JOIN civicrm_membership m ON m.contact_id = c.id
                  LEFT JOIN civicrm_membership_type t ON m.membership_type_id = t.id
                  LEFT JOIN civicrm_membership_status s ON m.status_id = s.id
                  WHERE c.is_deleted = 0
                  ORDER BY c.display_name ASC";
        return $civiDb->query($query)->fetchAll();
    }
}
