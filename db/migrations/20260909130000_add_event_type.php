<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddEventType extends AbstractMigration
{
    public function up(): void
    {
        $this->table('tgg_events')
            ->addColumn('event_type', 'string', [
                'limit' => 20,
                'null' => false,
                'default' => 'session', // 'session' (opens check-in, requires >=1 slot) | 'other' (no check-in, 0+ slots)
                'after' => 'description',
            ])
            ->addColumn('icon', 'string', [
                'limit' => 8,
                'null' => true,
                'default' => null, // admin-entered emoji, purely cosmetic, shown before the event title
                'after' => 'event_type',
            ])
            ->update();
    }

    public function down(): void
    {
        $this->table('tgg_events')
            ->removeColumn('event_type')
            ->removeColumn('icon')
            ->update();
    }
}
