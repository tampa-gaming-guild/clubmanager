<?php
/**
 * Mobile API (host tools): payment context (entrance fee owed, or the
 * renewable tier list) for a member the host is checking in -- backs the
 * native Card/Cash + tier-picker screen used instead of loading
 * pay-entrance.php in a webview. See src/PaymentFlow.php for the shared
 * logic; api/payment-context.php is the self-service equivalent.
 */
require_once dirname(dirname(dirname(dirname(__DIR__)))) . '/config/bootstrap.php';

use App\ApiAuth;
use App\PaymentFlow;

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['error' => 'Method not allowed'], 405);
}

ApiAuth::requirePermission('edit checkins');

$contactId = (int)($_GET['contact_id'] ?? 0);
if ($contactId <= 0) {
    json_response(['error' => 'Invalid member.'], 400);
}

$reason = ($_GET['reason'] ?? '') === 'entrance_fee' ? 'entrance_fee' : 'renewal';

if (PaymentFlow::hasPendingPayment($contactId)) {
    json_response(['has_pending_payment' => true]);
}

try {
    $context = $reason === 'entrance_fee' ? PaymentFlow::entranceFeeContext($contactId) : PaymentFlow::renewalContext($contactId);
    json_response(array_merge(['has_pending_payment' => false], $context));
} catch (Exception $e) {
    json_response(['error' => safe_err('', $e)], 400);
}
