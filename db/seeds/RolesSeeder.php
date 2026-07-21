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
            ['name' => 'superadmin', 'description' => 'Super Administrator with full access',                              'sort_order' => 1],
            ['name' => 'admin',      'description' => 'Administrator with management access',                              'sort_order' => 2],
            ['name' => 'majordomo',  'description' => 'Volunteer coordinator who manages hosts and volunteer scheduling',  'sort_order' => 3],
            ['name' => 'host',       'description' => 'Event Host with check-in access',                                   'sort_order' => 4],
            ['name' => 'librarian',  'description' => "Manages the club's board game library",                             'sort_order' => 6],
            ['name' => 'member',     'description' => 'Regular Club Member',                                               'sort_order' => 5],
        ];

        $sql = 'INSERT INTO `tgg_roles` (`name`, `description`, `sort_order`) VALUES (:name, :description, :sort_order)
                ON DUPLICATE KEY UPDATE `description` = VALUES(`description`), `sort_order` = VALUES(`sort_order`)';
        $stmt = $this->getAdapter()->getConnection()->prepare($sql);
        foreach ($rows as $row) {
            $stmt->execute($row);
        }
    }
}
