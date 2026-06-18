<?php

declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

final class MembershipStatusesSeeder extends AbstractSeed
{
    public function getDescription(): string
    {
        return 'Fixed system membership statuses (manually-assigned ids). Safe to rerun: upserts, never deletes.';
    }

    public function run(): void
    {
        $rows = [
            ['id' => 1, 'name' => 'New', 'label' => 'New', 'is_active' => 1],
            ['id' => 2, 'name' => 'Current', 'label' => 'Current', 'is_active' => 1],
            ['id' => 3, 'name' => 'Grace', 'label' => 'Grace Period', 'is_active' => 1],
            ['id' => 4, 'name' => 'Expired', 'label' => 'Expired', 'is_active' => 0],
            ['id' => 5, 'name' => 'Pending', 'label' => 'Pending', 'is_active' => 0],
        ];

        $sql = 'INSERT INTO `tgg_membership_statuses` (`id`, `name`, `label`, `is_active`) VALUES (:id, :name, :label, :is_active)
                ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `label` = VALUES(`label`), `is_active` = VALUES(`is_active`)';
        $stmt = $this->getAdapter()->getConnection()->prepare($sql);
        foreach ($rows as $row) {
            $stmt->execute($row);
        }
    }
}
