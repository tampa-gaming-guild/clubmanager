<?php
/**
 * Admin Import Tool
 * Triggers direct database sync of contacts/members from CiviCRM.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config/bootstrap.php';

use App\Auth;
use App\CiviCRMImporter;

Auth::requireAdmin();

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
            $errorMsg = "Import failed: " . $e->getMessage();
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
                        <li><a href="import.php" class="active">CiviCRM Importer</a></li>
                        <li><a href="reports.php">Reports & Analytics</a></li>
                    </ul>
                </aside>

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
                                    <p><strong>Note:</strong> Importing will automatically register CiviCRM contacts into the local tracking database, allowing them to log in to this portal using their CiviCRM emails. New imports default to a password of <code>change_me_123</code> which they must change upon logging in.</p>
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
