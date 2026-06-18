<?php

declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

final class VolunteerCreditsSeeder extends AbstractSeed
{
    public function getDescription(): string
    {
        return 'Default volunteer credit weights. INSERT ONLY where missing -- never '
             . 'overwrites a weight an admin has tuned in the admin panel.';
    }

    public function run(): void
    {
        $rows = [
            ['credit_key' => 'weekday_open', 'credit_label' => 'Weekday Open', 'credits' => 1.0],
            ['credit_key' => 'weekday_close', 'credit_label' => 'Weekday Close', 'credits' => 1.0],
            ['credit_key' => 'sunday_open', 'credit_label' => 'Sunday Open', 'credits' => 2.0],
            ['credit_key' => 'sunday_close', 'credit_label' => 'Sunday Close', 'credits' => 2.0],
            ['credit_key' => 'credits_per_month', 'credit_label' => 'Credits required for 1 month free membership', 'credits' => 4.0],
            ['credit_key' => 'weekday_greeter', 'credit_label' => 'Weekday Greeter', 'credits' => 0.0],
            ['credit_key' => 'sunday_greeter', 'credit_label' => 'Sunday Greeter', 'credits' => 0.0],
            ['credit_key' => 'credit_expiration_days', 'credit_label' => 'Credit Expiration (Days)', 'credits' => 365.0],
        ];

        $sql = 'INSERT INTO `tgg_volunteer_credits` (`credit_key`, `credit_label`, `credits`)
                SELECT :credit_key, :credit_label, :credits FROM DUAL
                WHERE NOT EXISTS (SELECT 1 FROM `tgg_volunteer_credits` WHERE `credit_key` = :credit_key2)';
        $stmt = $this->getAdapter()->getConnection()->prepare($sql);
        foreach ($rows as $row) {
            $row['credit_key2'] = $row['credit_key'];
            $stmt->execute($row);
        }
    }
}
