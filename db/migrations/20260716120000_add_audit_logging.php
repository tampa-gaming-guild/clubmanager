<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Audit logging: a central tgg_audit_log table for config/security/governance
 * events, plus denormalized actor columns (created_by / impersonator_id / source)
 * on the two financial ledgers so payment views can show who caused each row
 * without a join to the audit log.
 *
 * Actor semantics: created_by is the acting session identity; impersonator_id is
 * the real admin when the action happened under "Login As" (NULL otherwise);
 * source is web|stripe|cron|import. created_by NULL + source 'cron' means the
 * autorenew job; NULL/NULL is a pre-audit legacy row.
 */
final class AddAuditLogging extends AbstractMigration
{
    private const TABLE_OPTS = ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'signed' => true];

    public function up(): void
    {
        // No FKs on the contact-id columns: contacts are soft-deleted and the
        // existing actor precedent (tgg_pending_payments.resolved_by) is a plain
        // nullable integer too. Audit rows must outlive everything they reference.
        $this->table('tgg_audit_log', self::TABLE_OPTS)
            ->addColumn('category', 'string', ['limit' => 30, 'null' => false])   // security|roles|rates|volunteer_config|membership|import
            ->addColumn('action', 'string', ['limit' => 50, 'null' => false])
            ->addColumn('actor_contact_id', 'integer', ['null' => true])
            ->addColumn('impersonator_contact_id', 'integer', ['null' => true])
            ->addColumn('target_contact_id', 'integer', ['null' => true])
            ->addColumn('source', 'string', ['limit' => 10, 'null' => false, 'default' => 'web'])
            ->addColumn('details', 'text', ['null' => true])                      // JSON payload
            ->addColumn('ip_address', 'string', ['limit' => 45, 'null' => true])
            ->addColumn('created_at', 'timestamp', ['null' => true, 'default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['category', 'created_at'], ['name' => 'idx_audit_category_created'])
            ->addIndex(['actor_contact_id'], ['name' => 'idx_audit_actor'])
            ->addIndex(['target_contact_id'], ['name' => 'idx_audit_target'])
            ->addIndex(['created_at'], ['name' => 'idx_audit_created'])
            ->create();

        $this->table('tgg_billing_ledger')
            ->addColumn('created_by', 'integer', ['null' => true, 'default' => null, 'after' => 'action_type'])
            ->addColumn('impersonator_id', 'integer', ['null' => true, 'default' => null, 'after' => 'created_by'])
            ->addColumn('source', 'string', ['limit' => 10, 'null' => true, 'default' => null, 'after' => 'impersonator_id'])
            ->addIndex(['created_by'], ['name' => 'idx_ledger_created_by'])
            ->update();

        $this->table('tgg_volunteer_credit_transactions')
            ->addColumn('created_by', 'integer', ['null' => true, 'default' => null, 'after' => 'credits_applied'])
            ->addColumn('impersonator_id', 'integer', ['null' => true, 'default' => null, 'after' => 'created_by'])
            ->addColumn('source', 'string', ['limit' => 10, 'null' => true, 'default' => null, 'after' => 'impersonator_id'])
            ->update();

        // Best-effort source backfill from the synthetic payment-ID prefixes the
        // app already writes. Actor identity is unrecoverable for legacy rows
        // (stays NULL = unknown), except Stripe checkouts where the payer is by
        // definition the member on the row.
        $this->execute("UPDATE tgg_billing_ledger SET source = 'cron'   WHERE stripe_session_id LIKE 'autorenew\\_%'");
        $this->execute("UPDATE tgg_billing_ledger SET source = 'import' WHERE stripe_session_id LIKE 'civi\\_contrib\\_%'");
        $this->execute("UPDATE tgg_billing_ledger SET source = 'web'    WHERE stripe_session_id LIKE 'offline\\_%' OR stripe_session_id LIKE 'trial\\_%'");
        $this->execute("UPDATE tgg_billing_ledger SET source = 'stripe', created_by = contact_id WHERE stripe_session_id LIKE 'cs\\_%'");
    }

    public function down(): void
    {
        $this->table('tgg_volunteer_credit_transactions')
            ->removeColumn('source')
            ->removeColumn('impersonator_id')
            ->removeColumn('created_by')
            ->update();

        $this->table('tgg_billing_ledger')
            ->removeIndexByName('idx_ledger_created_by')
            ->removeColumn('source')
            ->removeColumn('impersonator_id')
            ->removeColumn('created_by')
            ->update();

        $this->table('tgg_audit_log')->drop()->save();
    }
}
