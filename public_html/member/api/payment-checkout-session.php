<?php
/**
 * Mobile API: create a Stripe Checkout session for the caller's OWN
 * check-in-blocking entrance fee or renewal -- mirrors pay-entrance.php's
 * card action. Success/cancel point at pay-entrance.php (reused purely as
 * the payment-processing callback -- it needs no PHP session for that step
 * -- the app never shows its page content, just watches the return URL and
 * closes the webview). For a renewal into a session-billed plan, this
 * activates that membership for free and returns pivoted_to_entrance_fee
 * instead of a checkout_url (see PaymentFlow::resolveRenewalCharge).
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

$user = ApiAuth::requireAuth();
$contactId = (int)$user['contact_id'];

$body = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
$reason = ($body['reason'] ?? '') === 'entrance_fee' ? 'entrance_fee' : 'renewal';
$tierId = (int)($body['tier_id'] ?? 0);

if (PaymentFlow::hasPendingPayment($contactId)) {
    json_response(['error' => 'You already have a pending payment with the host.'], 409);
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
        ['reason' => $context['reason'], 'return' => 'checkin.php']
    );

    json_response(['pivoted_to_entrance_fee' => false, 'checkout_url' => $session['url']]);
} catch (Exception $e) {
    json_response(['error' => safe_err('Failed to start card checkout: ', $e)], 400);
}
