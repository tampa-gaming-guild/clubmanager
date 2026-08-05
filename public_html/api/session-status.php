<?php
/**
 * Mobile API: lightweight status check the bottom-nav shell calls once per
 * login/relaunch to decide which tabs to show (Check-In, Hosting) and which
 * tab opens by default. Works for any authenticated member -- unlike
 * host/dashboard.php, which requires 'edit checkins' and 403s without it.
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
use App\Event;

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['error' => 'Method not allowed'], 405);
}

$user = ApiAuth::requireAuth();
$contactId = (int)$user['contact_id'];
$permissions = $user['permissions'] ?? [];
$canEditCheckins = in_array('all', $permissions, true) || in_array('edit checkins', $permissions, true);

$activeSession = Event::getActiveSession();
$isHostingNow = $activeSession !== null && !empty(Event::getMemberRolesForEvent((int)$activeSession['id'], $contactId));

json_response([
    'is_checkin_window_open' => Event::isCheckinWindowOpen(),
    'active_session' => $activeSession ? [
        'title' => $activeSession['title'],
        'start_time' => $activeSession['start_time'],
        'end_time' => $activeSession['end_time'],
    ] : null,
    // Same looser-than-web rule as host/dashboard.php: any 'edit checkins'
    // holder can reach the Hosting tab once a session is active, not just
    // someone signed up as today's specific volunteer/host -- that's what
    // is_hosting_now is for instead, which the shell uses only to decide
    // whether Hosting is the *default* tab (an actual signed-up host should
    // land there automatically; an admin who merely holds the permission
    // shouldn't be dropped into it uninvited).
    'can_host' => $activeSession !== null && $canEditCheckins,
    'is_hosting_now' => $isHostingNow,
]);
