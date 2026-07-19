<?php
namespace App;

use Exception;

/**
 * Shared volunteer signup/cancel/confirm POST handling, used by volunteers.php.
 *
 * Any logged-in member can now sign up for an open slot. A self-signup by
 * someone who doesn't already hold the 'volunteer' permission lands as
 * 'pending' (still occupying the slot) until a majordomo confirms it via
 * action_approve -- see Event::signupVolunteer()/approveVolunteerSignup().
 * A manage-hosting user assigning someone else always auto-confirms, since
 * they're vouching for the person.
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
        $actorId = (int)($_SESSION['user']['contact_id'] ?? 0);

        if (isset($post['action_signup'])) {
            try {
                if (empty($slotId)) {
                    throw new Exception("Slot is required.");
                }
                if (empty($contactId)) {
                    throw new Exception("Member selection is required.");
                }

                $isPrivileged = has_permission('manage hosting');
                // If not manage hosting, force contactId to the logged-in user
                if (!$isPrivileged) {
                    $contactId = $actorId;
                }

                $status = ($isPrivileged || has_permission('volunteer')) ? 'confirmed' : 'pending';

                $slot = Event::signupVolunteer($slotId, $contactId, $status);

                $displayName = self::contactDisplayName($contactId);

                $successMsg = $status === 'pending'
                    ? "Signed up {$displayName} as {$slot['slot_label']} volunteer -- pending confirmation from a Hosting Manager."
                    : "Success! Signed up {$displayName} as {$slot['slot_label']} volunteer.";

                self::notifySignup([$slot], $contactId, $actorId, $status);
            } catch (Exception $e) {
                $errorMsg = safe_err("Volunteer signup failed: ", $e);
            }
        } elseif (isset($post['action_signup_all'])) {
            try {
                if (empty($contactId)) {
                    throw new Exception("Member selection is required.");
                }

                $isPrivileged = has_permission('manage hosting');
                if (!$isPrivileged) {
                    $contactId = $actorId;
                }

                $status = ($isPrivileged || has_permission('volunteer')) ? 'confirmed' : 'pending';

                $results = Event::signupVolunteerAllOpenSlots($eventId, $contactId, $status);

                $displayName = self::contactDisplayName($contactId);

                $signedUp = array_column(array_filter($results, fn($r) => $r['success']), 'role');
                $failed = array_filter($results, fn($r) => !$r['success']);

                if (empty($results)) {
                    $errorMsg = "All volunteer roles for this event are already filled.";
                } elseif (empty($failed)) {
                    $successMsg = $status === 'pending'
                        ? "Signed up {$displayName} as " . implode(', ', $signedUp) . " volunteer -- pending confirmation from a Hosting Manager."
                        : "Success! Signed up {$displayName} as " . implode(', ', $signedUp) . " volunteer.";
                } else {
                    $errorMsg = "Could not fill: " . implode('; ', array_map(fn($r) => "{$r['role']} ({$r['error']})", $failed));
                    if (!empty($signedUp)) {
                        $successMsg = "Signed up {$displayName} as " . implode(', ', $signedUp) . ".";
                    }
                }

                if (!empty($signedUp)) {
                    $filledSlots = array_map(
                        fn($r) => ['event_id' => $eventId, 'slot_label' => $r['role']],
                        array_values(array_filter($results, fn($r) => $r['success']))
                    );
                    self::notifySignup($filledSlots, $contactId, $actorId, $status);
                }
            } catch (Exception $e) {
                $errorMsg = safe_err("Bulk volunteer signup failed: ", $e);
            }
        } elseif (isset($post['action_approve'])) {
            try {
                if (empty($slotId)) {
                    throw new Exception("Slot is required.");
                }
                if (!has_permission('manage hosting')) {
                    throw new Exception("You do not have permission to confirm volunteer signups.");
                }

                $result = Event::approveVolunteerSignup($slotId, $actorId);
                $volunteerContactId = $result['contact_id'];

                self::grantHostRoleIfNeeded($volunteerContactId);

                AuditLog::log('volunteer', 'signup_approved', [
                    'slot_id' => $slotId,
                    'slot_label' => $result['slot_label']
                ], $volunteerContactId, $actorId);

                self::notifyApproval($result, $actorId);

                $displayName = self::contactDisplayName($volunteerContactId);
                $successMsg = "Confirmed {$displayName} as {$result['slot_label']} volunteer.";
            } catch (Exception $e) {
                $errorMsg = safe_err("Failed to confirm volunteer signup: ", $e);
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
                $isSelf = ($contactId === $actorId);

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

                $existingSignup = Event::getSignupForSlot($slotId);

                Event::cancelVolunteer($slotId, $contactId);

                $displayName = self::contactDisplayName($contactId);

                $successMsg = "Success! Removed {$displayName} from {$slot['slot_label']} volunteer slot.";

                if (!$isSelf && $existingSignup) {
                    AuditLog::log('volunteer', 'signup_removed_by_other', [
                        'slot_id' => $slotId,
                        'slot_label' => $slot['slot_label'],
                        'prior_status' => $existingSignup['status']
                    ], $contactId, $actorId);

                    self::notifyRemoval($slot, $contactId, $actorId, $existingSignup['status']);
                }
            } catch (Exception $e) {
                $errorMsg = safe_err("Failed to delete volunteer signup: ", $e);
            }
        }

        return ['success' => $successMsg, 'error' => $errorMsg];
    }

    /**
     * Sends the appropriate email(s) after a signup: to the volunteer + every
     * majordomo when pending, or to the volunteer alone when a manage-hosting
     * user assigned someone else (and that assignment is auto-confirmed).
     * $slots: [['event_id' => int, 'slot_label' => string], ...] -- all slots
     * are assumed to belong to the same event (true for both call sites).
     */
    private static function notifySignup(array $slots, int $contactId, int $actorId, string $status): void {
        if (empty($slots)) {
            return;
        }
        $event = Event::getEvent((int)$slots[0]['event_id']);
        if (!$event) {
            return;
        }

        $volunteer = self::getContactInfo($contactId);
        if (empty($volunteer['email'])) {
            return;
        }

        $slotLabels = implode(', ', array_column($slots, 'slot_label'));
        $eventDate = date('F d, Y (l)', strtotime($event['start_time']));
        $eventLink = self::eventLink($event);

        if ($status === 'pending') {
            self::sendVolunteerMail($volunteer['email'], 'volunteer_signup_pending', [
                'display_name' => $volunteer['display_name'],
                'slot_label' => $slotLabels,
                'event_title' => $event['title'],
                'event_date' => $eventDate,
            ], $contactId, $actorId);

            foreach (Auth::getContactsWithRole('majordomo') as $majordomo) {
                if (empty($majordomo['email'])) {
                    continue;
                }
                self::sendVolunteerMail($majordomo['email'], 'volunteer_signup_needs_confirmation', [
                    'display_name' => $majordomo['display_name'],
                    'volunteer_name' => $volunteer['display_name'],
                    'slot_label' => $slotLabels,
                    'event_title' => $event['title'],
                    'event_date' => $eventDate,
                    'event_link' => $eventLink,
                    'volunteer_email' => $volunteer['email'],
                    'volunteer_phone' => $volunteer['phone'] !== '' ? $volunteer['phone'] : 'Not provided',
                ], (int)$majordomo['id'], $actorId);
            }
        } elseif ($actorId !== $contactId) {
            $actor = self::getContactInfo($actorId);
            self::sendVolunteerMail($volunteer['email'], 'volunteer_slot_assigned', [
                'display_name' => $volunteer['display_name'],
                'slot_label' => $slotLabels,
                'event_title' => $event['title'],
                'event_date' => $eventDate,
                'actor_name' => $actor['display_name'],
                'event_link' => $eventLink,
            ], $contactId, $actorId);
        }
    }

    /**
     * Confirmation email sent to the volunteer once a majordomo approves their
     * pending signup.
     */
    private static function notifyApproval(array $approval, int $resolverContactId): void {
        $slot = EventSlot::getSlot($approval['slot_id']);
        if (!$slot) {
            return;
        }
        $event = Event::getEvent((int)$slot['event_id']);
        if (!$event) {
            return;
        }

        $volunteer = self::getContactInfo($approval['contact_id']);
        if (empty($volunteer['email'])) {
            return;
        }
        $resolver = self::getContactInfo($resolverContactId);

        self::sendVolunteerMail($volunteer['email'], 'volunteer_signup_confirmed', [
            'display_name' => $volunteer['display_name'],
            'slot_label' => $slot['slot_label'],
            'event_title' => $event['title'],
            'event_date' => date('F d, Y (l)', strtotime($event['start_time'])),
            'resolver_name' => $resolver['display_name'],
        ], $approval['contact_id'], $resolverContactId);
    }

    /**
     * Sent when a manage-hosting user removes someone else's signup -- the
     * wording depends on whether it had already been confirmed.
     */
    private static function notifyRemoval(array $slot, int $contactId, int $actorId, string $priorStatus): void {
        $event = Event::getEvent((int)$slot['event_id']);
        if (!$event) {
            return;
        }
        $volunteer = self::getContactInfo($contactId);
        if (empty($volunteer['email'])) {
            return;
        }
        $actor = self::getContactInfo($actorId);

        $templateKey = $priorStatus === 'pending' ? 'volunteer_signup_not_confirmed' : 'volunteer_slot_removed';

        self::sendVolunteerMail($volunteer['email'], $templateKey, [
            'display_name' => $volunteer['display_name'],
            'slot_label' => $slot['slot_label'],
            'event_title' => $event['title'],
            'event_date' => date('F d, Y (l)', strtotime($event['start_time'])),
            'actor_name' => $actor['display_name'],
        ], $contactId, $actorId);
    }

    /**
     * Grants the 'host' role (skipping the pending step on future self-signups)
     * unless the contact already holds a role that carries 'volunteer'. Mirrors
     * the plain insert roles.php itself uses -- a contact can hold multiple roles.
     */
    private static function grantHostRoleIfNeeded(int $contactId): void {
        $appDb = Database::getAppConnection();

        $stmt = $appDb->prepare("
            SELECT COUNT(*)
            FROM tgg_member_roles mr
            JOIN tgg_roles r ON r.name = mr.role_name
            JOIN tgg_role_permissions rp ON rp.role_id = r.id
            JOIN tgg_permissions p ON p.id = rp.permission_id
            WHERE mr.contact_id = :contact_id AND p.name IN ('volunteer', 'all')
        ");
        $stmt->execute(['contact_id' => $contactId]);
        if ((int)$stmt->fetchColumn() > 0) {
            return;
        }

        $insert = $appDb->prepare("INSERT IGNORE INTO tgg_member_roles (contact_id, role_name) VALUES (:contact_id, 'host')");
        $insert->execute(['contact_id' => $contactId]);

        AuditLog::log('roles', 'role_granted', [
            'role' => 'host',
            'reason' => 'volunteer_signup_approved'
        ], $contactId);
    }

    private static function sendVolunteerMail(string $to, string $templateKey, array $placeholders, ?int $recipientId, ?int $senderId): void {
        try {
            MailHelper::sendTemplate($to, $templateKey, $placeholders, $recipientId, $senderId);
        } catch (Exception $e) {
            error_log("Failed to send {$templateKey} volunteer email: " . $e->getMessage());
        }
    }

    private static function eventLink(array $event): string {
        $base = rtrim($_ENV['BASE_URL'] ?? 'http://localhost/member', '/');
        return $base . '/volunteers.php?selected=' . date('Y-m-d', strtotime($event['start_time']));
    }

    private static function getContactInfo(int $contactId): array {
        $appDb = Database::getAppConnection();
        $stmt = $appDb->prepare("SELECT display_name, email, phone FROM tgg_contacts WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $contactId]);
        $row = $stmt->fetch();
        return [
            'display_name' => $row['display_name'] ?? "Member #{$contactId}",
            'email' => $row['email'] ?? '',
            'phone' => $row['phone'] ?? '',
        ];
    }

    private static function contactDisplayName(int $contactId): string {
        $appDb = Database::getAppConnection();
        $stmt = $appDb->prepare("SELECT display_name FROM tgg_contacts WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $contactId]);
        return $stmt->fetchColumn() ?: "Member #{$contactId}";
    }
}
