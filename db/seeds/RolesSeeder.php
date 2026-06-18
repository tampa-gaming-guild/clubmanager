<?php

declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

final class RolesSeeder extends AbstractSeed
{
    public function getDescription(): string
    {
        return 'Fixed system roles. Safe to rerun: upserts name/description, never deletes.';
    }

    public function run(): void
    {
        $rows = [
            ['name' => 'superadmin', 'description' => 'Super Administrator with full access'],
            ['name' => 'admin', 'description' => 'Administrator with management access'],
            ['name' => 'host', 'description' => 'Event Host with scheduling and check-in access'],
            ['name' => 'member', 'description' => 'Regular Club Member'],
            ['name' => 'guest', 'description' => 'Guest visitor with limited access'],
        ];

        $sql = 'INSERT INTO `tgg_roles` (`name`, `description`) VALUES (:name, :description)
                ON DUPLICATE KEY UPDATE `description` = VALUES(`description`)';
        $stmt = $this->getAdapter()->getConnection()->prepare($sql);
        foreach ($rows as $row) {
            $stmt->execute($row);
        }
    }
}
