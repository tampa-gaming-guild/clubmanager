<?php
/**
 * Mobile API (host tools): approve or deny a pending cash payment, mirroring
 * pending-payments.php's POST handler via the same BillingHelper methods
 * (approving completes the member's check-in). Requires 'edit checkins'.
 */
require_once dirname(dirname(dirname(dirname(__DIR__)))) . '/config/bootstrap.php';

use App\ApiAuth;
use App\BillingHelper;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$user = ApiAuth::requirePermission('edit checkins');

$body = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
$pendingId = (int)($body['pending_id'] ?? 0);
$action = $body['action'] ?? '';

if ($pendingId <= 0 || !in_array($action, ['approve', 'deny'], true)) {
    json_response(['success' => false, 'error' => 'Invalid request.'], 400);
}

try {
    if ($action === 'approve') {
        $details = BillingHelper::approvePendingPayment($pendingId, (int)$user['contact_id']);
        $amountFormatted = number_format((float)$details['amount'], 2);
        json_response(['success' => true, 'message' => "{$details['display_name']} checked in (\${$amountFormatted} cash)."]);
    } else {
        BillingHelper::denyPendingPayment($pendingId, (int)$user['contact_id']);
        json_response(['success' => true, 'message' => 'Payment request denied.']);
    }
} catch (Exception $e) {
    json_response(['success' => false, 'error' => safe_err('', $e)], 400);
}
