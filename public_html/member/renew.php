<?php
/**
 * Member Renewal Page
 * Displays current membership details and directs members to Stripe for renewal payment.
 */
require_once dirname(dirname(__DIR__)) . '/config/bootstrap.php';

use App\Database;
use App\CiviCRMImporter;
use App\StripeHelper;
use App\Auth;

Auth::requireAuth();

$contactId = $_SESSION['user']['contact_id'];
$errorMsg = null;
$successMsg = null;
$membership = null;
$tiers = [];

try {
    $membership = CiviCRMImporter::getMemberMembershipDetails($contactId);
    $tiers = CiviCRMImporter::getMembershipTiers();
} catch (Exception $e) {
    $errorMsg = "Unable to fetch membership details: " . $e->getMessage();
}

// 1. Handle Successful Redirect from Stripe
if (isset($_GET['status']) && $_GET['status'] === 'success' && isset($_GET['session_id'])) {
    $sessionId = $_GET['session_id'];
    try {
        $session = StripeHelper::retrieveCheckoutSession($sessionId);
        if ($session['payment_status'] === 'paid') {
            $successMsg = "Your renewal payment of $" . number_format($session['amount_total'] / 100, 2) . " was processed successfully! Your membership status is updated.";
            
            // Reload membership details
            $membership = CiviCRMImporter::getMemberMembershipDetails($contactId);
        } else {
            $errorMsg = "Payment verification is pending.";
        }
    } catch (Exception $e) {
        $errorMsg = "Verification error: " . $e->getMessage();
    }
}

// 2. Handle Cancelled Redirect from Stripe
if (isset($_GET['status']) && $_GET['status'] === 'cancelled') {
    $errorMsg = "Renewal process cancelled. No changes have been made.";
}

// 3. Handle Renewal Request Form POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_GET['status'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errorMsg = "Invalid security token. Please try again.";
    } else {
        $tierId = (int)($_POST['tier_id'] ?? 0);
        if (empty($tierId)) {
            $errorMsg = "Please select a membership level.";
        } else {
            try {
                // Find chosen tier info
                $tierIndex = array_search($tierId, array_column($tiers, 'id'));
                if ($tierIndex === false) {
                    throw new Exception("Invalid membership tier selected.");
                }
                $tier = $tiers[$tierIndex];
                $fee = (float)$tier['minimum_fee'];
                $tierName = $tier['name'];

                // Create Checkout Session
                $session = StripeHelper::createCheckoutSession($contactId, $tierId, $tierName, $fee, 'renew');
                
                header("Location: " . $session['url']);
                exit;

            } catch (Exception $e) {
                $errorMsg = "Failed to start renewal process: " . $e->getMessage();
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
    <title>Renew Membership - TGG Member Portal</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <div class="app-container">
        <header class="navbar">
            <div class="logo">TGG Members</div>
            <nav class="nav-links">
                <a href="index.php">Dashboard</a>
                <a href="calendar.php">Calendar</a>
                <a href="checkin.php">Check-In</a>
                <?php if (has_role('admin')): ?>
                    <a href="admin/dashboard.php">Admin</a>
                <?php endif; ?>
                <a href="index.php?action=logout" class="btn-logout">Logout</a>
            </nav>
        </header>

        <main class="main-content centered-content">
            <div class="auth-panel glass-panel">
                <h2>Membership Renewal</h2>
                <p class="subtitle">Keep your club benefits active by renewing your subscription.</p>

                <?php if ($errorMsg): ?>
                    <div class="alert alert-danger"><?php echo e($errorMsg); ?></div>
                <?php endif; ?>

                <?php if ($successMsg): ?>
                    <div class="alert alert-success"><?php echo e($successMsg); ?></div>
                <?php endif; ?>

                <!-- Current Membership Status Box -->
                <div class="current-membership-box">
                    <h3>Current Status</h3>
                    <?php if ($membership): ?>
                        <table class="status-table">
                            <tr>
                                <td><strong>Level:</strong></td>
                                <td><?php echo e($membership['membership_name']); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Status:</strong></td>
                                <td>
                                    <span class="badge badge-status <?php echo $membership['is_active'] ? 'badge-active' : 'badge-expired'; ?>">
                                        <?php echo e($membership['status_label']); ?>
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Join Date:</strong></td>
                                <td><?php echo date('F j, Y', strtotime($membership['join_date'])); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Current Expires:</strong></td>
                                <td>
                                    <strong class="<?php echo strtotime($membership['end_date']) < time() ? 'text-danger' : ''; ?>">
                                        <?php echo date('F j, Y', strtotime($membership['end_date'])); ?>
                                    </strong>
                                </td>
                            </tr>
                        </table>
                    <?php else: ?>
                        <div class="alert alert-info">
                            No active membership record found. You can choose a tier below to join.
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Renewal Form -->
                <form action="renew.php" method="POST" class="auth-form mt-20">
                    <input type="hidden" name="csrf_token" value="<?php echo e(get_csrf_token()); ?>">
                    
                    <div class="form-group">
                        <label for="tier_id">Select Renewal Level</label>
                        <select id="tier_id" name="tier_id" required>
                            <option value="" disabled selected>-- Select a Level --</option>
                            <?php foreach ($tiers as $tier): ?>
                                <option value="<?php echo (int)$tier['id']; ?>" 
                                    <?php echo ($membership && $membership['membership_name'] === $tier['name']) ? 'selected' : ''; ?>>
                                    <?php echo e($tier['name']); ?> - $<?php echo number_format($tier['minimum_fee'], 2); ?> / <?php echo e($tier['duration_interval']); ?> <?php echo e($tier['duration_unit']); ?>(s)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="info-block mt-10">
                        <p><strong>Note:</strong> Pressing "Pay Renewal Dues" will route you to Stripe Checkout. Once complete, your expiry date will be extended from your current expiration date or from today, whichever is later, based on the tier duration.</p>
                    </div>

                    <button type="submit" class="btn btn-primary btn-block">Pay Renewal Dues</button>
                </form>
            </div>
        </main>

        <footer class="app-footer">
            <p>&copy; <?php echo date('Y'); ?> TGG Club Membership System. Secure Public Portal.</p>
        </footer>
    </div>
</body>
</html>
