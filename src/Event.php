<?php
namespace App;

use Exception;
use PDO;

/**
 * Event and Volunteer Management
 * Handles scheduling events, retrieving schedules, and volunteer signups.
 */
class Event {

    /**
     * Create a new event
     */
    public static function createEvent(string $title, string $description, string $startTime, string $endTime, int $maxVolunteers): bool {
        if (empty($title) || empty($startTime) || empty($endTime)) {
            throw new Exception("Title, start time, and end time are required.");
        }

        if (strtotime($startTime) >= strtotime($endTime)) {
            throw new Exception("Start time must be before end time.");
        }

        $appDb = Database::getAppConnection();
        $stmt = $appDb->prepare("
            INSERT INTO tgg_events (title, description, start_time, end_time, max_volunteers) 
            VALUES (:title, :description, :start_time, :end_time, :max_volunteers)
        ");
        return $stmt->execute([
            'title' => $title,
            'description' => $description,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'max_volunteers' => $maxVolunteers
        ]);
    }

    /**
     * Fetch scheduled events in a range
     */
    public static function getEvents(string $start = null, string $end = null): array {
        $appDb = Database::getAppConnection();
        
        $sql = "SELECT id, title, description, start_time, end_time, max_volunteers FROM tgg_events";
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
        $stmt = $appDb->prepare("SELECT id, title, description, start_time, end_time, max_volunteers FROM tgg_events WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Get the currently active session event, if any.
     * "Active" = today's event where NOW() is within 1hr before start_time
     * through end_time.
     */
    public static function getActiveSession(): ?array {
        $appDb = Database::getAppConnection();
        $stmt = $appDb->prepare("
            SELECT id, title, description, start_time, end_time, max_volunteers
            FROM tgg_events
            WHERE DATE(start_time) = CURDATE()
              AND NOW() >= DATE_SUB(start_time, INTERVAL 1 HOUR)
              AND NOW() <= end_time
            ORDER BY start_time ASC
            LIMIT 1
        ");
        $stmt->execute();
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Get today's check-ins (same shape/order as the admin check-in list) for hosting dashboard widgets.
     */
    public static function getTodaysCheckins(): array {
        $appDb = Database::getAppConnection();
        $stmt = $appDb->prepare("
            SELECT c.id AS checkin_id, c.contact_id, c.checked_in_at, c.notes,
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
     * Register a volunteer for an event
     */
    public static function signupVolunteer(int $eventId, int $contactId, string $role): bool {
        $appDb = Database::getAppConnection();

        // Check if event exists
        $event = self::getEvent($eventId);
        if (!$event) {
            throw new Exception("Event not found.");
        }

        // Check capacity
        if ($event['max_volunteers'] > 0) {
            $countStmt = $appDb->prepare("SELECT COUNT(*) FROM tgg_volunteer_signups WHERE event_id = :event_id");
            $countStmt->execute(['event_id' => $eventId]);
            $currentCount = (int)$countStmt->fetchColumn();
            if ($currentCount >= $event['max_volunteers']) {
                throw new Exception("Volunteer capacity reached for this event.");
            }
        }

        $stmt = $appDb->prepare("
            INSERT INTO tgg_volunteer_signups (event_id, contact_id, role, signed_up_at) 
            VALUES (:event_id, :contact_id, :role, NOW())
        ");
        return $stmt->execute([
            'event_id' => $eventId,
            'contact_id' => $contactId,
            'role' => $role
        ]);
    }

    /**
     * Sign a contact up for every role in $roles that isn't already taken,
     * skipping ones that are filled and capturing per-role failures (e.g. capacity).
     */
    public static function signupVolunteerAllOpenRoles(int $eventId, int $contactId, array $roles): array {
        $vols = self::getVolunteers($eventId);
        $takenRoles = array_column($vols, 'role');

        $results = [];
        foreach ($roles as $role) {
            if (in_array($role, $takenRoles, true)) {
                continue;
            }
            try {
                self::signupVolunteer($eventId, $contactId, $role);
                $results[] = ['role' => $role, 'success' => true];
            } catch (Exception $e) {
                $results[] = ['role' => $role, 'success' => false, 'error' => $e->getMessage()];
            }
        }
        return $results;
    }

    /**
     * Cancel a volunteer signup
     */
    public static function cancelVolunteer(int $eventId, int $contactId, string $role = null): bool {
        $appDb = Database::getAppConnection();
        if ($role) {
            $stmt = $appDb->prepare("DELETE FROM tgg_volunteer_signups WHERE event_id = :event_id AND contact_id = :contact_id AND role = :role");
            return $stmt->execute([
                'event_id' => $eventId,
                'contact_id' => $contactId,
                'role' => $role
            ]);
        } else {
            $stmt = $appDb->prepare("DELETE FROM tgg_volunteer_signups WHERE event_id = :event_id AND contact_id = :contact_id");
            return $stmt->execute([
                'event_id' => $eventId,
                'contact_id' => $contactId
            ]);
        }
    }

    /**
     * Get list of roles a member has signed up for on an event
     */
    public static function getMemberRolesForEvent(int $eventId, int $contactId): array {
        $appDb = Database::getAppConnection();
        $stmt = $appDb->prepare("SELECT role FROM tgg_volunteer_signups WHERE event_id = :event_id AND contact_id = :contact_id");
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
        $stmt = $appDb->prepare("SELECT contact_id, role, signed_up_at FROM tgg_volunteer_signups WHERE event_id = :event_id ORDER BY signed_up_at ASC");
        $stmt->execute(['event_id' => $eventId]);
        $signups = $stmt->fetchAll();

        if (empty($signups)) {
            return [];
        }

        // Fetch display names from CiviCRM for these contact IDs according to privacy preferences
        $contactIds = array_column($signups, 'contact_id');
        $formattedNames = CiviCRMImporter::getFormattedNames($contactIds);

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
        $stmt = $appDb->prepare("SELECT id FROM tgg_volunteer_signups WHERE event_id = :event_id AND contact_id = :contact_id LIMIT 1");
        $stmt->execute([
            'event_id' => $eventId,
            'contact_id' => $contactId
        ]);
        return (bool)$stmt->fetch();
    }
}
