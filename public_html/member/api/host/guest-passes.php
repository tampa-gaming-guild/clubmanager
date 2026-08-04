<?php
/**
 * Mobile API (host tools): guest-pass allowance/remaining for a specific
 * member, mirroring host_checkin.php's ?action=guest_status lookup. Used by
 * the host's check-in confirmation panel once a search result is selected,
 * before deciding whether to show guest-name fields. Requires
 * 'edit checkins', same as the rest of host/*.php.
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
use App\BillingHelper;
use App\MembershipService;

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['error' => 'Method not allowed'], 405);
}

ApiAuth::requirePermission('edit checkins');

$contactId = (int)($_GET['contact_id'] ?? 0);

$result = ['allowance' => 0, 'used' => 0, 'remaining' => 0];
if ($contactId > 0) {
    $membership = MembershipService::getMemberMembershipDetails($contactId);
    if ($membership && $membership['is_active']) {
        $result = BillingHelper::getGuestPassesRemaining($contactId, $membership);
    }
}

json_response($result);
