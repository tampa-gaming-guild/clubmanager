<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class DropProfilePublicFields extends AbstractMigration
{
    public function up(): void
    {
        $this->table('tgg_member_settings')
            ->removeColumn('is_profile_public')
            ->removeColumn('public_fields')
            ->update();
    }

    public function down(): void
    {
        $this->table('tgg_member_settings')
            ->addColumn('is_profile_public', 'boolean', ['null' => false, 'default' => true, 'after' => 'custom_display_name'])
            ->addColumn('public_fields', 'text', ['null' => true, 'after' => 'is_profile_public'])
            ->update();
    }
}
