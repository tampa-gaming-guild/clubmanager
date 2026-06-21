<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddGuestPasses extends AbstractMigration
{
    public function up(): void
    {
        $this->table('tgg_subscription_plans')
            ->addColumn('guests_per_month', 'integer', ['null' => false, 'default' => 0, 'after' => 'active'])
            ->update();

        $this->table('tgg_checkins')
            ->addColumn('guest_name', 'string', ['limit' => 255, 'null' => true, 'after' => 'notes'])
            ->update();
    }

    public function down(): void
    {
        $this->table('tgg_subscription_plans')
            ->removeColumn('guests_per_month')
            ->update();

        $this->table('tgg_checkins')
            ->removeColumn('guest_name')
            ->update();
    }
}
