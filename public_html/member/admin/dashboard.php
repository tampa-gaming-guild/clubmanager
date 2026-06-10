<?php
/**
 * Admin Dashboard - Control Hub
 * Provides summary metrics and a searchable members list for desk administrators.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config/bootstrap.php';

use App\Auth;
use App\Database;
use App\CiviCRMImporter;

Auth::requireAdmin();

$errorMsg = null;
$members = [];

// Fetch statistics
$totalMembers = 0;
$activeMembers = 0;
$expiredMembers = 0;
$checkinsToday = 0;
$monthRevenue = 0.00;

try {
    $members = CiviCRMImporter::getMembersList();
    $totalMembers = count($members);

    $appDb = Database::getAppConnection();
    $civiDb = Database::getCiviConnection();

    // 1. Calculate Active/Expired status counts
    foreach ($members as $m) {
        if ($m['is_active']) {
            $activeMembers++;
        } else if ($m['status_label'] === 'Expired') {
            $expiredMembers++;
        }
    }

    // 2. Fetch Check-ins logged today
    $checkinsToday = (int)$appDb->query("
        SELECT COUNT(*) FROM tgg_checkins 
        WHERE DATE(checked_in_at) = CURRENT_DATE()
    ")->fetchColumn();

    // 3. Fetch Stripe/CiviCRM contribution revenue this month
    $monthRevenue = (float)$civiDb->query("
        SELECT SUM(total_amount) FROM civicrm_contribution 
        WHERE MONTH(receive_date) = MONTH(CURRENT_DATE()) 
          AND YEAR(receive_date) = YEAR(CURRENT_DATE())
          AND contribution_status_id = 1
    ")->fetchColumn();

} catch (Exception $e) {
    $errorMsg = "Unable to fetch summary metrics: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Club Management</title>
    <link rel="stylesheet" href="../assets/css/style.css">
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
                        <li><a href="dashboard.php" class="active">Control Hub</a></li>
                        <li><a href="scheduler.php">Event Scheduler</a></li>
                        <li><a href="import.php">CiviCRM Importer</a></li>
                        <li><a href="reports.php">Reports & Analytics</a></li>
                    </ul>
                </aside>

                <!-- Work Area: Control Hub -->
                <section class="admin-workspace">
                    <h2>Control Hub Dashboard</h2>
                    <p class="description-text">Real-time overview of members, check-ins, and payments.</p>

                    <?php if ($errorMsg): ?>
                        <div class="alert alert-danger"><?php echo e($errorMsg); ?></div>
                    <?php endif; ?>

                    <!-- Stat Cards Panel -->
                    <div class="stats-panel-grid">
                        <div class="stat-card glass-panel border-left-blue">
                            <span class="stat-icon">👥</span>
                            <div class="stat-vals">
                                <strong><?php echo $totalMembers; ?></strong>
                                <span>Total Contacts</span>
                            </div>
                        </div>
                        <div class="stat-card glass-panel border-left-green">
                            <span class="stat-icon">✔️</span>
                            <div class="stat-vals">
                                <strong><?php echo $activeMembers; ?></strong>
                                <span>Active Members</span>
                            </div>
                        </div>
                        <div class="stat-card glass-panel border-left-orange">
                            <span class="stat-icon">🎟️</span>
                            <div class="stat-vals">
                                <strong><?php echo $checkinsToday; ?></strong>
                                <span>Check-Ins Today</span>
                            </div>
                        </div>
                        <div class="stat-card glass-panel border-left-yellow">
                            <span class="stat-icon">💲</span>
                            <div class="stat-vals">
                                <strong>$<?php echo number_format($monthRevenue, 2); ?></strong>
                                <span>Revenue (Month)</span>
                            </div>
                        </div>
                    </div>

                    <!-- Members List Table -->
                    <div class="table-card glass-panel mt-20">
                        <div class="table-card-header">
                            <h3>Registered Members Directory</h3>
                            <div class="search-bar">
                                <input type="text" id="member-search" placeholder="Search members by name/email..." onkeyup="filterMembersTable()">
                            </div>
                        </div>

                        <div class="admin-table-container">
                            <table class="admin-table" id="members-table">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Contact Email</th>
                                        <th>Membership Level</th>
                                        <th>Status</th>
                                        <th>Expires</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($members)): ?>
                                        <tr>
                                            <td colspan="6" class="text-center">No members found. Use the CiviCRM Importer to sync records.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($members as $m): ?>
                                            <tr>
                                                <td><strong><?php echo e($m['display_name']); ?></strong></td>
                                                <td><?php echo e($m['email']); ?></td>
                                                <td><?php echo e($m['membership_name'] ?: 'None'); ?></td>
                                                <td>
                                                    <span class="badge badge-status <?php echo $m['is_active'] ? 'badge-active' : 'badge-expired'; ?>">
                                                        <?php echo e($m['status_label'] ?: 'None'); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php echo $m['end_date'] ? date('M d, Y', strtotime($m['end_date'])) : 'N/A'; ?>
                                                </td>
                                                <td>
                                                    <a href="../profile.php?id=<?php echo (int)$m['id']; ?>" class="btn btn-secondary btn-small">Manage</a>
                                                </td>
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
            <p>&copy; <?php echo date('Y'); ?> TGG Club Membership System. Secure Public Portal.</p>
        </footer>
    </div>

    <!-- Client-side filter script -->
    <script>
        function filterMembersTable() {
            const input = document.getElementById('member-search');
            const filter = input.value.toLowerCase();
            const table = document.getElementById('members-table');
            const trs = table.getElementsByTagName('tr');

            for (let i = 1; i < trs.length; i++) {
                let nameTd = trs[i].getElementsByTagName('td')[0];
                let emailTd = trs[i].getElementsByTagName('td')[1];
                if (nameTd && emailTd) {
                    let nameTxt = nameTd.textContent || nameTd.innerText;
                    let emailTxt = emailTd.textContent || emailTd.innerText;
                    if (nameTxt.toLowerCase().indexOf(filter) > -1 || emailTxt.toLowerCase().indexOf(filter) > -1) {
                        trs[i].style.display = "";
                    } else {
                        trs[i].style.display = "none";
                    }
                }
            }
        }
    </script>
</body>
</html>
