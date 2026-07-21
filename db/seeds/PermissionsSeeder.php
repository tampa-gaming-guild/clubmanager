<?php

declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

final class PermissionsSeeder extends AbstractSeed
{
    public function getDescription(): string
    {
        return 'Fixed system permissions. Safe to rerun: upserts name/description, never deletes.';
    }

    public function run(): void
    {
        $rows = [
            ['name' => 'all', 'description' => 'All permissions / full access'],
            ['name' => 'admin panel', 'description' => 'Access to admin-only member management UI (profile rate info, membership visibility)'],
            ['name' => 'manage configuration', 'description' => 'Edit club configuration: membership plans and subscription rates'],
            ['name' => 'manage roles', 'description' => 'Assign and remove roles for admin, majordomo, host, and member accounts'],
            ['name' => 'process payments', 'description' => 'View payments ledger and member billing history'],
            ['name' => 'schedule events', 'description' => 'Create and edit calendar events'],
            ['name' => 'edit checkins', 'description' => 'Log and edit attendance check-ins'],
            ['name' => 'manage hosting', 'description' => 'Manage volunteer slots and credits for other members; assign the host role'],
            ['name' => 'manage library', 'description' => 'Manage the board game library: add/edit/remove games and track member-loaned copies; syncs to BoardGameGeek'],
            ['name' => 'password resets', 'description' => 'Perform password resets for contacts'],
            ['name' => 'volunteer', 'description' => 'Sign up for volunteer slots at events'],
        ];

        $sql = 'INSERT INTO `tgg_permissions` (`name`, `description`) VALUES (:name, :description)
                ON DUPLICATE KEY UPDATE `description` = VALUES(`description`)';
        $stmt = $this->getAdapter()->getConnection()->prepare($sql);
        foreach ($rows as $row) {
            $stmt->execute($row);
        }
    }
}
