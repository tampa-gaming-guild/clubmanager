<?php
/**
 * Pending Cash Payments API
 * Used by the Host Portal to list, approve, or deny pending entrance-fee / renewal
 * cash payment requests. Approving completes the member's check-in (BillingHelper
 * handles the ledger/subscription update and the actual tgg_checkins insert).
 */
require_once dirname(dirname(__DIR__)) . '/config/bootstrap.php';

use App\Auth;
use App\Database;
use App\BillingHelper;

Auth::requirePermission('edit checkins');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $appDb = Database::getAppConnection();
        $stmt = $appDb->query("
            SELECT pp.id, pp.contact_id, pp.type, pp.plan_id, pp.amount, pp.requested_at,
                   c.display_name
            FROM tgg_pending_payments pp
            LEFT JOIN tgg_contacts c ON c.id = pp.contact_id
            WHERE pp.status = 'pending'
            ORDER BY pp.requested_at ASC
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            if (empty($row['display_name'])) {
                $row['display_name'] = "Member #{$row['contact_id']}";
            }
            $row['amount'] = number_format((float)$row['amount'], 2);
        }
        json_response(['success' => true, 'pending' => $rows]);
    } catch (Exception $e) {
        json_response(['success' => false, 'error' => $e->getMessage()], 500);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        json_response(['success' => false, 'error' => 'Invalid security token. Please refresh and try again.'], 403);
    }

    $pendingId = (int)($_POST['pending_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $resolverContactId = (int)($_SESSION['user']['contact_id'] ?? 0);

    if ($pendingId <= 0 || !in_array($action, ['approve', 'deny'], true)) {
        json_response(['success' => false, 'error' => 'Invalid request.'], 400);
    }

    try {
        if ($action === 'approve') {
            $details = BillingHelper::approvePendingPayment($pendingId, $resolverContactId);
            $amountFormatted = number_format((float)$details['amount'], 2);
            json_response(['success' => true, 'message' => "{$details['display_name']} checked in (\${$amountFormatted} cash)."]);
        } else {
            BillingHelper::denyPendingPayment($pendingId, $resolverContactId);
            json_response(['success' => true, 'message' => 'Payment request denied.']);
        }
    } catch (Exception $e) {
        json_response(['success' => false, 'error' => $e->getMessage()], 400);
    }
}
