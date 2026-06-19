<?php
/**
 * Host Portal Dashboard
 * Accessible by members with "edit checkins" permission (Hosts and Admins).
 */
require_once dirname(dirname(__DIR__)) . '/config/bootstrap.php';

use App\Auth;
use App\Database;

// Enforce permission
Auth::requirePermission('edit checkins');

$errorMsg = null;
$successMsg = null;

// Handle search/redirect for check-in or renewal overrides
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errorMsg = "Invalid security token.";
    } else {
        $action = $_POST['action'] ?? '';
        $identifier = trim($_POST['identifier'] ?? '');
        
        if (empty($identifier)) {
            $errorMsg = "Please enter an Email or Member ID.";
        } else {
            try {
                $appDb = Database::getAppConnection();
                $contactId = 0;
                
                if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
                    $stmt = $appDb->prepare("SELECT id FROM tgg_contacts WHERE email = :email AND is_deleted = 0 LIMIT 1");
                    $stmt->execute(['email' => strtolower($identifier)]);
                    $contactId = (int)($stmt->fetchColumn() ?: 0);
                } else if (is_numeric($identifier)) {
                    $stmt = $appDb->prepare("SELECT id FROM tgg_contacts WHERE id = :id AND is_deleted = 0 LIMIT 1");
                    $stmt->execute(['id' => $identifier]);
                    $contactId = (int)($stmt->fetchColumn() ?: 0);
                }
                
                if ($contactId <= 0) {
                    $errorMsg = "Member not found. Please check the Email or Member ID.";
                } else {
                    if ($action === 'renew') {
                        redirect("renew.php?contact_id={$contactId}");
                    } else if ($action === 'checkin') {
                        redirect("host_checkin.php?contact_id={$contactId}");
                    }
                }
            } catch (Exception $e) {
                $errorMsg = safe_err("Lookup error: ", $e);
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Host Portal - Club Management</title>
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <link rel="icon" type="image/png" href="favicon.png">
    <link rel="stylesheet" href="assets/css/style.css<?php echo asset_version('assets/css/style.css'); ?>">
</head>
<body>
    <div class="app-container">
        <?php $navActive = 'host'; include __DIR__ . '/partials/navbar.php'; ?>

        <main class="main-content centered-content">
            <?php if ($errorMsg): ?>
                <div class="alert alert-danger" style="max-width: 600px; margin: 10px auto;"><?php echo e($errorMsg); ?></div>
            <?php endif; ?>

            <div class="dashboard-panel glass-panel" style="max-width: 800px; width: 100%;">
                <div class="dashboard-header" style="border-bottom: 1px solid rgba(255, 255, 255, 0.1); padding-bottom: 20px; margin-bottom: 25px;">
                    <h2>Host Portal Dashboard</h2>
                    <span class="user-role-badge" style="background: var(--color-success); color: #fff;">Host Active</span>
                </div>
                
                <p class="description-text" style="color: var(--color-text-secondary); font-size: 1.05rem; line-height: 1.6; margin-bottom: 30px;">
                    Welcome, <?php echo e($_SESSION['user']['display_name']); ?>! As a host, you can perform quick daily operations such as checking in guests and managing member renewals.
                </p>

                <div class="dashboard-grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px;">
                    
                    <!-- Task 1: Check-in Someone Else -->
                    <div class="dashboard-card" style="display: flex; flex-direction: column; justify-content: space-between;">
                        <div>
                            <div style="font-size: 2rem; margin-bottom: 15px;">🎟️</div>
                            <h3 style="margin-bottom: 10px;">Check In Someone Else</h3>
                            <p style="font-size: 0.9rem; color: var(--color-text-secondary); line-height: 1.4; margin-bottom: 15px;">
                                Record a visit for a member using their email, ID, or name.
                            </p>
                        </div>
                        <a href="host_checkin.php" class="btn btn-primary btn-block" style="text-align: center;">Go to Host Check-In</a>
                    </div>

                    <!-- Task 2: Volunteer Section -->
                    <div class="dashboard-card" style="display: flex; flex-direction: column; justify-content: space-between;">
                        <div>
                            <div style="font-size: 2rem; margin-bottom: 15px;">🤝</div>
                            <h3 style="margin-bottom: 10px;">Volunteer Roster</h3>
                            <p style="font-size: 0.9rem; color: var(--color-text-secondary); line-height: 1.4; margin-bottom: 15px;">
                                View scheduled shifts (Open, Close, Bouncer) and signup status.
                            </p>
                        </div>
                        <a href="volunteers.php" class="btn btn-secondary btn-block" style="text-align: center;">View Volunteers</a>
                    </div>

                </div>

                <!-- Quick Member Lookup Tool -->
                <div class="admin-override-section" style="margin-top: 40px; padding-top: 30px; border-top: 1px solid rgba(255,255,255,0.1);">
                    <h3 style="font-size: 1.25rem; color: #fff; margin-bottom: 10px; font-family: var(--font-heading);">Quick Member Actions</h3>
                    <p style="color: var(--color-text-secondary); font-size: 0.9rem; margin-bottom: 20px;">
                        Quickly redirect to checking in or renewing a specific member:
                    </p>
                    
                    <form action="host.php" method="POST" autocomplete="off" style="display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end;">
                        <input type="hidden" name="csrf_token" value="<?php echo e(get_csrf_token()); ?>">
                        
                        <div class="form-group" style="flex: 1; min-width: 250px; margin-bottom: 0;">
                            <label for="identifier" style="font-size: 0.85rem; color: var(--color-text-secondary); margin-bottom: 6px; display: block;">Member Email or ID</label>
                            <input type="text" id="identifier" name="identifier" required placeholder="Enter Email or Member ID..." 
                                   style="background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.15); color: #fff; padding: 10px; border-radius: 6px; width: 100%; box-sizing: border-box;">
                        </div>
                        
                        <div style="display: flex; gap: 10px;">
                            <button type="submit" name="action" value="checkin" class="btn btn-primary" style="padding: 10px 15px; border-radius: 6px; font-weight: 600;">Check-In</button>
                            <button type="submit" name="action" value="renew" class="btn btn-secondary" style="padding: 10px 15px; border-radius: 6px; font-weight: 600;">Renew</button>
                        </div>
                    </form>
                </div>

            </div>
        </main>

        <?php include __DIR__ . '/partials/footer.php'; ?>
    </div>
</body>
</html>
