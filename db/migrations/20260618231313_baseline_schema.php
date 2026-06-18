<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Baseline migration translating the pre-Phinx sql/schema.sql into versioned migrations.
 * Reference/seed data (roles, permissions, role_permissions, volunteer_credits,
 * email_templates, membership_statuses) is handled by db/seeds/, not here.
 *
 * On environments where this schema already exists (e.g. production, bootstrapped
 * directly from sql/schema.sql), this migration must be marked applied without running
 * up(), via `phinx migrate --target <this_version> --fake`. See README.md.
 */
final class BaselineSchema extends AbstractMigration
{
    // 'signed' => true matches schema.sql's plain `INT` (never `INT UNSIGNED`) so that
    // Phinx's implicit auto-increment `id` column's type lines up with FK columns
    // elsewhere, which are also plain signed integers.
    private const TABLE_OPTS = ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'signed' => true];

    public function up(): void
    {
        // 0a. Roles
        $this->table('tgg_roles', self::TABLE_OPTS)
            ->addColumn('name', 'string', ['limit' => 50, 'null' => false])
            ->addColumn('description', 'string', ['limit' => 255, 'null' => true])
            ->addIndex('name', ['unique' => true])
            ->create();

        // 0b. Permissions
        $this->table('tgg_permissions', self::TABLE_OPTS)
            ->addColumn('name', 'string', ['limit' => 50, 'null' => false])
            ->addColumn('description', 'string', ['limit' => 255, 'null' => true])
            ->addIndex('name', ['unique' => true])
            ->create();

        // 0c. Role <-> Permission mapping
        $this->table('tgg_role_permissions', self::TABLE_OPTS + ['id' => false, 'primary_key' => ['role_id', 'permission_id']])
            ->addColumn('role_id', 'integer', ['null' => false])
            ->addColumn('permission_id', 'integer', ['null' => false])
            ->addForeignKey('role_id', 'tgg_roles', 'id', ['delete' => 'CASCADE', 'constraint' => 'fk_role_permissions_role'])
            ->addForeignKey('permission_id', 'tgg_permissions', 'id', ['delete' => 'CASCADE', 'constraint' => 'fk_role_permissions_permission'])
            ->create();

        // 1. Member settings and credentials (links to civicrm_contact)
        $this->table('tgg_member_settings', self::TABLE_OPTS + ['id' => false, 'primary_key' => 'contact_id'])
            ->addColumn('contact_id', 'integer', ['null' => false])
            ->addColumn('password_hash', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('role', 'string', ['limit' => 50, 'null' => false, 'default' => 'member'])
            ->addColumn('custom_display_name', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('is_profile_public', 'boolean', ['null' => false, 'default' => true])
            ->addColumn('public_fields', 'text', ['null' => true])
            ->addColumn('credits_earned', 'float', ['null' => false, 'default' => 0.0])
            ->addColumn('credits_applied', 'float', ['null' => false, 'default' => 0.0])
            ->addColumn('expired_credits', 'float', ['null' => false, 'default' => 0.0])
            ->addColumn('failed_login_attempts', 'integer', ['null' => false, 'default' => 0])
            ->addColumn('locked_until', 'datetime', ['null' => true])
            ->addColumn('created_at', 'timestamp', ['null' => true, 'default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'timestamp', ['null' => true, 'default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->addForeignKey('role', 'tgg_roles', 'name', ['update' => 'CASCADE', 'constraint' => 'fk_member_settings_role'])
            ->create();

        // 1b. Member Roles Mapping Table
        $this->table('tgg_member_roles', self::TABLE_OPTS + ['id' => false, 'primary_key' => ['contact_id', 'role_name']])
            ->addColumn('contact_id', 'integer', ['null' => false])
            ->addColumn('role_name', 'string', ['limit' => 50, 'null' => false])
            ->addForeignKey('contact_id', 'tgg_member_settings', 'contact_id', ['delete' => 'CASCADE', 'constraint' => 'fk_member_roles_contact'])
            ->addForeignKey('role_name', 'tgg_roles', 'name', ['update' => 'CASCADE', 'constraint' => 'fk_member_roles_role'])
            ->create();

        // Trigger to automatically assign default role on settings creation (no Phinx DSL for triggers)
        $this->execute("
            CREATE TRIGGER `tgg_member_settings_after_insert`
            AFTER INSERT ON `tgg_member_settings`
            FOR EACH ROW
            BEGIN
              INSERT INTO `tgg_member_roles` (`contact_id`, `role_name`)
              VALUES (NEW.contact_id, NEW.role)
              ON DUPLICATE KEY UPDATE `role_name` = VALUES(`role_name`);
            END
        ");

        // 2. Member Check-ins (Attendance Log)
        $this->table('tgg_checkins', self::TABLE_OPTS)
            ->addColumn('contact_id', 'integer', ['null' => false])
            ->addColumn('checked_in_at', 'datetime', ['null' => false])
            ->addColumn('notes', 'text', ['null' => true])
            ->addIndex('contact_id', ['name' => 'idx_contact_checkin'])
            ->addIndex('checked_in_at', ['name' => 'idx_date_checkin'])
            ->create();

        // 3. Calendar Events / Sessions
        $this->table('tgg_events', self::TABLE_OPTS)
            ->addColumn('title', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('description', 'text', ['null' => true])
            ->addColumn('start_time', 'datetime', ['null' => false])
            ->addColumn('end_time', 'datetime', ['null' => false])
            ->addColumn('max_volunteers', 'integer', ['null' => false, 'default' => 0])
            ->addIndex(['start_time', 'end_time'], ['name' => 'idx_event_times'])
            ->create();

        // 4. Volunteer Signups for Events
        $this->table('tgg_volunteer_signups', self::TABLE_OPTS)
            ->addColumn('event_id', 'integer', ['null' => false])
            ->addColumn('contact_id', 'integer', ['null' => false])
            ->addColumn('role', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('signed_up_at', 'datetime', ['null' => false])
            ->addIndex(['event_id', 'contact_id', 'role'], ['unique' => true, 'name' => 'uq_event_contact_role'])
            ->addForeignKey('event_id', 'tgg_events', 'id', ['delete' => 'CASCADE', 'constraint' => 'fk_volunteer_event'])
            ->create();

        // 5. Subscription Plans (Local Options)
        $this->table('tgg_subscription_plans', self::TABLE_OPTS)
            ->addColumn('name', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('description', 'text', ['null' => true])
            ->addColumn('price', 'decimal', ['precision' => 20, 'scale' => 2, 'null' => false])
            ->addColumn('duration_unit', 'string', ['limit' => 20, 'null' => false, 'default' => 'year'])
            ->addColumn('duration_interval', 'integer', ['null' => false, 'default' => 1])
            ->addColumn('civicrm_membership_type_id', 'integer', ['null' => false])
            ->addColumn('active', 'string', ['limit' => 20, 'null' => false, 'default' => 'active'])
            ->addIndex('civicrm_membership_type_id', ['unique' => true, 'name' => 'uq_civicrm_membership_type'])
            ->create();

        // 6. Billing Transaction Ledger
        $this->table('tgg_billing_ledger', self::TABLE_OPTS)
            ->addColumn('contact_id', 'integer', ['null' => false])
            ->addColumn('plan_id', 'integer', ['null' => false])
            ->addColumn('stripe_session_id', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('payment_intent_id', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('amount', 'decimal', ['precision' => 20, 'scale' => 2, 'null' => false])
            ->addColumn('currency', 'string', ['limit' => 10, 'null' => false, 'default' => 'usd'])
            ->addColumn('payment_status', 'string', ['limit' => 50, 'null' => false])
            ->addColumn('action_type', 'string', ['limit' => 20, 'null' => false])
            ->addColumn('created_at', 'timestamp', ['null' => true, 'default' => 'CURRENT_TIMESTAMP'])
            ->addIndex('stripe_session_id', ['unique' => true, 'name' => 'uq_stripe_session'])
            ->addIndex('contact_id', ['name' => 'idx_contact_ledger'])
            ->addForeignKey('plan_id', 'tgg_subscription_plans', 'id', ['constraint' => 'fk_ledger_plan'])
            ->create();

        // 7. Local Member Subscriptions
        $this->table('tgg_subscriptions', self::TABLE_OPTS + ['id' => false, 'primary_key' => 'contact_id'])
            ->addColumn('contact_id', 'integer', ['null' => false])
            ->addColumn('plan_id', 'integer', ['null' => false])
            ->addColumn('status', 'string', ['limit' => 50, 'null' => false, 'default' => 'pending'])
            ->addColumn('join_date', 'date', ['null' => false])
            ->addColumn('start_date', 'date', ['null' => false])
            ->addColumn('end_date', 'date', ['null' => false])
            ->addColumn('updated_at', 'timestamp', ['null' => true, 'default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->addForeignKey('plan_id', 'tgg_subscription_plans', 'id', ['constraint' => 'fk_sub_plan'])
            ->create();

        // 8. Volunteer Credits Settings
        $this->table('tgg_volunteer_credits', self::TABLE_OPTS)
            ->addColumn('credit_key', 'string', ['limit' => 50, 'null' => false])
            ->addColumn('credit_label', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('credits', 'float', ['null' => false, 'default' => 1.0])
            ->addIndex('credit_key', ['unique' => true])
            ->create();

        // 9. Volunteer Credit Transactions Ledger
        $this->table('tgg_volunteer_credit_transactions', self::TABLE_OPTS)
            ->addColumn('contact_id', 'integer', ['null' => false])
            ->addColumn('event_id', 'integer', ['null' => true])
            ->addColumn('volunteer_date', 'date', ['null' => false])
            ->addColumn('shift', 'string', ['limit' => 50, 'null' => false])
            ->addColumn('credits_earned', 'float', ['null' => false, 'default' => 0.0])
            ->addColumn('credits_applied', 'float', ['null' => false, 'default' => 0.0])
            ->addColumn('created_at', 'timestamp', ['null' => true, 'default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['event_id', 'contact_id', 'shift'], ['unique' => true, 'name' => 'uq_event_contact_shift'])
            ->addIndex('contact_id', ['name' => 'idx_contact_credits'])
            ->create();

        // 10. Email Templates Table
        $this->table('tgg_email_templates', self::TABLE_OPTS + ['id' => false, 'primary_key' => 'template_key'])
            ->addColumn('template_key', 'string', ['limit' => 50, 'null' => false])
            ->addColumn('subject', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('body', 'text', ['null' => false])
            ->addColumn('description', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('updated_at', 'timestamp', ['null' => true, 'default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->create();

        // 11. Password Resets Table
        $this->table('tgg_password_resets', self::TABLE_OPTS + ['id' => false, 'primary_key' => 'email'])
            ->addColumn('email', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('token', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('expires_at', 'datetime', ['null' => false])
            ->addColumn('created_at', 'timestamp', ['null' => true, 'default' => 'CURRENT_TIMESTAMP'])
            ->addIndex('token', ['name' => 'idx_reset_token'])
            ->create();

        // 12. Email Log Table
        $this->table('tgg_email_log', self::TABLE_OPTS)
            ->addColumn('recipient_id', 'integer', ['null' => true])
            ->addColumn('sender_id', 'integer', ['null' => true])
            ->addColumn('recipient', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('subject', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('body', 'text', ['null' => false])
            ->addColumn('sent_at', 'timestamp', ['null' => true, 'default' => 'CURRENT_TIMESTAMP'])
            ->addIndex('recipient_id', ['name' => 'idx_recipient_id'])
            ->addIndex('sender_id', ['name' => 'idx_sender_id'])
            ->addIndex('sent_at', ['name' => 'idx_sent_at'])
            ->create();

        // 13. Contacts Table (Local storage for CiviCRM contact details)
        $this->table('tgg_contacts', self::TABLE_OPTS)
            ->addColumn('contact_type', 'string', ['limit' => 64, 'null' => true, 'default' => 'Individual'])
            ->addColumn('display_name', 'string', ['limit' => 128, 'null' => false])
            ->addColumn('first_name', 'string', ['limit' => 64, 'null' => true])
            ->addColumn('last_name', 'string', ['limit' => 64, 'null' => true])
            ->addColumn('email', 'string', ['limit' => 254, 'null' => false])
            ->addColumn('phone', 'string', ['limit' => 32, 'null' => true])
            ->addColumn('is_deleted', 'boolean', ['null' => true, 'default' => false])
            ->addColumn('created_at', 'timestamp', ['null' => true, 'default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'timestamp', ['null' => true, 'default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->addIndex('email', ['name' => 'idx_email'])
            ->create();
        $this->execute('ALTER TABLE `tgg_contacts` AUTO_INCREMENT = 100000');

        // 14. Membership Statuses Table (manually-assigned ids, not auto-increment)
        $this->table('tgg_membership_statuses', self::TABLE_OPTS + ['id' => false, 'primary_key' => 'id'])
            ->addColumn('id', 'integer', ['null' => false, 'identity' => false])
            ->addColumn('name', 'string', ['limit' => 128, 'null' => false])
            ->addColumn('label', 'string', ['limit' => 128, 'null' => false])
            ->addColumn('is_active', 'boolean', ['null' => true, 'default' => true])
            ->create();

        // 15. Trial Membership Verifications Table
        $this->table('tgg_trial_verifications', self::TABLE_OPTS + ['id' => false, 'primary_key' => 'contact_id'])
            ->addColumn('contact_id', 'integer', ['null' => false])
            ->addColumn('plan_id', 'integer', ['null' => false])
            ->addColumn('token', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('expires_at', 'datetime', ['null' => false])
            ->addColumn('created_at', 'timestamp', ['null' => true, 'default' => 'CURRENT_TIMESTAMP'])
            ->addIndex('token', ['name' => 'idx_trial_verification_token'])
            ->create();
    }

    public function down(): void
    {
        $this->execute('DROP TRIGGER IF EXISTS `tgg_member_settings_after_insert`');

        // Drop in reverse FK-dependency order
        $this->table('tgg_trial_verifications')->drop()->save();
        $this->table('tgg_membership_statuses')->drop()->save();
        $this->table('tgg_contacts')->drop()->save();
        $this->table('tgg_email_log')->drop()->save();
        $this->table('tgg_password_resets')->drop()->save();
        $this->table('tgg_email_templates')->drop()->save();
        $this->table('tgg_volunteer_credit_transactions')->drop()->save();
        $this->table('tgg_volunteer_credits')->drop()->save();
        $this->table('tgg_subscriptions')->drop()->save();
        $this->table('tgg_billing_ledger')->drop()->save();
        $this->table('tgg_subscription_plans')->drop()->save();
        $this->table('tgg_volunteer_signups')->drop()->save();
        $this->table('tgg_events')->drop()->save();
        $this->table('tgg_checkins')->drop()->save();
        $this->table('tgg_member_roles')->drop()->save();
        $this->table('tgg_member_settings')->drop()->save();
        $this->table('tgg_role_permissions')->drop()->save();
        $this->table('tgg_permissions')->drop()->save();
        $this->table('tgg_roles')->drop()->save();
    }
}
