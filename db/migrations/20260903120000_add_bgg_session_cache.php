<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Singleton-row cache for BggCollectionSync's login session cookies, so it
 * stops re-authenticating with BGG on every collection push (see
 * BggCollectionSync::withSession). BGG's session cookie is long-lived in
 * practice; there's no known fixed TTL, so this is refreshed reactively --
 * on an auth-type failure -- rather than expired on a timer.
 */
final class AddBggSessionCache extends AbstractMigration
{
    public function up(): void
    {
        $this->table('tgg_bgg_session', ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'id' => false, 'primary_key' => 'id'])
            ->addColumn('id', 'integer', ['null' => false, 'signed' => true])
            ->addColumn('cookies', 'text', ['null' => false])
            ->addColumn('fetched_at', 'datetime', ['null' => false])
            ->create();
    }

    public function down(): void
    {
        $this->table('tgg_bgg_session')->drop()->save();
    }
}
