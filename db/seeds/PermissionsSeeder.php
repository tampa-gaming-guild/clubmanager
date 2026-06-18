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
            ['name' => 'process payments', 'description' => 'View payments ledger and process billing'],
            ['name' => 'schedule events', 'description' => 'Create and edit calendar events'],
            ['name' => 'edit checkins', 'description' => 'Log and edit attendance check-ins'],
            ['name' => 'edit volunteer slots', 'description' => 'Assign or cancel volunteer shifts and credits'],
            ['name' => 'password resets', 'description' => 'Perform password resets for contacts'],
        ];

        $sql = 'INSERT INTO `tgg_permissions` (`name`, `description`) VALUES (:name, :description)
                ON DUPLICATE KEY UPDATE `description` = VALUES(`description`)';
        $stmt = $this->getAdapter()->getConnection()->prepare($sql);
        foreach ($rows as $row) {
            $stmt->execute($row);
        }
    }
}
