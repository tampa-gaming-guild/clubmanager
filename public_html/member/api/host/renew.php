<?php
/**
 * Mobile API (host tools): record an immediate renewal for a member --
 * either charging their card on file (payment_method: 'card_on_file') or a
 * cash payment the host is directly confirming (payment_method: 'cash') --
 * wrapping BillingHelper::processOfflineRenewal(). Mirrors renew.php's
 * simplified admin "Renew Membership" section: always the standard
 * plan-based period, always honoring whatever level was picked (no separate
 * duration/level-change modes). A Stripe Checkout redirect (for a member
 * with no card on file) is the separate host/checkout-session.php endpoint
 * instead, since that's a redirect flow, not an immediate result.
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$user = ApiAuth::requirePermission('edit checkins');

$body = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
$contactId = (int)($body['contact_id'] ?? 0);
$planId = (int)($body['plan_id'] ?? 0);
$paymentMethod = (string)($body['payment_method'] ?? '');

if ($contactId <= 0 || $planId <= 0 || !in_array($paymentMethod, ['cash', 'card_on_file'], true)) {
    json_response(['success' => false, 'error' => 'Invalid request.'], 400);
}

// Cash / Card on File are host-renewing-someone-else options only, same
// restriction renew.php's web UI enforces -- a host renewing their OWN
// membership only ever gets Stripe Checkout.
if ($contactId === (int)$user['contact_id']) {
    json_response(['success' => false, 'error' => 'Use Pay with Card via Checkout to renew your own membership.'], 403);
}

try {
    BillingHelper::processOfflineRenewal($contactId, $planId, $paymentMethod, 'renew', 'change_level', 'standard');

    $membership = BillingHelper::getMemberSubscriptionDetails($contactId) ?? MembershipService::getMemberMembershipDetails($contactId);

    json_response([
        'success' => true,
        'message' => $paymentMethod === 'card_on_file' ? 'Card on file charged and renewal recorded.' : 'Cash renewal recorded.',
        'membership' => $membership,
    ]);
} catch (Exception $e) {
    json_response(['success' => false, 'error' => safe_err('Failed to process renewal: ', $e)], 400);
}
