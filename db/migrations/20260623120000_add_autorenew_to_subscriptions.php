<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddAutorenewToSubscriptions extends AbstractMigration
{
    public function up(): void
    {
        $this->table('tgg_subscriptions')
            ->addColumn('auto_renew', 'boolean', ['null' => false, 'default' => false, 'after' => 'status'])
            ->addColumn('stripe_customer_id', 'string', ['limit' => 255, 'null' => true, 'after' => 'auto_renew'])
            ->addColumn('stripe_payment_method_id', 'string', ['limit' => 255, 'null' => true, 'after' => 'stripe_customer_id'])
            ->addColumn('auto_renew_attempts', 'integer', ['null' => false, 'default' => 0, 'after' => 'stripe_payment_method_id'])
            ->addColumn('auto_renew_reminder_sent_for', 'date', ['null' => true, 'after' => 'auto_renew_attempts'])
            ->addIndex(['auto_renew', 'status', 'end_date'], ['name' => 'idx_autorenew_due'])
            ->update();
    }

    public function down(): void
    {
        $this->table('tgg_subscriptions')
            ->removeIndexByName('idx_autorenew_due')
            ->removeColumn('auto_renew_reminder_sent_for')
            ->removeColumn('auto_renew_attempts')
            ->removeColumn('stripe_payment_method_id')
            ->removeColumn('stripe_customer_id')
            ->removeColumn('auto_renew')
            ->update();
    }
}
