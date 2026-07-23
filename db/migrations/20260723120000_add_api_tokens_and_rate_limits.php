<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Foundation for the mobile app's token-based API auth (see plan doc
 * "Mobile App for Members/Hosts"). Two tables:
 *
 *  - tgg_api_tokens: one row per issued refresh token (one per logged-in
 *    device). Only the SHA-256 hash of the raw token is ever stored, same
 *    precedent as tgg_password_resets/tgg_trial_verifications. Access tokens
 *    are short-lived and self-verified (signed, not stored), so only refresh
 *    tokens need a revocable server-side record.
 *  - tgg_api_rate_limits: fixed-window per-IP attempt counter for the public
 *    token/login endpoint, which (unlike the web login form) is reachable
 *    directly by any HTTP client -- the existing per-account lockout in
 *    tgg_member_settings isn't sufficient on its own for a public API.
 */
final class AddApiTokensAndRateLimits extends AbstractMigration
{
    private const TABLE_OPTS = ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'signed' => true];

    public function up(): void
    {
        // No FK on contact_id: contacts are soft-deleted and revoked tokens
        // must outlive the contact row, same precedent as tgg_audit_log's
        // actor columns.
        $this->table('tgg_api_tokens', self::TABLE_OPTS)
            ->addColumn('contact_id', 'integer', ['null' => false])
            ->addColumn('token_hash', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('device_label', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('expires_at', 'datetime', ['null' => false])
            ->addColumn('revoked_at', 'datetime', ['null' => true])
            ->addColumn('last_used_at', 'datetime', ['null' => true])
            ->addColumn('created_at', 'timestamp', ['null' => true, 'default' => 'CURRENT_TIMESTAMP'])
            ->addIndex('token_hash', ['unique' => true, 'name' => 'uq_api_token_hash'])
            ->addIndex('contact_id', ['name' => 'idx_api_token_contact'])
            ->create();

        $this->table('tgg_api_rate_limits', self::TABLE_OPTS + ['id' => false, 'primary_key' => 'bucket_key'])
            ->addColumn('bucket_key', 'string', ['limit' => 100, 'null' => false]) // e.g. "api_login:203.0.113.4"
            ->addColumn('window_started_at', 'datetime', ['null' => false])
            ->addColumn('attempts', 'integer', ['null' => false, 'default' => 0])
            ->create();
    }

    public function down(): void
    {
        $this->table('tgg_api_rate_limits')->drop()->save();
        $this->table('tgg_api_tokens')->drop()->save();
    }
}
