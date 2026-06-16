<?php
require_once dirname(__DIR__) . '/config/bootstrap.php';
use App\Database;

try {
    $db = Database::getAppConnection();
    echo "Connected to the database successfully.\n";

    // 0. Ensure tgg_member_settings has all expected columns
    $expectedSettingsColumns = [
        'custom_display_name' => "VARCHAR(255) NULL",
        'is_profile_public' => "TINYINT(1) NOT NULL DEFAULT 1",
        'public_fields' => "TEXT NULL",
        'credits_earned' => "FLOAT NOT NULL DEFAULT 0.0",
        'credits_applied' => "FLOAT NOT NULL DEFAULT 0.0",
        'expired_credits' => "FLOAT NOT NULL DEFAULT 0.0",
        'failed_login_attempts' => "INT NOT NULL DEFAULT 0",
        'locked_until' => "DATETIME NULL"
    ];

    $stmt = $db->query("SHOW COLUMNS FROM `tgg_member_settings`");
    $existingSettingsColumns = $stmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($expectedSettingsColumns as $col => $definition) {
        if (!in_array($col, $existingSettingsColumns)) {
            $db->exec("ALTER TABLE `tgg_member_settings` ADD COLUMN `$col` $definition");
            echo "Added missing column '$col' to 'tgg_member_settings'.\n";
        }
    }

    // 1. Create tgg_roles table
    $db->exec("
        CREATE TABLE IF NOT EXISTS `tgg_roles` (
          `id` INT AUTO_INCREMENT NOT NULL,
          `name` VARCHAR(50) NOT NULL UNIQUE,
          `description` VARCHAR(255) NULL,
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    echo "Table 'tgg_roles' verified/created.\n";

    // Seed default roles
    $db->exec("
        INSERT INTO `tgg_roles` (`name`, `description`) VALUES
        ('superadmin', 'Super Administrator with full access'),
        ('admin', 'Administrator with management access'),
        ('host', 'Event Host with scheduling and check-in access'),
        ('member', 'Regular Club Member'),
        ('guest', 'Guest visitor with limited access')
        ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);
    ");
    echo "Default roles seeded.\n";

    // 2. Create tgg_permissions table
    $db->exec("
        CREATE TABLE IF NOT EXISTS `tgg_permissions` (
          `id` INT AUTO_INCREMENT NOT NULL,
          `name` VARCHAR(50) NOT NULL UNIQUE,
          `description` VARCHAR(255) NULL,
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    echo "Table 'tgg_permissions' verified/created.\n";

    // Seed default permissions
    $db->exec("
        INSERT INTO `tgg_permissions` (`name`, `description`) VALUES
        ('all', 'All permissions / full access'),
        ('process payments', 'View payments ledger and process billing'),
        ('schedule events', 'Create and edit calendar events'),
        ('edit checkins', 'Log and edit attendance check-ins'),
        ('edit volunteer slots', 'Assign or cancel volunteer shifts and credits'),
        ('password resets', 'Perform password resets for contacts')
        ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);
    ");
    echo "Default permissions seeded.\n";

    // 3. Create tgg_role_permissions table
    $db->exec("
        CREATE TABLE IF NOT EXISTS `tgg_role_permissions` (
          `role_id` INT NOT NULL,
          `permission_id` INT NOT NULL,
          PRIMARY KEY (`role_id`, `permission_id`),
          CONSTRAINT `fk_role_permissions_role` FOREIGN KEY (`role_id`) REFERENCES `tgg_roles` (`id`) ON DELETE CASCADE,
          CONSTRAINT `fk_role_permissions_permission` FOREIGN KEY (`permission_id`) REFERENCES `tgg_permissions` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    echo "Table 'tgg_role_permissions' verified/created.\n";

    // Seed default role-permission mappings
    $db->exec("
        INSERT IGNORE INTO `tgg_role_permissions` (`role_id`, `permission_id`)
        SELECT r.id, p.id FROM tgg_roles r, tgg_permissions p 
        WHERE (r.name = 'superadmin' AND p.name = 'all')
           OR (r.name = 'admin' AND p.name IN ('process payments', 'schedule events', 'edit checkins', 'edit volunteer slots', 'password resets'))
           OR (r.name = 'host' AND p.name IN ('schedule events', 'edit checkins', 'edit volunteer slots'));
    ");
    echo "Default role-permission mappings seeded.\n";

    // Helper to get column details
    $getColumnInfo = function($db, $table, $column) {
        $stmt = $db->query("SHOW FULL COLUMNS FROM `$table` LIKE '$column'");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new Exception("Column '$column' not found in table '$table'.");
        }
        return [
            'type' => $row['Type'],
            'collation' => $row['Collation']
        ];
    };

    $contactIdInfo = $getColumnInfo($db, 'tgg_member_settings', 'contact_id');
    $roleNameInfo = $getColumnInfo($db, 'tgg_roles', 'name');

    echo "Diagnostic - tgg_member_settings.contact_id: Type = {$contactIdInfo['type']}, Collation = " . ($contactIdInfo['collation'] ?? 'N/A') . "\n";
    echo "Diagnostic - tgg_roles.name: Type = {$roleNameInfo['type']}, Collation = " . ($roleNameInfo['collation'] ?? 'N/A') . "\n";

    $contactIdType = $contactIdInfo['type'];
    $roleNameType = $roleNameInfo['type'];
    $roleNameCollationStr = !empty($roleNameInfo['collation']) ? " COLLATE " . $roleNameInfo['collation'] : "";

    // 4. Create tgg_member_roles table dynamically matching foreign key fields
    $db->exec("
        CREATE TABLE IF NOT EXISTS `tgg_member_roles` (
          `contact_id` {$contactIdType} NOT NULL,
          `role_name` {$roleNameType}{$roleNameCollationStr} NOT NULL,
          PRIMARY KEY (`contact_id`, `role_name`),
          CONSTRAINT `fk_member_roles_contact` FOREIGN KEY (`contact_id`) REFERENCES `tgg_member_settings` (`contact_id`) ON DELETE CASCADE,
          CONSTRAINT `fk_member_roles_role` FOREIGN KEY (`role_name`) REFERENCES `tgg_roles` (`name`) ON UPDATE CASCADE
        ) ENGINE=InnoDB;
    ");
    echo "Table 'tgg_member_roles' verified/created.\n";

    // 5. Create trigger
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

    // 6. Migrate current roles
    $insertedCount = $db->exec("
        INSERT IGNORE INTO `tgg_member_roles` (`contact_id`, `role_name`)
        SELECT `contact_id`, `role` FROM `tgg_member_settings`
    ");
    echo "Migrated {$insertedCount} role records into 'tgg_member_roles'.\n";

    // Print summary of counts per role in the new table
    $summary = $db->query("SELECT role_name, COUNT(*) as count FROM tgg_member_roles GROUP BY role_name")->fetchAll(PDO::FETCH_ASSOC);
    print_r($summary);

    echo "=== MULTIPLE ROLES MIGRATION COMPLETED SUCCESSFULLY ===\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
