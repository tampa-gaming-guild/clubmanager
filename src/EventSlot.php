<?php
namespace App;

use Exception;
use PDO;

/**
 * Per-event volunteer slot definitions.
 * Each event owns a set of named slots; slot_type (open/close/greeter) drives
 * credit calculation while slot_label is display-only. One volunteer per slot,
 * enforced by UNIQUE(slot_id) on tgg_volunteer_signups.
 */
class EventSlot {

    public const TYPES = ['open', 'close', 'greeter'];

    public const DEFAULT_SLOTS = [
        ['label' => 'Open',  'type' => 'open'],
        ['label' => 'Close', 'type' => 'close'],
    ];

    /**
     * Slots for one event, in display order.
     */
    public static function getSlotsForEvent(int $eventId): array {
        $appDb = Database::getAppConnection();
        $stmt = $appDb->prepare("
            SELECT id, event_id, slot_label, slot_type, sort_order
            FROM tgg_event_slots
            WHERE event_id = :event_id
            ORDER BY sort_order ASC, id ASC
        ");
        $stmt->execute(['event_id' => $eventId]);
        return $stmt->fetchAll();
    }

    /**
     * Batched slot lookup: [event_id => [slot rows in display order]].
     */
    public static function getSlotsForEvents(array $eventIds): array {
        $eventIds = array_values(array_unique(array_map('intval', $eventIds)));
        if (empty($eventIds)) {
            return [];
        }

        $appDb = Database::getAppConnection();
        $placeholders = implode(',', array_fill(0, count($eventIds), '?'));
        $stmt = $appDb->prepare("
            SELECT id, event_id, slot_label, slot_type, sort_order
            FROM tgg_event_slots
            WHERE event_id IN ($placeholders)
            ORDER BY sort_order ASC, id ASC
        ");
        $stmt->execute($eventIds);

        $byEvent = [];
        foreach ($stmt->fetchAll() as $row) {
            $byEvent[(int)$row['event_id']][] = $row;
        }
        return $byEvent;
    }

    /**
     * Fetch a single slot.
     */
    public static function getSlot(int $slotId): ?array {
        $appDb = Database::getAppConnection();
        $stmt = $appDb->prepare("
            SELECT id, event_id, slot_label, slot_type, sort_order
            FROM tgg_event_slots
            WHERE id = :id LIMIT 1
        ");
        $stmt->execute(['id' => $slotId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Normalize and validate a slot definition list from a form:
     * [['id' => int|null, 'label' => string, 'type' => string], ...]
     * Throws a user-safe 423 Exception on any problem.
     */
    public static function validateSlots(array $slots): array {
        $clean = [];
        $seenLabels = [];

        foreach ($slots as $slot) {
            $label = trim((string)($slot['label'] ?? ''));
            $type = strtolower(trim((string)($slot['type'] ?? '')));
            $id = isset($slot['id']) && $slot['id'] !== '' ? (int)$slot['id'] : null;

            if ($label === '') {
                throw new Exception("Every volunteer slot needs a name.", 423);
            }
            if (mb_strlen($label) > 100) {
                throw new Exception("Slot name \"{$label}\" is too long (100 characters max).", 423);
            }
            if (!in_array($type, self::TYPES, true)) {
                throw new Exception("Slot \"{$label}\" has an invalid type.", 423);
            }

            $labelKey = mb_strtolower($label);
            if (isset($seenLabels[$labelKey])) {
                throw new Exception("Duplicate slot name \"{$label}\" -- slot names must be unique per event.", 423);
            }
            $seenLabels[$labelKey] = true;

            $clean[] = ['id' => $id, 'label' => $label, 'type' => $type];
        }

        if (empty($clean)) {
            throw new Exception("An event needs at least one volunteer slot.", 423);
        }
        if (count($clean) > 20) {
            throw new Exception("An event can have at most 20 volunteer slots.", 423);
        }

        return $clean;
    }

    /**
     * Reconcile an event's slots against a submitted definition list (create + edit).
     * Renames are label-only updates (signups reference slots by id); removing a slot
     * that has a signup is refused -- the signup must be cancelled first.
     * Joins the caller's transaction when one is open.
     */
    public static function setSlots(int $eventId, array $slots): void {
        $slots = self::validateSlots($slots);

        $appDb = Database::getAppConnection();
        $ownTransaction = !$appDb->inTransaction();
        if ($ownTransaction) {
            $appDb->beginTransaction();
        }

        try {
            $existing = [];
            foreach (self::getSlotsForEvent($eventId) as $row) {
                $existing[(int)$row['id']] = $row;
            }

            $keptIds = array_values(array_filter(array_column($slots, 'id'), fn($id) => $id !== null && isset($existing[$id])));

            // Deletes first, so a kept slot can take over a removed slot's label
            // without tripping the unique(event_id, slot_label) index.
            $removedIds = array_values(array_diff(array_keys($existing), $keptIds));
            if (!empty($removedIds)) {
                $placeholders = implode(',', array_fill(0, count($removedIds), '?'));
                $checkStmt = $appDb->prepare("
                    SELECT sl.slot_label
                    FROM tgg_volunteer_signups s
                    JOIN tgg_event_slots sl ON sl.id = s.slot_id
                    WHERE s.slot_id IN ($placeholders)
                ");
                $checkStmt->execute($removedIds);
                $filledLabels = $checkStmt->fetchAll(PDO::FETCH_COLUMN);
                if (!empty($filledLabels)) {
                    throw new Exception(
                        "Cannot remove slot \"" . implode('", "', $filledLabels) . "\" -- a volunteer is signed up. Cancel the signup on the Volunteers page first.",
                        423
                    );
                }

                $deleteStmt = $appDb->prepare("DELETE FROM tgg_event_slots WHERE event_id = ? AND id IN ($placeholders)");
                $deleteStmt->execute(array_merge([$eventId], $removedIds));
            }

            $updateStmt = $appDb->prepare("
                UPDATE tgg_event_slots
                SET slot_label = :label, slot_type = :type, sort_order = :sort_order
                WHERE id = :id AND event_id = :event_id
            ");
            $insertStmt = $appDb->prepare("
                INSERT INTO tgg_event_slots (event_id, slot_label, slot_type, sort_order)
                VALUES (:event_id, :label, :type, :sort_order)
            ");
            foreach ($slots as $sortOrder => $slot) {
                if ($slot['id'] !== null && isset($existing[$slot['id']])) {
                    $updateStmt->execute([
                        'label' => $slot['label'],
                        'type' => $slot['type'],
                        'sort_order' => $sortOrder,
                        'id' => $slot['id'],
                        'event_id' => $eventId
                    ]);
                } else {
                    $insertStmt->execute([
                        'event_id' => $eventId,
                        'label' => $slot['label'],
                        'type' => $slot['type'],
                        'sort_order' => $sortOrder
                    ]);
                }
            }

            if ($ownTransaction) {
                $appDb->commit();
            }
        } catch (\PDOException $e) {
            if ($ownTransaction && $appDb->inTransaction()) {
                $appDb->rollBack();
            }
            // Two kept slots swapping labels collide transiently on the unique index.
            if ($e->getCode() == 23000) {
                throw new Exception("Slot names conflict -- rename one slot at a time and save between changes.", 423);
            }
            throw $e;
        } catch (Exception $e) {
            if ($ownTransaction && $appDb->inTransaction()) {
                $appDb->rollBack();
            }
            throw $e;
        }
    }

    /**
     * The single credit-key mapping: slot type + event day => tgg_volunteer_credits key.
     */
    public static function creditKey(?string $slotType, string $eventStartTime): string {
        $type = in_array($slotType, self::TYPES, true) ? $slotType : 'open';
        $isSunday = (date('w', strtotime($eventStartTime)) == 0);
        return ($isSunday ? 'sunday_' : 'weekday_') . $type;
    }
}
