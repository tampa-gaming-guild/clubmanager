<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Replaces the hardcoded Open/Close volunteer roles with per-event slot definitions.
 *
 * - New tgg_event_slots: each event defines its own named slots, where slot_type
 *   (open/close/greeter) drives credit calculation and slot_label is display-only.
 * - tgg_volunteer_signups now references a slot by FK instead of carrying free-text
 *   role + event_id. UNIQUE(slot_id) enforces one volunteer per slot and doubles as
 *   the race guard; ON DELETE RESTRICT makes deleting a filled slot impossible at
 *   the DB level (event deletion therefore removes signups first -- see
 *   Event::deleteEvent()).
 * - tgg_volunteer_credit_transactions stays a denormalized history ledger (shift
 *   keeps the label snapshot) but gains a nullable slot_id so processed-signup
 *   matching no longer depends on label text.
 * - tgg_events.max_volunteers is dropped: capacity is now the number of slots.
 */
final class EventSlotsRestructure extends AbstractMigration
{
    private const TABLE_OPTS = ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'signed' => true];

    public function up(): void
    {
        // 1. Per-event slot definitions
        $this->table('tgg_event_slots', self::TABLE_OPTS)
            ->addColumn('event_id', 'integer', ['null' => false])
            ->addColumn('slot_label', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('slot_type', 'enum', ['values' => ['open', 'close', 'greeter'], 'null' => false, 'default' => 'open'])
            ->addColumn('sort_order', 'integer', ['null' => false, 'default' => 0])
            ->addIndex(['event_id', 'slot_label'], ['unique' => true, 'name' => 'uq_event_slot_label'])
            ->addForeignKey('event_id', 'tgg_events', 'id', ['delete' => 'CASCADE', 'constraint' => 'fk_slot_event'])
            ->create();

        // 2. Every existing event gets the standard Open/Close slots...
        $this->execute("
            INSERT INTO tgg_event_slots (event_id, slot_label, slot_type, sort_order)
            SELECT id, 'Open', 'open', 0 FROM tgg_events
        ");
        $this->execute("
            INSERT INTO tgg_event_slots (event_id, slot_label, slot_type, sort_order)
            SELECT id, 'Close', 'close', 1 FROM tgg_events
        ");
        // ...plus a slot for any signup role that doesn't match one (e.g. legacy 'Greeter').
        $this->execute("
            INSERT INTO tgg_event_slots (event_id, slot_label, slot_type, sort_order)
            SELECT DISTINCT s.event_id, s.role,
                   CASE LOWER(s.role) WHEN 'close' THEN 'close' WHEN 'greeter' THEN 'greeter' ELSE 'open' END,
                   2
            FROM tgg_volunteer_signups s
            LEFT JOIN tgg_event_slots sl ON sl.event_id = s.event_id AND sl.slot_label = s.role
            WHERE sl.id IS NULL
        ");

        // 3. Repoint signups at slots. Dedupe first: only a check-then-insert race could
        // have produced two people on the same (event, role); keep the earliest signup.
        $this->execute("
            DELETE s1 FROM tgg_volunteer_signups s1
            JOIN tgg_volunteer_signups s2
              ON s1.event_id = s2.event_id AND s1.role = s2.role
             AND (s1.signed_up_at > s2.signed_up_at
                  OR (s1.signed_up_at = s2.signed_up_at AND s1.id > s2.id))
        ");

        $this->table('tgg_volunteer_signups')
            ->addColumn('slot_id', 'integer', ['null' => true, 'after' => 'id'])
            ->update();

        $this->execute("
            UPDATE tgg_volunteer_signups s
            JOIN tgg_event_slots sl ON sl.event_id = s.event_id AND sl.slot_label = s.role
            SET s.slot_id = sl.id
        ");

        // Step 2's orphan backfill guarantees every signup matched a slot.
        $this->table('tgg_volunteer_signups')
            ->changeColumn('slot_id', 'integer', ['null' => false])
            ->addIndex('slot_id', ['unique' => true, 'name' => 'uq_signup_slot'])
            ->addForeignKey('slot_id', 'tgg_event_slots', 'id', ['delete' => 'RESTRICT', 'constraint' => 'fk_signup_slot'])
            ->update();

        // FK first: MySQL requires an index on event_id while the FK exists, and
        // uq_event_contact_role is the index backing it.
        $this->table('tgg_volunteer_signups')
            ->dropForeignKey('event_id')
            ->update();
        $this->table('tgg_volunteer_signups')
            ->removeIndexByName('uq_event_contact_role')
            ->removeColumn('event_id')
            ->removeColumn('role')
            ->update();

        // 4. Credit ledger: precise processed-signup matching via slot_id (nullable --
        // synthetic rows like 'Apply Extension' have no slot, and history must survive
        // slot/event deletion).
        $this->table('tgg_volunteer_credit_transactions')
            ->addColumn('slot_id', 'integer', ['null' => true, 'after' => 'event_id'])
            ->addForeignKey('slot_id', 'tgg_event_slots', 'id', ['delete' => 'SET_NULL', 'constraint' => 'fk_credit_tx_slot'])
            ->update();

        $this->execute("
            UPDATE tgg_volunteer_credit_transactions t
            JOIN tgg_event_slots sl ON sl.event_id = t.event_id AND sl.slot_label = t.shift
            SET t.slot_id = sl.id
        ");

        // 5. Capacity is now structural (one person per slot).
        $this->table('tgg_events')
            ->removeColumn('max_volunteers')
            ->update();
    }

    public function down(): void
    {
        $this->table('tgg_events')
            ->addColumn('max_volunteers', 'integer', ['null' => false, 'default' => 0, 'after' => 'end_time'])
            ->update();

        $this->table('tgg_volunteer_credit_transactions')
            ->dropForeignKey('slot_id')
            ->removeColumn('slot_id')
            ->update();

        // Restore denormalized event_id/role on signups from the slot rows.
        $this->table('tgg_volunteer_signups')
            ->addColumn('event_id', 'integer', ['null' => true, 'after' => 'id'])
            ->addColumn('role', 'string', ['limit' => 100, 'null' => true, 'after' => 'contact_id'])
            ->update();

        $this->execute("
            UPDATE tgg_volunteer_signups s
            JOIN tgg_event_slots sl ON sl.id = s.slot_id
            SET s.event_id = sl.event_id, s.role = sl.slot_label
        ");

        $this->table('tgg_volunteer_signups')
            ->changeColumn('event_id', 'integer', ['null' => false])
            ->changeColumn('role', 'string', ['limit' => 100, 'null' => false])
            ->addIndex(['event_id', 'contact_id', 'role'], ['unique' => true, 'name' => 'uq_event_contact_role'])
            ->addForeignKey('event_id', 'tgg_events', 'id', ['delete' => 'CASCADE', 'constraint' => 'fk_volunteer_event'])
            ->update();

        $this->table('tgg_volunteer_signups')
            ->removeIndexByName('uq_signup_slot')
            ->dropForeignKey('slot_id')
            ->update();
        $this->table('tgg_volunteer_signups')
            ->removeColumn('slot_id')
            ->update();

        $this->table('tgg_event_slots')->drop()->save();
    }
}
