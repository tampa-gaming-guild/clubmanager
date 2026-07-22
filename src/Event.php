<?php
namespace App;

use Exception;
use PDO;

/**
 * Event and Volunteer Management
 * Handles scheduling events, retrieving schedules, and volunteer signups.
 * Volunteer capacity is structural: each event defines named slots (see EventSlot)
 * and every slot holds exactly one volunteer.
 */
class Event {

    /**
     * Create a new event with its volunteer slots. Returns the new event id.
     */
    public static function createEvent(string $title, string $description, string $startTime, string $endTime, ?array $slots = null): int {
        self::validateEventFields($title, $startTime, $endTime);

        $appDb = Database::getAppConnection();
        $ownTransaction = !$appDb->inTransaction();
        if ($ownTransaction) {
            $appDb->beginTransaction();
        }

        try {
            $stmt = $appDb->prepare("
                INSERT INTO tgg_events (title, description, start_time, end_time)
                VALUES (:title, :description, :start_time, :end_time)
            ");
            $stmt->execute([
                'title' => $title,
                'description' => $description,
                'start_time' => $startTime,
                'end_time' => $endTime
            ]);
            $eventId = (int)$appDb->lastInsertId();

            EventSlot::setSlots($eventId, $slots ?? EventSlot::DEFAULT_SLOTS);

            if ($ownTransaction) {
                $appDb->commit();
            }
            return $eventId;
        } catch (Exception $e) {
            if ($ownTransaction && $appDb->inTransaction()) {
                $appDb->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Update an event's details and reconcile its volunteer slots.
     */
    public static function updateEvent(int $eventId, string $title, string $description, string $startTime, string $endTime, array $slots): void {
        self::validateEventFields($title, $startTime, $endTime);

        if (!self::getEvent($eventId)) {
            throw new Exception("Event not found.", 423);
        }

        $appDb = Database::getAppConnection();
        $ownTransaction = !$appDb->inTransaction();
        if ($ownTransaction) {
            $appDb->beginTransaction();
        }

        try {
            $stmt = $appDb->prepare("
                UPDATE tgg_events
                SET title = :title, description = :description, start_time = :start_time, end_time = :end_time
                WHERE id = :id
            ");
            $stmt->execute([
                'title' => $title,
                'description' => $description,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'id' => $eventId
            ]);

            EventSlot::setSlots($eventId, $slots);

            if ($ownTransaction) {
                $appDb->commit();
            }
        } catch (Exception $e) {
            if ($ownTransaction && $appDb->inTransaction()) {
                $appDb->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Delete an event. Signups must go first (their FK RESTRICTs slot deletion);
     * slots then cascade with the event row.
     */
    public static function deleteEvent(int $eventId): void {
        $appDb = Database::getAppConnection();
        $ownTransaction = !$appDb->inTransaction();
        if ($ownTransaction) {
            $appDb->beginTransaction();
        }

        try {
            $stmt = $appDb->prepare("
                DELETE s FROM tgg_volunteer_signups s
                JOIN tgg_event_slots sl ON sl.id = s.slot_id
                WHERE sl.event_id = :event_id
            ");
            $stmt->execute(['event_id' => $eventId]);

            $stmt = $appDb->prepare("DELETE FROM tgg_events WHERE id = :id");
            $stmt->execute(['id' => $eventId]);

            if ($ownTransaction) {
                $appDb->commit();
            }
        } catch (Exception $e) {
            if ($ownTransaction && $appDb->inTransaction()) {
                $appDb->rollBack();
            }
            throw $e;
        }
    }

    private static function validateEventFields(string $title, string $startTime, string $endTime): void {
        if (empty($title) || empty($startTime) || empty($endTime)) {
            throw new Exception("Title, start time, and end time are required.");
        }

        if (strtotime($startTime) >= strtotime($endTime)) {
            throw new Exception("Start time must be before end time.");
        }
    }

    /**
     * Fetch scheduled events in a range
     */
    public static function getEvents(?string $start = null, ?string $end = null): array {
        $appDb = Database::getAppConnection();

        $sql = "SELECT id, title, description, start_time, end_time FROM tgg_events";
        $params = [];

        if ($start && $end) {
            $sql .= " WHERE start_time >= :start AND start_time <= :end";
            $params = ['start' => $start, 'end' => $end];
        }

        $sql .= " ORDER BY start_time ASC";
        $stmt = $appDb->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Fetch a single event's details
     */
    public static function getEvent(int $id): ?array {
        $appDb = Database::getAppConnection();
        $stmt = $appDb->prepare("SELECT id, title, description, start_time, end_time FROM tgg_events WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Get the currently active session event, if any.
     * "Active" = today's event where NOW() is within 2hrs before start_time
     * through end_time -- hosts need to set up and members start arriving
     * well before the official start time.
     */
    public static function getActiveSession(): ?array {
        $appDb = Database::getAppConnection();
        $stmt = $appDb->prepare("
            SELECT id, title, description, start_time, end_time
            FROM tgg_events
            WHERE DATE(start_time) = CURDATE()
              AND NOW() >= DATE_SUB(start_time, INTERVAL 2 HOUR)
              AND NOW() <= end_time
            ORDER BY start_time ASC
            LIMIT 1
        ");
        $stmt->execute();
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Whether check-in is currently allowed: a session is scheduled today and
     * NOW() is within 1hr before its start_time through its end_time.
     */
    public static function isCheckinWindowOpen(): bool {
        $appDb = Database::getAppConnection();
        $stmt = $appDb->prepare("
            SELECT COUNT(*) FROM tgg_events
            WHERE DATE(start_time) = CURDATE()
              AND NOW() >= DATE_SUB(start_time, INTERVAL 1 HOUR)
              AND NOW() <= end_time
        ");
        $stmt->execute();
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Get today's check-ins (same shape/order as the admin check-in list) for hosting dashboard widgets.
     */
    public static function getTodaysCheckins(): array {
        $appDb = Database::getAppConnection();
        $stmt = $appDb->prepare("
            SELECT c.id AS checkin_id, c.contact_id, c.checked_in_at, c.notes, c.guest_name,
                   con.display_name, con.first_name, con.last_name
            FROM tgg_checkins c
            LEFT JOIN tgg_contacts con ON con.id = c.contact_id
            WHERE DATE(c.checked_in_at) = CURDATE()
            ORDER BY COALESCE(NULLIF(con.first_name, ''), con.display_name) ASC, con.last_name ASC
        ");
        $stmt->execute();
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            if (empty($row['display_name'])) {
                $row['display_name'] = "Member #{$row['contact_id']}";
            }
        }
        return $rows;
    }

    /**
     * Register a volunteer for a slot. Returns the slot row on success.
     * UNIQUE(slot_id) makes the insert the race guard: whoever commits first
     * gets the slot, everyone else lands in the 23000 handler.
     * $status is 'confirmed' unless the caller determined this signup needs a
     * majordomo's confirmation first (self-signup by someone without the
     * 'volunteer' permission) -- see VolunteerSignupRequest.
     */
    public static function signupVolunteer(int $slotId, int $contactId, string $status = 'confirmed'): array {
        $appDb = Database::getAppConnection();

        $slot = EventSlot::getSlot($slotId);
        if (!$slot) {
            // Code 423 marks this as a deliberate, user-safe message (see safe_err()).
            throw new Exception("That volunteer slot does not exist.", 423);
        }

        try {
            $stmt = $appDb->prepare("
                INSERT INTO tgg_volunteer_signups (slot_id, contact_id, status, signed_up_at)
                VALUES (:slot_id, :contact_id, :status, NOW())
            ");
            $stmt->execute([
                'slot_id' => $slotId,
                'contact_id' => $contactId,
                'status' => $status
            ]);
        } catch (\PDOException $e) {
            if ($e->getCode() == 23000) {
                throw new Exception("The \"{$slot['slot_label']}\" slot has already been filled.", 423);
            }
            throw $e;
        }

        return $slot;
    }

    /**
     * Sign a contact up for every open slot on an event, capturing per-slot results.
     */
    public static function signupVolunteerAllOpenSlots(int $eventId, int $contactId, string $status = 'confirmed'): array {
        $appDb = Database::getAppConnection();
        $stmt = $appDb->prepare("
            SELECT sl.id, sl.slot_label
            FROM tgg_event_slots sl
            LEFT JOIN tgg_volunteer_signups s ON s.slot_id = sl.id
            WHERE sl.event_id = :event_id AND s.id IS NULL
            ORDER BY sl.sort_order ASC, sl.id ASC
        ");
        $stmt->execute(['event_id' => $eventId]);
        $openSlots = $stmt->fetchAll();

        $results = [];
        foreach ($openSlots as $slot) {
            try {
                self::signupVolunteer((int)$slot['id'], $contactId, $status);
                $results[] = ['slot_id' => (int)$slot['id'], 'role' => $slot['slot_label'], 'success' => true];
            } catch (Exception $e) {
                $results[] = ['slot_id' => (int)$slot['id'], 'role' => $slot['slot_label'], 'success' => false, 'error' => safe_err('Signup failed: ', $e)];
            }
        }
        return $results;
    }

    /**
     * Cancel a volunteer signup
     */
    public static function cancelVolunteer(int $slotId, int $contactId): bool {
        $appDb = Database::getAppConnection();
        $stmt = $appDb->prepare("DELETE FROM tgg_volunteer_signups WHERE slot_id = :slot_id AND contact_id = :contact_id");
        return $stmt->execute([
            'slot_id' => $slotId,
            'contact_id' => $contactId
        ]);
    }

    /**
     * The current signup (if any) for a slot -- contact_id + status. Used before
     * cancelling (to pick the right notification email) and by approveVolunteerSignup().
     */
    public static function getSignupForSlot(int $slotId): ?array {
        $appDb = Database::getAppConnection();
        $stmt = $appDb->prepare("SELECT contact_id, status FROM tgg_volunteer_signups WHERE slot_id = :slot_id LIMIT 1");
        $stmt->execute(['slot_id' => $slotId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Confirm a pending volunteer signup (majordomo action). Idempotency guard
     * mirrors BillingHelper::approvePendingPayment.
     * @return array{slot_id: int, contact_id: int, slot_label: string} for the caller's email/audit log
     * @throws Exception
     */
    public static function approveVolunteerSignup(int $slotId, int $resolverContactId): array {
        $appDb = Database::getAppConnection();

        $slot = EventSlot::getSlot($slotId);
        if (!$slot) {
            throw new Exception("That volunteer slot does not exist.", 423);
        }

        $signup = self::getSignupForSlot($slotId);
        if (!$signup) {
            throw new Exception("There is no signup for this slot.", 423);
        }
        if ($signup['status'] !== 'pending') {
            throw new Exception("This signup has already been confirmed.", 423);
        }

        $stmt = $appDb->prepare("
            UPDATE tgg_volunteer_signups
            SET status = 'confirmed', resolved_at = NOW(), resolved_by = :resolved_by
            WHERE slot_id = :slot_id
        ");
        $stmt->execute(['resolved_by' => $resolverContactId, 'slot_id' => $slotId]);

        return [
            'slot_id' => $slotId,
            'contact_id' => (int)$signup['contact_id'],
            'slot_label' => $slot['slot_label']
        ];
    }

    /**
     * Get list of slot labels a member has signed up for on an event
     */
    public static function getMemberRolesForEvent(int $eventId, int $contactId): array {
        $appDb = Database::getAppConnection();
        $stmt = $appDb->prepare("
            SELECT sl.slot_label
            FROM tgg_volunteer_signups s
            JOIN tgg_event_slots sl ON sl.id = s.slot_id
            WHERE sl.event_id = :event_id AND s.contact_id = :contact_id
            ORDER BY sl.sort_order ASC, sl.id ASC
        ");
        $stmt->execute([
            'event_id' => $eventId,
            'contact_id' => $contactId
        ]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Get list of signed up volunteers for an event (including CiviCRM contact names)
     */
    public static function getVolunteers(int $eventId): array {
        $appDb = Database::getAppConnection();

        // Get volunteer rows from local database
        $stmt = $appDb->prepare("
            SELECT s.contact_id, s.slot_id, s.status, sl.slot_label, sl.slot_type, s.signed_up_at
            FROM tgg_volunteer_signups s
            JOIN tgg_event_slots sl ON sl.id = s.slot_id
            WHERE sl.event_id = :event_id
            ORDER BY s.signed_up_at ASC
        ");
        $stmt->execute(['event_id' => $eventId]);
        $signups = $stmt->fetchAll();

        if (empty($signups)) {
            return [];
        }

        // Fetch display names for these contact IDs according to privacy preferences
        $contactIds = array_column($signups, 'contact_id');
        $formattedNames = MembershipService::getFormattedNames($contactIds);

        // Map names back to signups list
        foreach ($signups as &$signup) {
            $cid = (int)$signup['contact_id'];
            $signup['display_name'] = $formattedNames[$cid] ?? "Member #{$cid}";
        }

        return $signups;
    }

    /**
     * Check if a member is signed up for a specific event
     */
    public static function isSignedUp(int $eventId, int $contactId): bool {
        $appDb = Database::getAppConnection();
        $stmt = $appDb->prepare("
            SELECT s.id
            FROM tgg_volunteer_signups s
            JOIN tgg_event_slots sl ON sl.id = s.slot_id
            WHERE sl.event_id = :event_id AND s.contact_id = :contact_id
            LIMIT 1
        ");
        $stmt->execute([
            'event_id' => $eventId,
            'contact_id' => $contactId
        ]);
        return (bool)$stmt->fetch();
    }
}
