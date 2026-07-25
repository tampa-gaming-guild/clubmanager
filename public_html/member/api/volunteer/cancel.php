<?php
/**
 * Mobile API: cancel the caller's own volunteer signup, mirroring
 * volunteers.php's action_delete branch for the self-service (non-admin)
 * case only -- can only cancel signups dated today or later, same
 * restriction the web applies to a non-manage-hosting member. No email is
 * sent, matching the web: notifyRemoval only fires when someone *other* than
 * the volunteer removes the signup.
 */
require_once dirname(dirname(dirname(dirname(__DIR__)))) . '/config/bootstrap.php';

use App\ApiAuth;
use App\Event;
use App\EventSlot;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$user = ApiAuth::requireAuth();
$contactId = (int)$user['contact_id'];

$body = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
$slotId = (int)($body['slot_id'] ?? 0);
if ($slotId <= 0) {
    json_response(['success' => false, 'error' => 'Invalid volunteer slot.'], 400);
}

$slot = EventSlot::getSlot($slotId);
if (!$slot) {
    json_response(['success' => false, 'error' => 'That volunteer slot does not exist.'], 400);
}

$signup = Event::getSignupForSlot($slotId);
if (!$signup || (int)$signup['contact_id'] !== $contactId) {
    json_response(['success' => false, 'error' => 'You are not signed up for this slot.'], 403);
}

$event = Event::getEvent((int)$slot['event_id']);
if ($event && date('Y-m-d', strtotime($event['start_time'])) < date('Y-m-d')) {
    json_response(['success' => false, 'error' => 'You can only cancel signups dated today or later.'], 400);
}

Event::cancelVolunteer($slotId, $contactId);

json_response(['success' => true, 'message' => "Removed you from the {$slot['slot_label']} volunteer slot."]);
