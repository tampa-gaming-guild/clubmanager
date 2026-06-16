<?php
/**
 * Admin Import Tool
 * Triggers direct database sync of contacts/members from CiviCRM.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config/bootstrap.php';

use App\Auth;
use App\CiviCRMImporter;

Auth::requireAdmin();
Auth::requirePermission('all');

$connTest = CiviCRMImporter::testConnections();
$syncResult = null;
$errorMsg = null;

// Handle Trigger Import
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errorMsg = "Invalid security token. Please reload the page and try again.";
    } else {
        try {
            $syncResult = CiviCRMImporter::runSync();
        } catch (Exception $e) {
            $errorMsg = safe_err("Import failed: ", $e);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CiviCRM Data Import - Admin Dashboard</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <div class="app-container">
        <!-- Sidebar or Nav -->
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
                <?php include 'sidebar.php'; ?>

                <!-- Importer Work Area -->
                <section class="admin-workspace glass-panel">
                    <h2>CiviCRM Live Data Importer</h2>
                    <p class="description-text">
                        Connects directly to your WordPress CiviCRM tables to pull in contacts, membership statuses, and sync settings locally.
                    </p>

                    <!-- Connection Status Badges -->
                    <div class="connection-status">
                        <div class="status-badge <?php echo $connTest['app'] ? 'status-ok' : 'status-fail'; ?>">
                            <span>Local DB Connection:</span>
                            <strong><?php echo $connTest['app'] ? 'CONNECTED' : 'DISCONNECTED'; ?></strong>
                        </div>
                        <div class="status-badge <?php echo $connTest['civi'] ? 'status-ok' : 'status-fail'; ?>">
                            <span>CiviCRM DB Connection:</span>
                            <strong><?php echo $connTest['civi'] ? 'CONNECTED' : 'DISCONNECTED'; ?></strong>
                        </div>
                    </div>

                    <?php if ($errorMsg): ?>
                        <div class="alert alert-danger"><?php echo e($errorMsg); ?></div>
                    <?php endif; ?>

                    <?php if ($syncResult): ?>
                        <div class="alert alert-success">
                            <h4>Import Complete!</h4>
                            <ul>
                                <li>Contacts Scanned: <strong><?php echo (int)$syncResult['contacts_scanned']; ?></strong></li>
                                <li>Local Credentials Provisioned (New): <strong><?php echo (int)$syncResult['settings_created']; ?></strong></li>
                                <li>Local Credentials Verified (Existing): <strong><?php echo (int)$syncResult['settings_updated']; ?></strong></li>
                            </ul>
                            <?php if (!empty($syncResult['errors'])): ?>
                                <div class="sync-errors">
                                    <h5>Import Warnings (<?php echo count($syncResult['errors']); ?>):</h5>
                                    <pre class="error-log"><?php foreach ($syncResult['errors'] as $err) echo e($err) . "\n"; ?></pre>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Action Panel -->
                    <div class="action-panel">
                        <?php if ($connTest['app'] && $connTest['civi']): ?>
                            <form action="import.php" method="POST" class="sync-form">
                                <input type="hidden" name="csrf_token" value="<?php echo e(get_csrf_token()); ?>">
                                <div class="info-block">
                                    <p><strong>Note:</strong> Importing will automatically register CiviCRM contacts into the local tracking database, allowing them to log in to this portal using their CiviCRM emails. New accounts are initialized with a random secure token. Users must use the <strong>Forgot Password</strong> flow on the login portal to set their password for the first time.</p>
                                </div>
                                <button type="submit" class="btn btn-primary btn-large">Start CiviCRM Synchronization</button>
                            </form>
                        <?php else: ?>
                            <div class="alert alert-warning">
                                <p>Cannot run synchronization because one or more database connections are failing. Please check your <code>.env</code> file credentials.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>
            </div>
        </main>

        <footer class="app-footer">
            <p>&copy; <?php echo date('Y'); ?> TGG Club Membership System. Secure Public Portal.</p>
        </footer>
    </div>
</body>
</html>
