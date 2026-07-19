<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Adds an approval workflow to volunteer signups: a self-signup by a member
 * without the 'volunteer' permission lands as 'pending' (still occupying the
 * slot) until a majordomo confirms it via Event::approveVolunteerSignup().
 * Existing rows default to 'confirmed' since they were all made under the old
 * permission-gated flow.
 */
final class AddVolunteerSignupStatus extends AbstractMigration
{
    public function up(): void
    {
        $this->table('tgg_volunteer_signups')
            ->addColumn('status', 'enum', ['values' => ['confirmed', 'pending'], 'null' => false, 'default' => 'confirmed', 'after' => 'contact_id'])
            ->addColumn('resolved_at', 'datetime', ['null' => true, 'after' => 'signed_up_at'])
            ->addColumn('resolved_by', 'integer', ['null' => true, 'after' => 'resolved_at'])
            ->update();
    }

    public function down(): void
    {
        $this->table('tgg_volunteer_signups')
            ->removeColumn('resolved_by')
            ->removeColumn('resolved_at')
            ->removeColumn('status')
            ->update();
    }
}
