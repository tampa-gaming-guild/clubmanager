<?php
/**
 * Mobile API: the caller's own guest-pass allowance/remaining for the
 * current calendar month, mirroring checkin.php's ?action=guest_status
 * lookup for a logged-in member. Used by CheckInScreen to decide whether to
 * show a "bring a guest?" toggle before checking in.
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
use App\BillingHelper;
use App\MembershipService;

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['error' => 'Method not allowed'], 405);
}

$user = ApiAuth::requireAuth();
$contactId = (int)$user['contact_id'];

$result = ['allowance' => 0, 'used' => 0, 'remaining' => 0];
$membership = MembershipService::getMemberMembershipDetails($contactId);
if ($membership && $membership['is_active']) {
    $result = BillingHelper::getGuestPassesRemaining($contactId, $membership);
}

json_response($result);
