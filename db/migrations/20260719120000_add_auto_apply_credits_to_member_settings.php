<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddAutoApplyCreditsToMemberSettings extends AbstractMigration
{
    public function up(): void
    {
        $this->table('tgg_member_settings')
            ->addColumn('auto_apply_credits', 'boolean', ['null' => false, 'default' => false, 'after' => 'expired_credits'])
            ->update();
    }

    public function down(): void
    {
        $this->table('tgg_member_settings')
            ->removeColumn('auto_apply_credits')
            ->update();
    }
}
