<?php
require_once dirname(__DIR__) . '/config/bootstrap.php';
use App\Database;

try {
    $db = Database::getAppConnection();
    echo "Connected to the database successfully.\n";

    // 1. Create tgg_member_roles table
    $db->exec("
        CREATE TABLE IF NOT EXISTS `tgg_member_roles` (
          `contact_id` INT NOT NULL,
          `role_name` VARCHAR(50) NOT NULL,
          PRIMARY KEY (`contact_id`, `role_name`),
          CONSTRAINT `fk_member_roles_contact` FOREIGN KEY (`contact_id`) REFERENCES `tgg_member_settings` (`contact_id`) ON DELETE CASCADE,
          CONSTRAINT `fk_member_roles_role` FOREIGN KEY (`role_name`) REFERENCES `tgg_roles` (`name`) ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    echo "Table 'tgg_member_roles' verified/created.\n";

    // 2. Create trigger
    $db->exec("DROP TRIGGER IF EXISTS `tgg_member_settings_after_insert`");
    $db->exec("
        CREATE TRIGGER `tgg_member_settings_after_insert`
        AFTER INSERT ON `tgg_member_settings`
        FOR EACH ROW
        BEGIN
          INSERT INTO `tgg_member_roles` (`contact_id`, `role_name`)
          VALUES (NEW.contact_id, NEW.role)
          ON DUPLICATE KEY UPDATE `role_name` = VALUES(`role_name`);
        END;
    ");
    echo "Trigger 'tgg_member_settings_after_insert' created.\n";

    // 3. Migrate current roles
    $insertedCount = $db->exec("
        INSERT IGNORE INTO `tgg_member_roles` (`contact_id`, `role_name`)
        SELECT `contact_id`, `role` FROM `tgg_member_settings`
    ");
    echo "Migrated {$insertedCount} role records into 'tgg_member_roles'.\n";

    // Let's print summary of counts per role in the new table
    $summary = $db->query("SELECT role_name, COUNT(*) as count FROM tgg_member_roles GROUP BY role_name")->fetchAll(PDO::FETCH_ASSOC);
    print_r($summary);

    echo "=== MULTIPLE ROLES MIGRATION COMPLETED SUCCESSFULLY ===\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
