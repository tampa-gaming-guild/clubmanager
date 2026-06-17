<?php
/**
 * Admin Check-In List Page
 * Displays a tabular report of check-ins for a selected date, ordered by first name.
 * Allows deleting check-ins.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config/bootstrap.php';

use App\Auth;
use App\Database;
use App\CiviCRMImporter;

Auth::requirePermission('edit checkins');

$errorMsg = null;
$successMsg = null;
$checkinsList = [];

// Determine selected date (defaults to current local date)
$selectedDate = $_GET['date'] ?? '';
if (empty($selectedDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
    $selectedDate = date('Y-m-d');
}

// Check for success parameter in GET (Post-Redirect-Get pattern feedback)
if (isset($_GET['success']) && $_GET['success'] === '1') {
    $successMsg = "Check-in deleted successfully.";
}

try {
    $appDb = Database::getAppConnection();

    // Handle Check-In Deletion
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_checkin'])) {
        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            $errorMsg = "Invalid security token.";
        } else {
            $checkinId = (int)($_POST['checkin_id'] ?? 0);
            if ($checkinId > 0) {
                $deleteStmt = $appDb->prepare("DELETE FROM tgg_checkins WHERE id = :id");
                $deleteStmt->execute(['id' => $checkinId]);
                
                // Redirect back to same page with date parameter to prevent form resubmission
                redirect("admin/checkins.php?date=" . urlencode($selectedDate) . "&success=1");
            } else {
                $errorMsg = "Invalid check-in ID.";
            }
        }
    }

    // Fetch Check-Ins for the selected date, ordered by first name
    // Falls back to display_name if first_name is empty/null.
    $stmt = $appDb->prepare("
        SELECT 
            c.id AS checkin_id, 
            c.checked_in_at, 
            c.notes, 
            con.display_name, 
            con.first_name, 
            con.last_name, 
            con.id AS contact_id
        FROM tgg_checkins c
        JOIN tgg_contacts con ON con.id = c.contact_id
        WHERE DATE(c.checked_in_at) = :date
        ORDER BY COALESCE(NULLIF(con.first_name, ''), con.display_name) ASC, con.last_name ASC
    ");
    $stmt->execute(['date' => $selectedDate]);
    $checkinsList = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

} catch (Exception $e) {
    $errorMsg = safe_err("Error compiling check-in report: ", $e);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Check-In List - Admin Panel</title>
    <link rel="stylesheet" href="../assets/css/style.css">
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
                <?php if (has_permission('edit checkins')): ?>
                    <a href="checkins.php" class="active">Check-In List</a>
                <?php endif; ?>
                <a href="dashboard.php">Admin</a>
                <a href="../index.php?action=logout&amp;csrf_token=<?php echo e(get_csrf_token()); ?>" class="btn-logout">Logout</a>
            </nav>
        </header>

        <main class="main-content">
            <div class="admin-grid">
                
                <?php include 'sidebar.php'; ?>

                <!-- Work Area: Check-In List -->
                <section class="admin-workspace">
                    
                    <div style="margin-bottom: 25px; display: flex; align-items: center; justify-content: space-between; gap: 15px; flex-wrap: wrap;">
                        <div>
                            <h2 style="margin: 0;">Check-In List</h2>
                            <p class="description-text" style="margin: 5px 0 0 0;">Manage and verify members currently checked in at the club.</p>
                        </div>
                        <form method="GET" action="checkins.php" style="display: inline-flex; align-items: center; gap: 10px; background: rgba(255,255,255,0.05); padding: 8px 15px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.1);">
                            <label for="date-filter" style="color: var(--color-text-secondary); font-size: 0.85rem; font-weight: 500;">Choose Date:</label>
                            <input type="date" id="date-filter" name="date" value="<?php echo e($selectedDate); ?>" onchange="this.form.submit()" style="background: rgba(0,0,0,0.2); border: 1px solid rgba(255,255,255,0.2); border-radius: 4px; color: #fff; padding: 5px 10px; font-size: 0.85rem; outline: none; cursor: pointer;">
                        </form>
                    </div>

                    <?php if ($errorMsg): ?>
                        <div class="alert alert-danger"><?php echo e($errorMsg); ?></div>
                    <?php endif; ?>

                    <?php if ($successMsg): ?>
                        <div class="alert alert-success"><?php echo e($successMsg); ?></div>
                    <?php endif; ?>

                    <div class="table-card glass-panel span-full-row">
                        <div class="admin-table-container">
                            <table class="admin-table">
                                <thead>
                                    <tr>
                                        <th>First Name</th>
                                        <th>Last Name</th>
                                        <th>Display Name</th>
                                        <th>Check-In Time</th>
                                        <th>Notes</th>
                                        <th style="text-align: center;">+1</th>
                                        <th style="text-align: center; width: 120px;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($checkinsList)): ?>
                                        <tr>
                                            <td colspan="7" class="text-center" style="padding: 30px; color: var(--color-text-muted);">No check-in records found for <?php echo e(date('M d, Y', strtotime($selectedDate))); ?>.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($checkinsList as $chk): ?>
                                            <tr>
                                                <td><strong><?php echo e($chk['first_name'] ?: '-'); ?></strong></td>
                                                <td><strong><?php echo e($chk['last_name'] ?: '-'); ?></strong></td>
                                                <td><?php echo e($chk['display_name']); ?></td>
                                                <td><span class="table-datetime"><?php echo date('g:i A', strtotime($chk['checked_in_at'])); ?></span></td>
                                                <td><?php echo e($chk['notes'] ?: 'Regular Visit'); ?></td>
                                                <td style="text-align: center; color: var(--color-text-muted);">-</td>
                                                <td style="text-align: center;">
                                                    <form action="checkins.php?date=<?php echo urlencode($selectedDate); ?>" method="POST" onsubmit="return confirm('Are you sure you want to delete this check-in record?');" style="margin: 0;">
                                                        <input type="hidden" name="csrf_token" value="<?php echo e(get_csrf_token()); ?>">
                                                        <input type="hidden" name="checkin_id" value="<?php echo e($chk['checkin_id']); ?>">
                                                        <button type="submit" name="delete_checkin" class="btn btn-danger btn-sm" style="padding: 6px 12px; font-size: 0.75rem; border-radius: 4px; border: none; width: 100%;">Delete</button>
                                                    </form>
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
    </div>
</body>
</html>
