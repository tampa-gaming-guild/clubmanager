<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddRenewalGraceDaysSetting extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("
            INSERT INTO tgg_volunteer_credits (credit_key, credit_label, credits) 
            VALUES ('renewal_grace_days', 'Renewal Grace Period (Days)', 30)
            ON DUPLICATE KEY UPDATE credits = 30
        ");
    }

    public function down(): void
    {
        $this->execute("DELETE FROM tgg_volunteer_credits WHERE credit_key = 'renewal_grace_days'");
    }
}
