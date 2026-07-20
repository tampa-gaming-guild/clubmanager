<?php
/**
 * Admin Payments Log Page
 * Displays a sortable tabular report of all recent membership payments.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config/bootstrap.php';

use App\Auth;
use App\AuditLog;
use App\Database;

Auth::requirePermission('process payments');

$errorMsg = null;
$paymentsList = [];

// Actor attribution is admin-panel-only information.
$showActorCol = has_permission('admin panel');

try {
    $appDb = Database::getAppConnection();

    // Fetch Recent Payments Log (Financial Table Report) from local ledger. Includes
    // failed attempts (e.g. declined auto-renewal charges) alongside successful ones, so
    // admins have visibility into renewal charges that didn't go through.
    $payLogsRaw = $appDb->query("
        SELECT l.contact_id, l.created_at as receive_date, l.amount as total_amount, l.payment_intent_id as trxn_id,
               l.payment_status, l.action_type, l.created_by, l.impersonator_id, l.source, p.name as plan_name
        FROM tgg_billing_ledger l
        LEFT JOIN tgg_subscription_plans p ON l.plan_id = p.id
        ORDER BY l.created_at DESC
        LIMIT 100
    ")->fetchAll();

    if (!empty($payLogsRaw)) {
        $contactIds = array_unique(array_filter(array_merge(
            array_column($payLogsRaw, 'contact_id'),
            array_column($payLogsRaw, 'created_by'),
            array_column($payLogsRaw, 'impersonator_id')
        ), fn($id) => $id !== null));
        $placeholders = implode(',', array_fill(0, count($contactIds), '?'));

        $civiContactStmt = $appDb->prepare("SELECT id, display_name FROM tgg_contacts WHERE id IN ({$placeholders})");
        $civiContactStmt->execute(array_values($contactIds));
        $contactsMap = $civiContactStmt->fetchAll(PDO::FETCH_KEY_PAIR);

        foreach ($payLogsRaw as $row) {
            $cid = (int)$row['contact_id'];
            $paymentsList[] = [
                'display_name' => $contactsMap[$cid] ?? "Member #{$cid}",
                'receive_date' => $row['receive_date'],
                'total_amount' => $row['total_amount'],
                'trxn_id' => $row['trxn_id'],
                'payment_status' => $row['payment_status'],
                'action_type' => $row['action_type'],
                'plan_name' => $row['plan_name'] ?? 'Unknown Plan',
                'recorded_by' => AuditLog::describeActor(
                    $row['created_by'] !== null ? (int)$row['created_by'] : null,
                    $row['impersonator_id'] !== null ? (int)$row['impersonator_id'] : null,
                    $row['source'],
                    $contactsMap
                )
            ];
        }
    }
} catch (Exception $e) {
    $errorMsg = safe_err("Failed to compile financial ledger data: ", $e);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payments Log - Admin Panel</title>
    <link rel="shortcut icon" href="../favicon.ico" type="image/x-icon">
    <link rel="icon" type="image/png" href="../favicon.png">
    <link rel="apple-touch-icon" href="../favicon.png">
    <link rel="manifest" href="../manifest.json">
    <link rel="stylesheet" href="../assets/css/style.css<?php echo asset_version('assets/css/style.css'); ?>">
    <style>
        .sortable-header {
            cursor: pointer;
            user-select: none;
            position: relative;
            transition: background 0.2s ease;
        }
        .sortable-header:hover {
            background: rgba(255, 255, 255, 0.05);
        }
        .sortable-header::after {
            content: " ⇅";
            font-size: 0.8rem;
            color: var(--color-text-muted);
            margin-left: 5px;
        }
        .sortable-header.sort-asc::after {
            content: " ▲";
            color: var(--color-primary);
        }
        .sortable-header.sort-desc::after {
            content: " ▼";
            color: var(--color-primary);
        }
    </style>
</head>
<body>
    <div class="app-container">
        <?php $navAdminArea = true; $navActive = 'admin'; include __DIR__ . '/../partials/navbar.php'; ?>

        <main class="main-content">
            <div class="admin-grid">
                
                <?php include 'sidebar.php'; ?>

                <!-- Work Area: Payments Log -->
                <section class="admin-workspace">
                    <h2>Recent Payments Log</h2>
                    <p class="description-text" style="margin-bottom: 25px;">Audit trail of all processed stripe checkouts and recorded offline renewals.</p>

                    <?php if ($errorMsg): ?>
                        <div class="alert alert-danger"><?php echo e($errorMsg); ?></div>
                    <?php endif; ?>

                    <div class="table-card glass-panel span-full-row">

                        <div class="admin-table-container">
                            <table class="admin-table" id="payments-report-table" data-sort-dir="">
                                <thead>
                                    <tr>
                                        <th class="sortable-header" onclick="sortTable('payments-report-table', 0, false)">Date / Time</th>
                                        <th class="sortable-header" onclick="sortTable('payments-report-table', 1, false)">Member Name</th>
                                        <th class="sortable-header" onclick="sortTable('payments-report-table', 2, false)">Membership Tier</th>
                                        <th class="sortable-header" onclick="sortTable('payments-report-table', 3, false)">Method / Transaction ID</th>
                                        <th class="sortable-header" onclick="sortTable('payments-report-table', 4, true)">Amount Paid</th>
                                        <?php if ($showActorCol): ?>
                                            <th class="sortable-header" onclick="sortTable('payments-report-table', 5, false)">Recorded By</th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($paymentsList)): ?>
                                        <tr>
                                            <td colspan="<?php echo $showActorCol ? 6 : 5; ?>" class="text-center">No payment history found.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($paymentsList as $pay): ?>
                                            <tr>
                                                <td><span class="table-datetime"><?php echo date('Y-m-d H:i:s', strtotime($pay['receive_date'])); ?></span></td>
                                                <td><strong><?php echo e($pay['display_name']); ?></strong></td>
                                                <td><a href="dashboard.php?level=<?php echo urlencode($pay['plan_name']); ?>" style="color: var(--color-primary); text-decoration: none; font-weight: 600;"><?php echo e($pay['plan_name']); ?></a></td>
                                                <td>
                                                    <?php
                                                    $trxnId = $pay['trxn_id'] ?? '';
                                                    $badgeClass = 'badge-active';
                                                    $badgeLabel = 'Paid (Card)';

                                                    if (($pay['payment_status'] ?? '') === 'failed') {
                                                        $badgeClass = 'badge-expired';
                                                        $badgeLabel = 'Declined';
                                                    } elseif (strpos($trxnId, 'credit_redeem_') === 0) {
                                                        $badgeClass = 'badge-volunteer';
                                                        $badgeLabel = 'Membership Credits Redeemed';
                                                    } elseif (strpos($trxnId, 'offline_volunteer_credit_') === 0) {
                                                        $badgeClass = 'badge-volunteer';
                                                        $badgeLabel = 'Volunteer';
                                                    } elseif (strpos($trxnId, 'offline_complimentary_') === 0) {
                                                        $badgeClass = 'badge-free';
                                                        $badgeLabel = 'Free';
                                                    } elseif (strpos($trxnId, 'offline_cash_') === 0) {
                                                        $badgeClass = 'badge-active';
                                                        $badgeLabel = 'Paid (Cash)';
                                                    } elseif (strpos($trxnId, 'offline_check_') === 0) {
                                                        $badgeClass = 'badge-active';
                                                        $badgeLabel = 'Paid (Check)';
                                                    } elseif (($pay['action_type'] ?? '') === 'auto_renew') {
                                                        $badgeLabel = 'Paid (Auto-Renew)';
                                                    }
                                                    ?>
                                                    <span class="badge <?php echo $badgeClass; ?>" style="font-size: 0.75rem; padding: 2px 6px; display: inline-block; margin-right: 5px;">
                                                        <?php echo e($badgeLabel); ?>
                                                    </span>
                                                    <code><?php echo e($trxnId); ?></code>
                                                </td>
                                                <td><strong>$<?php echo number_format($pay['total_amount'], 2); ?></strong></td>
                                                <?php if ($showActorCol): ?>
                                                    <td><?php echo e($pay['recorded_by']); ?></td>
                                                <?php endif; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>
            </div>
        </main>

        <?php $footerText = 'TGG Club Membership System. Secure Admin Portal.'; include __DIR__ . '/../partials/footer.php'; ?>

    <!-- Table Sorting Configuration Script -->
    <script>
        function sortTable(tableId, colIndex, isNumeric) {
            const table = document.getElementById(tableId);
            const tbody = table.querySelector('tbody');
            const rows = Array.from(tbody.querySelectorAll('tr'));
            
            if (rows.length === 1 && rows[0].cells.length === 1) return;

            const currentDir = table.getAttribute('data-sort-dir') === 'asc' ? 'desc' : 'asc';
            table.setAttribute('data-sort-dir', currentDir);

            const headers = table.querySelectorAll('th');
            headers.forEach(h => h.className = 'sortable-header');
            headers[colIndex].classList.add(currentDir === 'asc' ? 'sort-asc' : 'sort-desc');

            rows.sort((a, b) => {
                let cellA = a.cells[colIndex].innerText || a.cells[colIndex].textContent;
                let cellB = b.cells[colIndex].innerText || b.cells[colIndex].textContent;

                if (isNumeric) {
                    let numA = parseFloat(cellA.replace(/[^\d.-]/g, '')) || 0;
                    let numB = parseFloat(cellB.replace(/[^\d.-]/g, '')) || 0;
                    return currentDir === 'asc' ? numA - numB : numB - numA;
                } else {
                    return currentDir === 'asc' 
                        ? cellA.trim().localeCompare(cellB.trim()) 
                        : cellB.trim().localeCompare(cellA.trim());
                }
            });

            tbody.innerHTML = '';
            rows.forEach(row => tbody.appendChild(row));
        }
    </script>

    <script>
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('../sw.js')
                .then(reg => console.log('Service Worker registered'))
                .catch(err => console.error('Service Worker registration failed', err));
        });
    }
    </script>
</body>
</html>
