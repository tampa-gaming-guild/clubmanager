<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddFounderFlag extends AbstractMigration
{
    public function up(): void
    {
        $this->table('tgg_member_settings')
            ->addColumn('is_founder', 'boolean', ['null' => false, 'default' => false, 'after' => 'role'])
            ->update();
    }

    public function down(): void
    {
        $this->table('tgg_member_settings')
            ->removeColumn('is_founder')
            ->update();
    }
}
