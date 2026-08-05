<?php
/**
 * Mobile API: request a cash payment for the caller's OWN check-in-blocking
 * entrance fee or renewal -- creates a tgg_pending_payments row awaiting
 * host approval, mirroring pay-entrance.php's cash action. For a renewal
 * into a session-billed plan, this activates that membership for free and
 * pends today's entrance fee instead (see PaymentFlow::resolveRenewalCharge).
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
use App\BillingHelper;
use App\PaymentFlow;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$user = ApiAuth::requireAuth();
$contactId = (int)$user['contact_id'];

$body = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
$reason = ($body['reason'] ?? '') === 'entrance_fee' ? 'entrance_fee' : 'renewal';
$tierId = (int)($body['tier_id'] ?? 0);

if (PaymentFlow::hasPendingPayment($contactId)) {
    json_response(['success' => false, 'error' => 'You already have a pending payment with the host.'], 409);
}

try {
    if ($reason === 'entrance_fee') {
        $context = PaymentFlow::entranceFeeContext($contactId);
    } else {
        if ($tierId <= 0) {
            json_response(['success' => false, 'error' => 'Please select a membership level.'], 400);
        }
        $context = PaymentFlow::resolveRenewalCharge($contactId, $tierId);
        if ($context['pivoted_to_entrance_fee']) {
            json_response([
                'success' => true,
                'pivoted_to_entrance_fee' => true,
                'amount' => $context['amount'],
                'membership_name' => $context['membership_name'],
            ]);
        }
    }

    BillingHelper::createPendingPayment($contactId, $reason === 'entrance_fee' ? 'entrance_fee' : 'membership_renewal', $context['plan_id'], $context['amount']);

    json_response([
        'success' => true,
        'pivoted_to_entrance_fee' => false,
        'message' => sprintf('See the Host to pay $%s in cash. Your check-in will be completed once they confirm payment.', number_format($context['amount'], 2)),
    ]);
} catch (Exception $e) {
    json_response(['success' => false, 'error' => safe_err('Failed to record cash payment request: ', $e)], 400);
}
