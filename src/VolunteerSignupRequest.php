<?php
namespace App;

use Exception;

/**
 * Shared volunteer signup/cancel POST handling, used by both volunteers.php and
 * calendar.php's selected-day panel. Callers still build their own page-specific
 * PRG redirect after calling handle() -- the two pages redirect to different URLs.
 */
class VolunteerSignupRequest {

    /**
     * @param array $post $_POST
     * @return array{success: ?string, error: ?string}
     */
    public static function handle(array $post): array {
        $successMsg = null;
        $errorMsg = null;

        if (!verify_csrf_token($post['csrf_token'] ?? '')) {
            return ['success' => null, 'error' => "Invalid security token."];
        }

        $eventId = (int)($post['event_id'] ?? 0);
        $contactId = (int)($post['contact_id'] ?? 0);
        $slotId = (int)($post['slot_id'] ?? 0);

        if (isset($post['action_signup'])) {
            try {
                if (empty($slotId)) {
                    throw new Exception("Slot is required.");
                }
                if (empty($contactId)) {
                    throw new Exception("Member selection is required.");
                }

                if (!has_permission('volunteer')) {
                    throw new Exception("You do not have permission to sign up as a volunteer.");
                }
                // If not manage hosting, force contactId to the logged-in user
                if (!has_permission('manage hosting')) {
                    $contactId = $_SESSION['user']['contact_id'];
                }

                $slot = Event::signupVolunteer($slotId, $contactId);

                // Fetch member name to display in the success message
                $appDb = Database::getAppConnection();
                $stmtName = $appDb->prepare("SELECT display_name FROM tgg_contacts WHERE id = :id LIMIT 1");
                $stmtName->execute(['id' => $contactId]);
                $displayName = $stmtName->fetchColumn() ?: "Member #{$contactId}";

                $successMsg = "Success! Signed up {$displayName} as {$slot['slot_label']} volunteer.";
            } catch (Exception $e) {
                $errorMsg = safe_err("Volunteer signup failed: ", $e);
            }
        } elseif (isset($post['action_signup_all'])) {
            try {
                if (empty($contactId)) {
                    throw new Exception("Member selection is required.");
                }

                if (!has_permission('volunteer')) {
                    throw new Exception("You do not have permission to sign up as a volunteer.");
                }
                // If not manage hosting, force contactId to the logged-in user
                if (!has_permission('manage hosting')) {
                    $contactId = $_SESSION['user']['contact_id'];
                }

                $results = Event::signupVolunteerAllOpenSlots($eventId, $contactId);

                $appDb = Database::getAppConnection();
                $stmtName = $appDb->prepare("SELECT display_name FROM tgg_contacts WHERE id = :id LIMIT 1");
                $stmtName->execute(['id' => $contactId]);
                $displayName = $stmtName->fetchColumn() ?: "Member #{$contactId}";

                $signedUp = array_column(array_filter($results, fn($r) => $r['success']), 'role');
                $failed = array_filter($results, fn($r) => !$r['success']);

                if (empty($results)) {
                    $errorMsg = "All volunteer roles for this event are already filled.";
                } elseif (empty($failed)) {
                    $successMsg = "Success! Signed up {$displayName} as " . implode(', ', $signedUp) . " volunteer.";
                } else {
                    $errorMsg = "Could not fill: " . implode('; ', array_map(fn($r) => "{$r['role']} ({$r['error']})", $failed));
                    if (!empty($signedUp)) {
                        $successMsg = "Signed up {$displayName} as " . implode(', ', $signedUp) . ".";
                    }
                }
            } catch (Exception $e) {
                $errorMsg = safe_err("Bulk volunteer signup failed: ", $e);
            }
        } elseif (isset($post['action_delete'])) {
            try {
                if (empty($slotId)) {
                    throw new Exception("Slot is required.");
                }
                if (empty($contactId)) {
                    throw new Exception("Member ID is required.");
                }

                $isAdmin = has_permission('manage hosting');
                $isSelf = ($contactId === (int)$_SESSION['user']['contact_id']);

                if (!$isAdmin && !$isSelf) {
                    throw new Exception("You are not authorized to delete this signup.");
                }

                $slot = EventSlot::getSlot($slotId);
                if (!$slot) {
                    throw new Exception("Slot not found.");
                }

                if (!$isAdmin) {
                    $event = Event::getEvent((int)$slot['event_id']);
                    if (!$event) {
                        throw new Exception("Event not found.");
                    }
                    $eventDate = date('Y-m-d', strtotime($event['start_time']));
                    $today = date('Y-m-d');
                    if ($eventDate < $today) {
                        throw new Exception("Members can only delete signups dated today or later.");
                    }
                }

                Event::cancelVolunteer($slotId, $contactId);

                $appDb = Database::getAppConnection();
                $stmtName = $appDb->prepare("SELECT display_name FROM tgg_contacts WHERE id = :id LIMIT 1");
                $stmtName->execute(['id' => $contactId]);
                $displayName = $stmtName->fetchColumn() ?: "Member #{$contactId}";

                $successMsg = "Success! Removed {$displayName} from {$slot['slot_label']} volunteer slot.";
            } catch (Exception $e) {
                $errorMsg = safe_err("Failed to delete volunteer signup: ", $e);
            }
        }

        return ['success' => $successMsg, 'error' => $errorMsg];
    }
}
