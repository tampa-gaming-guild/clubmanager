<?php
/**
 * Admin Reports & Analytics
 * Aggregates attendance and financial metrics, presenting them in responsive visual charts.
 * Includes sortable tabular reports for recent payments and check-in logs.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config/bootstrap.php';

use App\Auth;
use App\Database;

Auth::requireStaff();

$errorMsg = null;

// Datasets for Chart.js
$attendanceLabels = [];
$attendanceData = [];

$financeLabels = [];
$financeData = [];

$tierLabels = [];
$tierData = [];

// Tabular Reports Datasets
$paymentsList = [];
$checkinsList = [];

try {
    $appDb = Database::getAppConnection();

    // 1. Fetch Attendance Trends (Display only days of past scheduled events)
    $eventsStmt = $appDb->query("
        SELECT DISTINCT DATE(start_time) as event_date 
        FROM tgg_events 
        WHERE DATE(start_time) <= CURRENT_DATE()
        ORDER BY event_date DESC
        LIMIT 7
    ");
    $eventDates = array_column($eventsStmt->fetchAll(), 'event_date');
    $eventDates = array_reverse($eventDates); // Chronological order (left-to-right)

    if (empty($eventDates)) {
        // Fallback to last 7 days if no events are recorded in the past
        for ($i = 6; $i >= 0; $i--) {
            $eventDates[] = date('Y-m-d', strtotime("-{$i} days"));
        }
    }

    foreach ($eventDates as $dateStr) {
        $labelStr = date('D (M d)', strtotime($dateStr));
        $attendanceLabels[] = $labelStr;

        $countStmt = $appDb->prepare("
            SELECT COUNT(*) 
            FROM tgg_checkins 
            WHERE DATE(checked_in_at) = :dt
        ");
        $countStmt->execute(['dt' => $dateStr]);
        $attendanceData[] = (int)$countStmt->fetchColumn();
    }

    // 2. Fetch Financial Trends (Last 30 Days) from local ledger
    $finStmt = $appDb->query("
        SELECT DATE(created_at) as receive_date, SUM(amount) as amount 
        FROM tgg_billing_ledger 
        WHERE created_at >= DATE_SUB(CURRENT_DATE(), INTERVAL 30 DAY) 
          AND payment_status = 'paid'
        GROUP BY DATE(created_at) 
        ORDER BY receive_date ASC
    ");
    $finRows = $finStmt->fetchAll();

    // Group by date for detailed 30-day lines
    for ($i = 29; $i >= 0; $i--) {
        $dateStr = date('Y-m-d', strtotime("-{$i} days"));
        $labelStr = date('M d', strtotime($dateStr));
        
        $financeLabels[] = $labelStr;
        
        $amount = 0.00;
        foreach ($finRows as $row) {
            if ($row['receive_date'] === $dateStr) {
                $amount = (float)$row['amount'];
                break;
            }
        }
        $financeData[] = $amount;
    }

    // 3. Fetch Membership Tier Distribution from local subscriptions
    $tierStmt = $appDb->query("
        SELECT p.name as tier_name, COUNT(s.contact_id) as count 
        FROM tgg_subscriptions s 
        INNER JOIN tgg_subscription_plans p ON s.plan_id = p.id 
        GROUP BY p.id
        ORDER BY count DESC
    ");
    $tierRows = $tierStmt->fetchAll();
    foreach ($tierRows as $row) {
        $tierLabels[] = $row['tier_name'];
        $tierData[] = (int)$row['count'];
    }

    // 4. Fetch Recent Payments Log (Financial Table Report) from local ledger
    $payLogsRaw = $appDb->query("
        SELECT l.contact_id, l.created_at as receive_date, l.amount as total_amount, l.payment_intent_id as trxn_id, p.name as plan_name
        FROM tgg_billing_ledger l
        LEFT JOIN tgg_subscription_plans p ON l.plan_id = p.id
        WHERE l.payment_status = 'paid'
        ORDER BY l.created_at DESC
        LIMIT 50
    ")->fetchAll();
    
    $paymentsList = [];
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

    // 5. Fetch Recent Attendance Log (Attendance Table Report)
    $chkLogStmt = $appDb->query("
        SELECT contact_id, checked_in_at, notes 
        FROM tgg_checkins 
        ORDER BY checked_in_at DESC 
        LIMIT 50
    ");
    $checkinsRaw = $chkLogStmt->fetchAll();
    if (!empty($checkinsRaw)) {
        $contactIds = array_unique(array_column($checkinsRaw, 'contact_id'));
        $placeholders = implode(',', array_fill(0, count($contactIds), '?'));
        
        $civiContactStmt = $appDb->prepare("SELECT id, display_name FROM tgg_contacts WHERE id IN ({$placeholders})");
        $civiContactStmt->execute(array_values($contactIds));
        $contactsMap = $civiContactStmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        foreach ($checkinsRaw as $row) {
            $cid = (int)$row['contact_id'];
            $checkinsList[] = [
                'display_name' => $contactsMap[$cid] ?? "Member #{$cid}",
                'checked_in_at' => $row['checked_in_at'],
                'notes' => $row['notes']
            ];
        }
    }

} catch (Exception $e) {
    $errorMsg = safe_err("Failed to compile analytical data: ", $e);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports & Analytics - Admin Panel</title>
    <link rel="shortcut icon" href="../favicon.ico" type="image/x-icon">
    <link rel="icon" type="image/png" href="../favicon.png">
    <link rel="apple-touch-icon" href="../favicon.png">
    <link rel="manifest" href="../manifest.json">
    <link rel="stylesheet" href="../assets/css/style.css<?php echo asset_version('assets/css/style.css'); ?>">
    <!-- Chart.js CDN for client-side visualizations -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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

                <!-- Work Area: Reports -->
                <section class="admin-workspace">
                    <h2>Reports & Analytics Dashboard</h2>
                    <p class="description-text">Visual charts and sortable data summaries for attendance and finances.</p>

                    <?php if ($errorMsg): ?>
                        <div class="alert alert-danger"><?php echo e($errorMsg); ?></div>
                    <?php endif; ?>

                    <div class="reports-grid">
                        
                        <!-- 1. Attendance Trend Chart -->
                        <?php if (has_permission('edit checkins')): ?>
                        <div class="report-chart-card glass-panel">
                            <h3>Attendance (Last 7 Events)</h3>
                            <div class="chart-canvas-container">
                                <canvas id="attendanceChart"></canvas>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- 2. Membership Distribution Chart -->
                        <?php if (has_permission('process payments')): ?>
                        <div class="report-chart-card glass-panel">
                            <h3>Membership Tier Share</h3>
                            <div class="chart-canvas-container">
                                <canvas id="tierChart"></canvas>
                            </div>
                        </div>

                        <!-- 3. Financial Income Chart -->
                        <div class="report-chart-card glass-panel span-full-row">
                            <h3>Membership Dues Income Trend (Last 30 Days)</h3>
                            <div class="chart-canvas-container wide-chart">
                                <canvas id="financeChart"></canvas>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- 4. Tabular Financial Report -->
                        <?php if (has_permission('process payments')): ?>
                        <div class="table-card glass-panel span-full-row mt-20">
                            <h3>Recent Payments Log</h3>

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
                                                        
                                                        if (strpos($trxnId, 'credit_redeem_') === 0) {
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
                        <?php endif; ?>

                        <!-- 5. Tabular Attendance Report -->
                        <?php if (has_permission('edit checkins')): ?>
                        <div class="table-card glass-panel span-full-row mt-20">
                            <h3>Recent Attendance Log</h3>

                            <div class="admin-table-container">
                                <table class="admin-table" id="attendance-report-table" data-sort-dir="">
                                    <thead>
                                        <tr>
                                            <th class="sortable-header" onclick="sortTable('attendance-report-table', 0, false)">Check-In Time</th>
                                            <th class="sortable-header" onclick="sortTable('attendance-report-table', 1, false)">Member Name</th>
                                            <th class="sortable-header" onclick="sortTable('attendance-report-table', 2, false)">Check-In Notes</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($checkinsList)): ?>
                                            <tr>
                                                <td colspan="3" class="text-center">No check-in logs found.</td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($checkinsList as $chk): ?>
                                                <tr>
                                                    <td><span class="table-datetime"><?php echo date('Y-m-d H:i:s', strtotime($chk['checked_in_at'])); ?></span></td>
                                                    <td><strong><?php echo e($chk['display_name']); ?></strong></td>
                                                    <td><?php echo e($chk['notes'] ?: 'Regular Visit'); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <?php endif; ?>

                    </div>
                </section>
            </div>
        </main>

        <?php include __DIR__ . '/../partials/footer.php'; ?>

    <!-- Chart & Table Sorting Configuration Script -->
    <script>
        // Table Sorter
        function sortTable(tableId, colIndex, isNumeric) {
            const table = document.getElementById(tableId);
            const tbody = table.querySelector('tbody');
            const rows = Array.from(tbody.querySelectorAll('tr'));
            
            // If empty row exists, ignore
            if (rows.length === 1 && rows[0].cells.length === 1) return;

            // Toggle Sort Direction
            const currentDir = table.getAttribute('data-sort-dir') === 'asc' ? 'desc' : 'asc';
            table.setAttribute('data-sort-dir', currentDir);

            // Toggle visual class in headers
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

            // Re-render
            tbody.innerHTML = '';
            rows.forEach(row => tbody.appendChild(row));
        }

        document.addEventListener('DOMContentLoaded', () => {
            // Chart.js global options for dark theme alignment
            Chart.defaults.color = 'rgba(255, 255, 255, 0.7)';
            Chart.defaults.borderColor = 'rgba(255, 255, 255, 0.1)';
            Chart.defaults.font.family = 'system-ui, -apple-system, sans-serif';

            // 1. Attendance Chart (Line)
            const attCtx = document.getElementById('attendanceChart').getContext('2d');
            new Chart(attCtx, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode($attendanceLabels); ?>,
                    datasets: [{
                        label: 'Check-Ins',
                        data: <?php echo json_encode($attendanceData); ?>,
                        borderColor: '#22c55e',
                        backgroundColor: 'rgba(34, 197, 94, 0.2)',
                        borderWidth: 2,
                        tension: 0.3,
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: { stepSize: 1 }
                        }
                    }
                }
            });

            // 2. Membership Tier Distribution (Doughnut)
            const tierCtx = document.getElementById('tierChart').getContext('2d');
            const tierChartInstance = new Chart(tierCtx, {
                type: 'doughnut',
                data: {
                    labels: <?php echo json_encode($tierLabels); ?>,
                    datasets: [{
                        data: <?php echo json_encode($tierData); ?>,
                        backgroundColor: [
                            '#3b82f6', // Blue
                            '#eab308', // Yellow
                            '#ec4899', // Pink
                            '#a855f7', // Purple
                            '#14b8a6'  // Teal
                        ],
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    onClick: (event, activeElements) => {
                        if (activeElements.length > 0) {
                            const activeElement = activeElements[0];
                            const dataIndex = activeElement.index;
                            const label = tierChartInstance.data.labels[dataIndex];
                            window.location.href = 'dashboard.php?level=' + encodeURIComponent(label);
                        }
                    },
                    onHover: (event, activeElements) => {
                        event.native.target.style.cursor = activeElements.length ? 'pointer' : 'default';
                    },
                    plugins: {
                        legend: {
                            position: 'bottom'
                        }
                    }
                }
            });

            // 3. Finance Revenue Chart (Bar)
            const finCtx = document.getElementById('financeChart').getContext('2d');
            new Chart(finCtx, {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode($financeLabels); ?>,
                    datasets: [{
                        label: 'Revenue ($)',
                        data: <?php echo json_encode($financeData); ?>,
                        backgroundColor: '#3b82f6',
                        borderRadius: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) { return '$' + value; }
                            }
                        }
                    }
                }
            });
        });
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
