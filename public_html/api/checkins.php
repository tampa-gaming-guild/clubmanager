<?php
/**
 * Mobile API: self check-in -- either manual (member taps "Check In" in the
 * app) or triggered automatically by BLE beacon detection. Always requires a
 * valid access token: the contact checked in is the authenticated caller,
 * never a submitted identifier -- there is no anonymous/kiosk flow here.
 *
 * No geolocation is accepted or used. Unlike the web kiosk's GPS geofence,
 * proximity here is established by the phone's BLE beacon detection before
 * this endpoint is ever called; this endpoint does not re-derive or cache
 * any location from the device.
 */
require_once (function() {
    $dir = dirname(dirname(__DIR__));
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
use App\CheckinService;
use App\Event;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$user = ApiAuth::requireAuth();
$contactId = (int)$user['contact_id'];

if (!Event::isCheckinWindowOpen()) {
    // 'code' is what the mobile app keys on, and it matters more than it looks:
    // this is the one refusal here that stops being true on its own. Every other
    // outcome is settled for the day, but a member who arrives before the window
    // opens should still be checked in automatically once it does, so the app
    // must be able to tell this apart from a real rejection without matching on
    // the English text below. Do not drop or rename it -- see
    // BeaconBackground.runCheckIn() in the tgg-mobile repo.
    json_response([
        'success' => false,
        'code' => 'no_session_open',
        'error' => 'There is no session open for check-in right now. Check-in opens 1 hour before a scheduled session begins.',
    ], 409);
}

$body = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
$notes = trim((string)($body['notes'] ?? 'Regular Visit'));
$rawGuestNames = isset($body['guest_names']) && is_array($body['guest_names']) ? $body['guest_names'] : [];
$guestNames = CheckinService::sanitizeGuestNames($rawGuestNames);

$result = CheckinService::checkIn($contactId, $notes, $guestNames, suppressRedirectIfPendingPayment: true);

if ($result['redirect_reason']) {
    // The app decides how to prompt for payment; there's no page to redirect to.
    json_response(['success' => false, 'redirect_reason' => $result['redirect_reason']], 402);
} elseif ($result['error']) {
    json_response(['success' => false, 'error' => $result['error']], 400);
} else {
    json_response(['success' => true, 'message' => $result['message'], 'details' => $result['details']]);
}
