<?php
/**
 * Admin Volunteer Credits Config
 * Allows administrators to update the credits rewarded for specific volunteer roles.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config/bootstrap.php';

use App\Auth;
use App\Database;

Auth::requireAdmin();

$errorMsg = null;
$successMsg = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errorMsg = "Invalid security token.";
    } else {
        $credits = $_POST['credits'] ?? [];
        try {
            $appDb = Database::getAppConnection();
            $stmt = $appDb->prepare("UPDATE tgg_volunteer_credits SET credits = :credits WHERE credit_key = :key");
            
            $appDb->beginTransaction();
            foreach ($credits as $key => $val) {
                $valFloat = (float)$val;
                if ($valFloat < 0) {
                    throw new Exception("Credit values cannot be negative.");
                }
                $stmt->execute([
                    'credits' => $valFloat,
                    'key' => $key
                ]);
            }
            $appDb->commit();
            $successMsg = "Volunteer credit settings updated successfully.";
        } catch (Exception $e) {
            if ($appDb->inTransaction()) {
                $appDb->rollBack();
            }
            $errorMsg = "Failed to update credits: " . $e->getMessage();
        }
    }
}

try {
    $appDb = Database::getAppConnection();
    $stmt = $appDb->query("SELECT credit_key, credit_label, credits FROM tgg_volunteer_credits ORDER BY id ASC");
    $creditSettings = $stmt->fetchAll();
} catch (Exception $e) {
    $creditSettings = [];
    $errorMsg = "Unable to retrieve credits: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Volunteer Credits - Admin Dashboard</title>
    <link rel="shortcut icon" href="../favicon.ico" type="image/x-icon">
    <link rel="icon" type="image/png" href="../favicon.png">
    <link rel="apple-touch-icon" href="../favicon.png">
    <link rel="manifest" href="../manifest.json">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .credits-input {
            width: 100px !important;
            padding: 8px 12px !important;
            font-size: 0.9rem !important;
            border-radius: 6px !important;
            border: 1px solid var(--border-glass) !important;
            background: rgba(255, 255, 255, 0.05) !important;
            color: #fff !important;
            outline: none !important;
            transition: all 0.2s ease !important;
        }
        .credits-input:focus {
            border-color: var(--color-success, #22c55e) !important;
            box-shadow: 0 0 0 2px rgba(34, 197, 94, 0.2) !important;
        }
    </style>
</head>
<body>
    <div class="app-container">
        <header class="navbar">
            <div class="logo">TGG Members</div>
            <?php if (has_role('admin')): ?>
                <form action="dashboard.php" method="GET" class="navbar-search-form" style="margin: 0 20px; flex-grow: 1; max-width: 380px; position: relative;">
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
                <a href="../index.php?action=logout" class="btn-logout">Logout</a>
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
                        <li><a href="volunteer_credits.php" class="active">Volunteer Credits</a></li>
                        <li><a href="import.php">CiviCRM Importer</a></li>
                        <li><a href="memberships.php">Memberships</a></li>
                        <li><a href="reports.php" class="<?php echo in_array(basename($_SERVER['PHP_SELF']), ['reports.php', 'payments.php', 'attendance.php']) ? 'active' : ''; ?>">Reports & Analytics</a>
                            <ul class="admin-submenu" style="list-style-type: none; padding-left: 15px; margin-top: 5px; display: flex; flex-direction: column; gap: 4px;">
                                <li><a href="payments.php" class="<?php echo (basename($_SERVER['PHP_SELF']) === 'payments.php') ? 'active' : ''; ?>" style="padding: 6px 10px; font-size: 0.85rem; border-left: none; border-radius: 4px;">Payments Log</a></li>
                                <li><a href="attendance.php" class="<?php echo (basename($_SERVER['PHP_SELF']) === 'attendance.php') ? 'active' : ''; ?>" style="padding: 6px 10px; font-size: 0.85rem; border-left: none; border-radius: 4px;">Attendance Log</a></li>
                            </ul>
                        </li>
                    </ul>
                </aside>

                <!-- Volunteer Credits Workspace -->
                <section class="admin-workspace glass-panel">
                    <h2>Volunteer Credits Configuration</h2>
                    <p class="description-text" style="margin-bottom: 25px;">
                        Configure the volunteer credit weight values earned by members for filling specific shift roles.
                    </p>

                    <?php if ($errorMsg): ?>
                        <div class="alert alert-danger" style="margin-bottom: 20px;"><?php echo e($errorMsg); ?></div>
                    <?php endif; ?>

                    <?php if ($successMsg): ?>
                        <div class="alert alert-success" style="margin-bottom: 20px;"><?php echo e($successMsg); ?></div>
                    <?php endif; ?>

                    <form action="volunteer_credits.php" method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo e(get_csrf_token()); ?>">
                        
                        <div class="admin-table-container">
                            <table class="admin-table" style="width: 100%; margin-bottom: 20px;">
                                <thead>
                                    <tr>
                                        <th style="width: 60%;">Shift Configuration Role</th>
                                        <th style="width: 40%;">Volunteer Credits Value</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($creditSettings)): ?>
                                        <tr>
                                            <td colspan="2" style="text-align: center; color: var(--color-text-secondary); padding: 20px;">
                                                No volunteer credit records found.
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($creditSettings as $setting): ?>
                                            <tr>
                                                <td style="font-weight: 600; color: #fff;">
                                                    <?php echo e($setting['credit_label']); ?>
                                                </td>
                                                <td>
                                                    <input type="number" 
                                                        class="credits-input" 
                                                        name="credits[<?php echo e($setting['credit_key']); ?>]" 
                                                        value="<?php echo (float)$setting['credits']; ?>" 
                                                        step="0.1" 
                                                        min="0" 
                                                        required>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php if (!empty($creditSettings)): ?>
                            <div style="text-align: left;">
                                <button type="submit" class="btn btn-success" style="padding: 10px 24px;">Save Credit Settings</button>
                            </div>
                        <?php endif; ?>
                    </form>
                </section>
            </div>
        </main>

        <footer class="app-footer">
            <p>&copy; <?php echo date('Y'); ?> TGG Club Membership System. Secure Public Portal.</p>
        </footer>
    </div>
</body>
</html>
