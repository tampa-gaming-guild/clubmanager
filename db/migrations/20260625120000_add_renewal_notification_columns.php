<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddRenewalNotificationColumns extends AbstractMigration
{
    public function up(): void
    {
        $this->table('tgg_subscriptions')
            ->addColumn('renewal_reminder_sent_for', 'date', ['null' => true, 'after' => 'auto_renew_reminder_sent_for'])
            ->addColumn('expired_notice_sent_for', 'date', ['null' => true, 'after' => 'renewal_reminder_sent_for'])
            ->update();
    }

    public function down(): void
    {
        $this->table('tgg_subscriptions')
            ->removeColumn('expired_notice_sent_for')
            ->removeColumn('renewal_reminder_sent_for')
            ->update();
    }
}
