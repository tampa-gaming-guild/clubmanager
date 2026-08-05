<?php
/**
 * Mobile API (host tools): check in another member, mirroring
 * host_checkin.php's core flow via the shared CheckinService. Requires
 * 'edit checkins'. Uses the host's wider 2-hour session window
 * (Event::getActiveSession()), not the 1-hour self-service window, and
 * doesn't suppress the pending-payment redirect -- the host is handling this
 * member in person, unlike the unaccompanied self-service kiosk.
 *
 * Not yet ported from host_checkin.php: in-person Trial activation for a
 * member with an unverified online Trial registration -- an edge case left
 * for a later pass; that member still gets routed to the renewal flow
 * instead of a flat denial.
 */
require_once (function() {
    $dir = dirname(dirname(dirname(__DIR__)));
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

ApiAuth::requirePermission('edit checkins');

if (Event::getActiveSession() === null) {
    json_response(['success' => false, 'error' => 'There is no session open for check-in right now.'], 409);
}

$body = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
$contactId = (int)($body['contact_id'] ?? 0);
if ($contactId <= 0) {
    json_response(['success' => false, 'error' => 'Invalid member selected.'], 400);
}

$notes = trim((string)($body['notes'] ?? 'Checked in by Host'));
$rawGuestNames = isset($body['guest_names']) && is_array($body['guest_names']) ? $body['guest_names'] : [];
$guestNames = CheckinService::sanitizeGuestNames($rawGuestNames);

$result = CheckinService::checkIn($contactId, $notes, $guestNames);

if ($result['redirect_reason']) {
    json_response(['success' => false, 'redirect_reason' => $result['redirect_reason']], 402);
} elseif ($result['error']) {
    json_response(['success' => false, 'error' => $result['error']], 400);
} else {
    json_response(['success' => true, 'message' => $result['message'], 'details' => $result['details']]);
}
