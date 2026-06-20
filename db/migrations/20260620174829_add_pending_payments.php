<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddPendingPayments extends AbstractMigration
{
    private const TABLE_OPTS = ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'signed' => true];

    public function up(): void
    {
        $this->table('tgg_pending_payments', self::TABLE_OPTS)
            ->addColumn('contact_id', 'integer', ['null' => false])
            ->addColumn('type', 'string', ['limit' => 50, 'null' => false]) // 'entrance_fee', 'membership_renewal'
            ->addColumn('plan_id', 'integer', ['null' => true])
            ->addColumn('amount', 'decimal', ['precision' => 20, 'scale' => 2, 'null' => false])
            ->addColumn('payment_method', 'string', ['limit' => 50, 'null' => false, 'default' => 'cash'])
            ->addColumn('status', 'string', ['limit' => 20, 'null' => false, 'default' => 'pending']) // 'pending', 'approved', 'denied'
            ->addColumn('requested_at', 'datetime', ['null' => false])
            ->addColumn('resolved_at', 'datetime', ['null' => true])
            ->addColumn('resolved_by', 'integer', ['null' => true])
            ->addIndex(['status', 'requested_at'])
            ->addIndex(['contact_id'])
            ->addForeignKey('contact_id', 'tgg_contacts', 'id', ['delete' => 'CASCADE', 'constraint' => 'fk_pending_payments_contact'])
            ->create();
    }

    public function down(): void
    {
        $this->table('tgg_pending_payments')->drop()->save();
    }
}
