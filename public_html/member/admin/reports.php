<?php
/**
 * Admin Reports & Analytics
 * Aggregates attendance and financial metrics, presenting them in responsive visual charts.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config/bootstrap.php';

use App\Auth;
use App\Database;

Auth::requireAdmin();

$errorMsg = null;

// Datasets for Chart.js
$attendanceLabels = [];
$attendanceData = [];

$financeLabels = [];
$financeData = [];

$tierLabels = [];
$tierData = [];

try {
    $appDb = Database::getAppConnection();
    $civiDb = Database::getCiviConnection();

    // 1. Fetch Attendance Trends (Last 7 Days)
    $attStmt = $appDb->query("
        SELECT DATE(checked_in_at) as checkin_date, COUNT(*) as count 
        FROM tgg_checkins 
        WHERE checked_in_at >= DATE_SUB(CURRENT_DATE(), INTERVAL 7 DAY) 
        GROUP BY DATE(checked_in_at) 
        ORDER BY checkin_date ASC
    ");
    $attRows = $attStmt->fetchAll();
    
    // Fill in last 7 days even if 0 checkins to make a smooth chart
    for ($i = 6; $i >= 0; $i--) {
        $dateStr = date('Y-m-d', strtotime("-{$i} days"));
        $labelStr = date('D (M d)', strtotime($dateStr));
        
        $attendanceLabels[] = $labelStr;
        
        $count = 0;
        foreach ($attRows as $row) {
            if ($row['checkin_date'] === $dateStr) {
                $count = (int)$row['count'];
                break;
            }
        }
        $attendanceData[] = $count;
    }

    // 2. Fetch Financial Trends (Last 30 Days)
    $finStmt = $civiDb->query("
        SELECT DATE(receive_date) as receive_date, SUM(total_amount) as amount 
        FROM civicrm_contribution 
        WHERE receive_date >= DATE_SUB(CURRENT_DATE(), INTERVAL 30 DAY) 
          AND contribution_status_id = 1
        GROUP BY DATE(receive_date) 
        ORDER BY receive_date ASC
    ");
    $finRows = $finStmt->fetchAll();

    // Group by week or date. Let's do dates for detailed 30-day lines
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

    // 3. Fetch Membership Tier Distribution
    $tierStmt = $civiDb->query("
        SELECT t.name as tier_name, COUNT(m.id) as count 
        FROM civicrm_membership m 
        INNER JOIN civicrm_membership_type t ON m.membership_type_id = t.id 
        GROUP BY t.id
        ORDER BY count DESC
    ");
    $tierRows = $tierStmt->fetchAll();
    foreach ($tierRows as $row) {
        $tierLabels[] = $row['tier_name'];
        $tierData[] = (int)$row['count'];
    }

} catch (Exception $e) {
    $errorMsg = "Failed to compile analytical data: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports & Analytics - Admin Panel</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <!-- Chart.js CDN for client-side visualizations -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <div class="app-container">
        <header class="navbar">
            <div class="logo">TGG Members</div>
            <nav class="nav-links">
                <a href="../index.php">Dashboard</a>
                <a href="../calendar.php">Calendar</a>
                <a href="../checkin.php">Check-In</a>
                <a href="dashboard.php" class="active">Admin</a>
                <a href="../index.php?action=logout" class="btn-logout">Logout</a>
            </nav>
        </header>

        <main class="main-content">
            <div class="admin-grid">
                
                <!-- Sidebar Admin Navigation -->
                <aside class="admin-sidebar glass-panel">
                    <h3>Admin Controls</h3>
                    <ul class="admin-menu">
                        <li><a href="dashboard.php">Control Hub</a></li>
                        <li><a href="scheduler.php">Event Scheduler</a></li>
                        <li><a href="import.php">CiviCRM Importer</a></li>
                        <li><a href="reports.php" class="active">Reports & Analytics</a></li>
                    </ul>
                </aside>

                <!-- Work Area: Reports -->
                <section class="admin-workspace">
                    <h2>Reports & Analytics Dashboard</h2>
                    <p class="description-text">Visual charts demonstrating member attendance, financial growth, and tier distributions.</p>

                    <?php if ($errorMsg): ?>
                        <div class="alert alert-danger"><?php echo e($errorMsg); ?></div>
                    <?php endif; ?>

                    <div class="reports-grid">
                        
                        <!-- 1. Attendance Trend Chart -->
                        <div class="report-chart-card glass-panel">
                            <h3>Attendance (Last 7 Days)</h3>
                            <div class="chart-canvas-container">
                                <canvas id="attendanceChart"></canvas>
                            </div>
                        </div>

                        <!-- 2. Membership Distribution Chart -->
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
                    </div>
                </section>
            </div>
        </main>

        <footer class="app-footer">
            <p>&copy; <?php echo date('Y'); ?> TGG Club Membership System. Secure Public Portal.</p>
        </footer>
    </div>

    <!-- Chart Configuration Script -->
    <script>
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
            new Chart(tierCtx, {
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
</body>
</html>
