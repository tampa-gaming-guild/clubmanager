<?php
/**
 * Mobile API (host tools): request a cash payment for a member the host is
 * checking in -- creates a tgg_pending_payments row awaiting host approval
 * (approved from the same Hosting Dashboard), mirroring pay-entrance.php's
 * cash action. api/payment-cash.php is the self-service equivalent.
 */
require_once dirname(dirname(dirname(dirname(__DIR__)))) . '/config/bootstrap.php';

use App\BillingHelper;
use App\ApiAuth;
use App\PaymentFlow;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

ApiAuth::requirePermission('edit checkins');

$body = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
$contactId = (int)($body['contact_id'] ?? 0);
$reason = ($body['reason'] ?? '') === 'entrance_fee' ? 'entrance_fee' : 'renewal';
$tierId = (int)($body['tier_id'] ?? 0);

if ($contactId <= 0) {
    json_response(['success' => false, 'error' => 'Invalid member.'], 400);
}
if (PaymentFlow::hasPendingPayment($contactId)) {
    json_response(['success' => false, 'error' => 'This member already has a pending payment.'], 409);
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
        'message' => sprintf('Recorded -- $%s cash pending approval.', number_format($context['amount'], 2)),
    ]);
} catch (Exception $e) {
    json_response(['success' => false, 'error' => safe_err('Failed to record cash payment request: ', $e)], 400);
}
