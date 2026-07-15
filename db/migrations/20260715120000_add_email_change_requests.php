<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddEmailChangeRequests extends AbstractMigration
{
    private const TABLE_OPTS = ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'signed' => true];

    public function up(): void
    {
        // One pending email change per member (PK contact_id): a re-request
        // overwrites the previous row, invalidating its links.
        $this->table('tgg_email_change_requests', self::TABLE_OPTS + ['id' => false, 'primary_key' => 'contact_id'])
            ->addColumn('contact_id', 'integer', ['null' => false])
            ->addColumn('new_email', 'string', ['limit' => 254, 'null' => false])
            ->addColumn('old_email', 'string', ['limit' => 254, 'null' => false]) // snapshot for notices
            ->addColumn('token', 'string', ['limit' => 64, 'null' => false]) // sha256 hex, verify link (new address)
            ->addColumn('cancel_token', 'string', ['limit' => 64, 'null' => false]) // sha256 hex, cancel link (old address)
            ->addColumn('expires_at', 'datetime', ['null' => false])
            ->addColumn('created_at', 'timestamp', ['null' => true, 'default' => 'CURRENT_TIMESTAMP'])
            ->addIndex('token', ['name' => 'idx_email_change_token'])
            ->addIndex('cancel_token', ['name' => 'idx_email_change_cancel_token'])
            ->addForeignKey('contact_id', 'tgg_contacts', 'id', ['delete' => 'CASCADE', 'constraint' => 'fk_email_change_contact'])
            ->create();

        // Post-completion recovery: each completed change leaves a revert token
        // mailed to the old address. Not keyed by contact_id -- a chained
        // takeover (A->B->C) must not invalidate the victim's earlier token.
        $this->table('tgg_email_change_reverts', self::TABLE_OPTS)
            ->addColumn('contact_id', 'integer', ['null' => false])
            ->addColumn('old_email', 'string', ['limit' => 254, 'null' => false]) // address to restore
            ->addColumn('new_email', 'string', ['limit' => 254, 'null' => false])
            ->addColumn('token', 'string', ['limit' => 64, 'null' => false]) // sha256 hex
            ->addColumn('expires_at', 'datetime', ['null' => false])
            ->addColumn('created_at', 'timestamp', ['null' => true, 'default' => 'CURRENT_TIMESTAMP'])
            ->addIndex('token', ['name' => 'idx_email_revert_token'])
            ->addIndex('contact_id')
            ->addForeignKey('contact_id', 'tgg_contacts', 'id', ['delete' => 'CASCADE', 'constraint' => 'fk_email_revert_contact'])
            ->create();
    }

    public function down(): void
    {
        $this->table('tgg_email_change_reverts')->drop()->save();
        $this->table('tgg_email_change_requests')->drop()->save();
    }
}
