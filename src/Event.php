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
            $currentCount = (int)$appDb->query("SELECT COUNT(*) FROM tgg_volunteer_signups WHERE event_id = {$eventId}")->fetchColumn();
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
        $civiDb = Database::getCiviConnection();

        // Get volunteer rows from local database
        $stmt = $appDb->prepare("SELECT contact_id, role, signed_up_at FROM tgg_volunteer_signups WHERE event_id = :event_id ORDER BY signed_up_at ASC");
        $stmt->execute(['event_id' => $eventId]);
        $signups = $stmt->fetchAll();

        if (empty($signups)) {
            return [];
        }

        // Fetch display names from CiviCRM for these contact IDs
        $contactIds = array_column($signups, 'contact_id');
        $placeholders = implode(',', array_fill(0, count($contactIds), '?'));
        
        $civiStmt = $civiDb->prepare("SELECT id, display_name FROM civicrm_contact WHERE id IN ({$placeholders})");
        $civiStmt->execute($contactIds);
        $contacts = $civiStmt->fetchAll(PDO::FETCH_KEY_PAIR);

        // Map names back to signups list
        foreach ($signups as &$signup) {
            $cid = (int)$signup['contact_id'];
            $signup['display_name'] = $contacts[$cid] ?? "Member #{$cid}";
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
