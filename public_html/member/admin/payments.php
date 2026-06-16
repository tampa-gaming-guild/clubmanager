<?php
/**
 * Admin Payments Log Page
 * Displays a sortable tabular report of all recent membership payments.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config/bootstrap.php';

use App\Auth;
use App\Database;

Auth::requireAdmin();

$errorMsg = null;
$paymentsList = [];

try {
    $appDb = Database::getAppConnection();

    // Fetch Recent Payments Log (Financial Table Report) from local ledger
    $payLogsRaw = $appDb->query("
        SELECT l.contact_id, l.created_at as receive_date, l.amount as total_amount, l.payment_intent_id as trxn_id, p.name as plan_name
        FROM tgg_billing_ledger l
        LEFT JOIN tgg_subscription_plans p ON l.plan_id = p.id
        WHERE l.payment_status = 'paid'
        ORDER BY l.created_at DESC
        LIMIT 100
    ")->fetchAll();
    
    if (!empty($payLogsRaw)) {
        $contactIds = array_unique(array_column($payLogsRaw, 'contact_id'));
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
                'plan_name' => $row['plan_name'] ?? 'Unknown Plan'
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
    <link rel="stylesheet" href="../assets/css/style.css">
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
        <!-- Navigation Bar -->
        <header class="navbar">
            <div class="logo">TGG Members</div>
            <?php if (has_role('admin')): ?>
                <form action="<?php echo rtrim($_ENV['BASE_URL'] ?? 'http://localhost/member', '/') . '/admin/dashboard.php'; ?>" method="GET" class="navbar-search-form" style="margin: 0 20px; flex-grow: 1; max-width: 380px; position: relative;">
                    <input type="text" name="search" placeholder="Search members by name..." 
                        value="<?php echo isset($_GET['search']) ? e($_GET['search']) : ''; ?>"
                        style="width: 100%; padding: 8px 15px 8px 35px; background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 20px; color: #fff; font-size: 0.85rem; outline: none; transition: all 0.2s ease;">
                    <span style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: rgba(255, 255, 255, 0.4); font-size: 0.9rem;">🔍</span>
                </form>
            <?php endif; ?>
            <nav class="nav-links">
                <a href="../index.php">Dashboard</a>
                <a href="../calendar.php">Calendar</a>
                <a href="../volunteers.php">Volunteers</a>
                <a href="../checkin.php">Check-In</a>
                <a href="dashboard.php" class="active">Admin</a>
                <a href="../index.php?action=logout&amp;csrf_token=<?php echo e(get_csrf_token()); ?>" class="btn-logout">Logout</a>
            </nav>
        </header>

        <main class="main-content">
            <div class="admin-grid">
                
                <!-- Sidebar Admin Navigation -->
                <aside class="admin-sidebar glass-panel">
                    <h3>Admin Controls</h3>
                    <ul class="admin-menu">
                        <li><a href="dashboard.php">Dashboard</a></li>
                        <li><a href="scheduler.php">Event Scheduler</a></li>
                        <li><a href="volunteer_credits.php">Volunteer Credits</a></li>
                        <li><a href="import.php">CiviCRM Importer</a></li>
                        <li><a href="memberships.php">Memberships</a></li>
                        <li><a href="email_templates.php">Email Templates</a></li>
                        <li>
                            <a href="reports.php" class="active">Reports & Analytics</a>
                            <ul class="admin-submenu" style="list-style-type: none; padding-left: 15px; margin-top: 5px; display: flex; flex-direction: column; gap: 4px;">
                                <li><a href="payments.php" class="active" style="padding: 6px 10px; font-size: 0.85rem; border-left: none; border-radius: 4px;">Payments Log</a></li>
                                <li><a href="attendance.php" style="padding: 6px 10px; font-size: 0.85rem; border-left: none; border-radius: 4px;">Attendance Log</a></li>
                                <li><a href="email_log.php" style="padding: 6px 10px; font-size: 0.85rem; border-left: none; border-radius: 4px;">Email Log</a></li>
                            </ul>
                        </li>
                    </ul>
                </aside>

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
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($paymentsList)): ?>
                                        <tr>
                                            <td colspan="5" class="text-center">No payment history found.</td>
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
                                                    
                                                    if (strpos($trxnId, 'offline_volunteer_credit_') === 0) {
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
                                                    }
                                                    ?>
                                                    <span class="badge <?php echo $badgeClass; ?>" style="font-size: 0.75rem; padding: 2px 6px; display: inline-block; margin-right: 5px;">
                                                        <?php echo e($badgeLabel); ?>
                                                    </span>
                                                    <code><?php echo e($trxnId); ?></code>
                                                </td>
                                                <td><strong>$<?php echo number_format($pay['total_amount'], 2); ?></strong></td>
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

        <footer class="app-footer">
            <p>&copy; <?php echo date('Y'); ?> TGG Club Membership System. Secure Admin Portal.</p>
        </footer>
    </div>

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
