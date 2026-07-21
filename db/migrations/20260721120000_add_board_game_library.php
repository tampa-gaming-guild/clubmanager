<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Board game library: a local cache of the club's BoardGameGeek collection,
 * seeded by a one-time import (bin/import-bgg-collection.php) and then
 * authoritative going forward -- edits happen here and are pushed out to BGG
 * via App\BggCollectionSync, not the other way around.
 *
 * owner_contact_id/loan_started_at track a member who has LENT a physical
 * copy TO the club (not a club-to-member checkout system) -- null means the
 * club owns the copy outright.
 */
final class AddBoardGameLibrary extends AbstractMigration
{
    private const TABLE_OPTS = ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'signed' => true];

    public function up(): void
    {
        $this->table('tgg_games', self::TABLE_OPTS)
            ->addColumn('bgg_id', 'integer', ['null' => false])
            ->addColumn('name', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('year_published', 'integer', ['null' => true])
            ->addColumn('thumbnail_url', 'string', ['limit' => 500, 'null' => true])
            ->addColumn('image_url', 'string', ['limit' => 500, 'null' => true])
            ->addColumn('description', 'text', ['null' => true])
            ->addColumn('min_players', 'smallinteger', ['signed' => false, 'null' => true])
            ->addColumn('max_players', 'smallinteger', ['signed' => false, 'null' => true])
            ->addColumn('min_playtime', 'smallinteger', ['signed' => false, 'null' => true])
            ->addColumn('max_playtime', 'smallinteger', ['signed' => false, 'null' => true])
            ->addColumn('min_age', 'tinyinteger', ['signed' => false, 'null' => true])
            ->addColumn('bgg_rating_bayes', 'decimal', ['precision' => 4, 'scale' => 2, 'null' => true])
            ->addColumn('bgg_weight', 'decimal', ['precision' => 3, 'scale' => 2, 'null' => true])
            ->addColumn('mechanisms', 'text', ['null' => true]) // JSON array of strings
            ->addColumn('categories', 'text', ['null' => true]) // JSON array of strings
            ->addColumn('notes', 'text', ['null' => true])
            ->addColumn('owner_contact_id', 'integer', ['null' => true])
            ->addColumn('loan_started_at', 'datetime', ['null' => true])
            ->addColumn('bgg_sync_status', 'string', ['limit' => 20, 'null' => false, 'default' => 'pending']) // synced|pending|failed
            ->addColumn('bgg_last_synced_at', 'datetime', ['null' => true])
            ->addColumn('bgg_last_sync_error', 'text', ['null' => true])
            ->addColumn('added_by_contact_id', 'integer', ['null' => true]) // no FK, matches other actor columns (e.g. tgg_pending_payments.resolved_by)
            ->addColumn('is_deleted', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('created_at', 'timestamp', ['null' => true, 'default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'timestamp', ['null' => true, 'default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->addIndex('bgg_id', ['unique' => true])
            ->addIndex('name')
            ->addIndex('owner_contact_id')
            ->addIndex('bgg_sync_status')
            ->addIndex('is_deleted')
            ->addForeignKey('owner_contact_id', 'tgg_contacts', 'id', ['delete' => 'SET_NULL', 'update' => 'CASCADE', 'constraint' => 'fk_games_owner_contact'])
            ->create();
    }

    public function down(): void
    {
        $this->table('tgg_games')->drop()->save();
    }
}
