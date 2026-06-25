<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class RolesSortOrder extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("ALTER TABLE `tgg_roles` ADD COLUMN `sort_order` TINYINT UNSIGNED NOT NULL DEFAULT 99 AFTER `description`");

        $this->execute("UPDATE `tgg_roles` SET `sort_order` = CASE `name`
            WHEN 'superadmin' THEN 1
            WHEN 'admin'      THEN 2
            WHEN 'majordomo'  THEN 3
            WHEN 'host'       THEN 4
            WHEN 'member'     THEN 5
            ELSE 99
        END");
    }

    public function down(): void
    {
        $this->execute("ALTER TABLE `tgg_roles` DROP COLUMN `sort_order`");
    }
}
