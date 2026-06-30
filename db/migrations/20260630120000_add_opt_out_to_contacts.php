<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddOptOutToContacts extends AbstractMigration
{
    public function up(): void
    {
        $this->table('tgg_contacts')
            ->addColumn('is_opt_out', 'boolean', ['null' => false, 'default' => false, 'after' => 'is_deleted'])
            ->update();
    }

    public function down(): void
    {
        $this->table('tgg_contacts')
            ->removeColumn('is_opt_out')
            ->update();
    }
}
