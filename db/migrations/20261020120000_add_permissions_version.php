<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Adds a per-account counter that is bumped whenever the account's roles (or
 * the role->permission matrix) change. Sessions cache roles/permissions at
 * login; comparing the cached counter against this one lets a live session
 * pick up changes on its next request. See Auth::syncSessionPermissions().
 */
final class AddPermissionsVersion extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("ALTER TABLE `tgg_member_settings` ADD COLUMN `permissions_version` INT UNSIGNED NOT NULL DEFAULT 0");
    }

    public function down(): void
    {
        $this->execute("ALTER TABLE `tgg_member_settings` DROP COLUMN `permissions_version`");
    }
}
