<?php
/**
 * Mobile API (host tools): create a Stripe Checkout session for a member
 * the host is checking in -- mirrors pay-entrance.php's card action.
 * Success/cancel point at pay-entrance.php purely as the payment-processing
 * callback (needs no PHP session for that step); the app never shows its
 * page content, just watches the return URL and closes the webview.
 * api/payment-checkout-session.php is the self-service equivalent.
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
use App\Database;
use App\PaymentFlow;
use App\StripeHelper;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

ApiAuth::requirePermission('edit checkins');

$body = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
$contactId = (int)($body['contact_id'] ?? 0);
$reason = ($body['reason'] ?? '') === 'entrance_fee' ? 'entrance_fee' : 'renewal';
$tierId = (int)($body['tier_id'] ?? 0);

if ($contactId <= 0) {
    json_response(['error' => 'Invalid member.'], 400);
}
if (PaymentFlow::hasPendingPayment($contactId)) {
    json_response(['error' => 'This member already has a pending payment.'], 409);
}

try {
    if ($reason === 'entrance_fee') {
        $context = PaymentFlow::entranceFeeContext($contactId);
    } else {
        if ($tierId <= 0) {
            json_response(['error' => 'Please select a membership level.'], 400);
        }
        $context = PaymentFlow::resolveRenewalCharge($contactId, $tierId);
        if ($context['pivoted_to_entrance_fee']) {
            json_response([
                'pivoted_to_entrance_fee' => true,
                'amount' => $context['amount'],
                'membership_name' => $context['membership_name'],
            ]);
        }
    }

    $appDb = Database::getAppConnection();
    $contactStmt = $appDb->prepare("SELECT display_name, email FROM tgg_contacts WHERE id = :id AND is_deleted = 0 LIMIT 1");
    $contactStmt->execute(['id' => $contactId]);
    $contact = $contactStmt->fetch();
    if (!$contact) {
        json_response(['error' => 'Member not found.'], 404);
    }

    $session = StripeHelper::createCheckoutSession(
        $contactId,
        $context['plan_id'],
        $context['civicrm_type_id'],
        $context['membership_name'],
        $context['amount'],
        $context['reason'] === 'entrance_fee' ? 'entrance_fee' : 'renew',
        $contact['email'],
        $contact['display_name'],
        'pay-entrance.php',
        ['reason' => $context['reason'], 'return' => 'host_checkin.php']
    );

    json_response(['pivoted_to_entrance_fee' => false, 'checkout_url' => $session['url']]);
} catch (Exception $e) {
    json_response(['error' => safe_err('Failed to start card checkout: ', $e)], 400);
}
