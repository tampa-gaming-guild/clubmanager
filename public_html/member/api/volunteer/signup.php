<?php
/**
 * Mobile API: self-signup for an open volunteer slot, mirroring
 * volunteers.php's action_signup branch for the "myself" case only -- no
 * admin "assign another member" or "sign up for all slots" flow here, mobile
 * self-service only. A signup from someone without the 'volunteer' (or
 * 'manage hosting') permission lands as 'pending' until a majordomo confirms
 * it, same as the web.
 */
require_once (function() {
    $dir = dirname(dirname(dirname(dirname(__DIR__))));
    if (file_exists($dir . '/.env') && $lines = @file($dir . '/.env')) {
        foreach ($lines as $line) {
            if (preg_match('/^\s*BOOTSTRAP_PATH\s*=\s*["\']?(.*?)["\']?\s*$/', $line, $m)) {
                return $m[1];
            }
        }
    }
    return $dir . '/config/bootstrap.php';
})();

use App\ApiAuth;
use App\Event;
use App\VolunteerSignupRequest;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$user = ApiAuth::requireAuth();
$contactId = (int)$user['contact_id'];
$permissions = $user['permissions'] ?? [];
$isConfirmed = in_array('all', $permissions, true) || in_array('manage hosting', $permissions, true) || in_array('volunteer', $permissions, true);

$body = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
$slotId = (int)($body['slot_id'] ?? 0);
if ($slotId <= 0) {
    json_response(['success' => false, 'error' => 'Invalid volunteer slot.'], 400);
}

$status = $isConfirmed ? 'confirmed' : 'pending';

try {
    $slot = Event::signupVolunteer($slotId, $contactId, $status);
    VolunteerSignupRequest::notifySelfSignup($slot, $contactId, $status);
    json_response([
        'success' => true,
        'status' => $status,
        'message' => $status === 'pending'
            ? "Signed up as {$slot['slot_label']} volunteer -- pending confirmation from a Hosting Manager."
            : "Signed up as {$slot['slot_label']} volunteer.",
    ]);
} catch (Exception $e) {
    json_response(['success' => false, 'error' => safe_err('Volunteer signup failed: ', $e)], 400);
}
