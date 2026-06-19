<?php
/**
 * Main Portal Homepage & Login Gateway
 * Routes logged-out users to authentication and logged-in members to their dashboard.
 */
require_once dirname(dirname(__DIR__)) . '/config/bootstrap.php';

use App\Auth;
use App\CiviCRMImporter;

$errorMsg = null;
$successMsg = null;

// Handle Logout Action
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    if (verify_csrf_token($_GET['csrf_token'] ?? '')) {
        Auth::logout();
        redirect('index.php?loggedout=1');
    } else {
        http_response_code(403);
        die("CSRF validation failed on logout.");
    }
}

// Handle Stop Impersonating Action
if (isset($_GET['action']) && $_GET['action'] === 'stop_impersonating') {
    if (Auth::stopImpersonating()) {
        redirect('admin/roles.php?success=impersonation_stopped');
    } else {
        redirect('index.php');
    }
}

if (isset($_GET['loggedout'])) {
    $successMsg = "You have been logged out successfully.";
}

if (isset($_GET['success'])) {
    $successMsg = trim($_GET['success']);
}

if (isset($_GET['renew_success'])) {
    $amount = isset($_GET['amount']) ? (float)$_GET['amount'] : 0.00;
    $successMsg = "Thank you! Your renewal payment " . ($amount > 0 ? "of $" . number_format($amount, 2) : "") . " was processed successfully. Please sign in to view your updated status.";
}

if (isset($_GET['error'])) {
    if ($_GET['error'] === 'unauthorized') {
        $errorMsg = "Access denied. You do not have permission to view that page.";
    } else {
        $errorMsg = trim($_GET['error']);
    }
}

// Handle Login POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_submit'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errorMsg = "Invalid security token. Please try again.";
    } else {
        $email = $_POST['email'] ?? '';
        $password = $_POST['password'] ?? '';
        
        try {
            if (Auth::login($email, $password)) {
                // Redirect to previous page if set, or homepage
                $redirectUrl = $_SESSION['redirect_after_login'] ?? 'index.php';
                unset($_SESSION['redirect_after_login']);

                // Validate the redirect stays on-site (prevent Open Redirect vulnerability)
                $parsed = parse_url($redirectUrl);
                $allowedHost = parse_url($_ENV['BASE_URL'] ?? '', PHP_URL_HOST) ?: $_SERVER['HTTP_HOST'];
                if (empty($parsed['host']) || $parsed['host'] === $allowedHost) {
                    header("Location: " . $redirectUrl);
                } else {
                    header("Location: index.php");
                }
                exit;
            } else {
                $errorMsg = "Invalid email or password. Please check your credentials.";
            }
        } catch (Exception $e) {
            $errorMsg = safe_err("Login system error: ", $e);
        }
    }
}

// Load current membership if logged in
$membership = null;
if (Auth::check()) {
    try {
        $membership = CiviCRMImporter::getMemberMembershipDetails($_SESSION['user']['contact_id']);
    } catch (Exception $e) {
        // Silent fail for membership fetch in dashboard
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Member Portal - Club Management</title>
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <link rel="icon" type="image/png" href="favicon.png">
    <link rel="apple-touch-icon" href="favicon.png">
    <link rel="manifest" href="manifest.json">
    <link rel="stylesheet" href="assets/css/style.css<?php echo asset_version('assets/css/style.css'); ?>">
</head>
<body>
    <div class="app-container">
        <?php $navActive = Auth::check() ? 'dashboard' : 'login'; include __DIR__ . '/partials/navbar.php'; ?>

        <main class="main-content centered-content">
            <?php if ($errorMsg): ?>
                <div class="alert alert-danger" style="max-width: 450px; margin: 10px auto;"><?php echo e($errorMsg); ?></div>
            <?php endif; ?>

            <?php if ($successMsg): ?>
                <div class="alert alert-success" style="max-width: 450px; margin: 10px auto;"><?php echo e($successMsg); ?></div>
            <?php endif; ?>

            <?php if (Auth::check()): ?>
                <!-- LOGGED IN USER DASHBOARD -->
                <div class="dashboard-panel glass-panel">
                    <div class="dashboard-header">
                        <h2>Welcome Back, <?php echo e($_SESSION['user']['display_name']); ?>!</h2>
                        <span class="user-role-badge"><?php echo e(ucfirst($_SESSION['user']['role'])); ?> Portal</span>
                    </div>

                    <div class="dashboard-grid">
                        <!-- Quick Stats / Status -->
                        <div class="dashboard-card status-card">
                            <h3>Membership Status</h3>
                            <?php if ($membership): ?>
                                 <div class="status-summary" style="display: flex; flex-direction: column; align-items: flex-start; gap: 5px;">
                                     <span class="membership-level" style="font-size: 0.9rem;">
                                         <?php 
                                         echo e($membership['membership_name']); 
                                         $showRate = Auth::check() && (
                                             true // The user logged in always owns their dashboard view
                                             || has_role('host') || has_role('admin') || has_role('superadmin')
                                         );
                                         if ($showRate && isset($membership['minimum_fee'])) {
                                             $formattedPrice = '$' . number_format($membership['minimum_fee'], 2);
                                             $intervalText = '';
                                             if (isset($membership['duration_unit'])) {
                                                 $unit = strtolower($membership['duration_unit']);
                                                 if ($unit === 'year') $unit = 'annual';
                                                 elseif ($unit === 'month') $unit = 'monthly';
                                                 elseif ($unit === 'day') $unit = 'daily';
                                                 
                                                 $intervalText = ' / ' . $unit;
                                             }
                                             echo ' <span style="color: var(--color-text-muted); font-size: 0.9em; font-weight: normal;">' . e("({$formattedPrice}{$intervalText})") . '</span>';
                                         }
                                         ?>
                                     </span>
                                     <span class="badge badge-status <?php echo $membership['is_active'] ? 'badge-active' : 'badge-expired'; ?>">
                                         <?php echo e($membership['status_label']); ?>
                                     </span>
                                 </div>
                                <div class="status-dates">
                                    <p>Joined: <span><?php echo date('M d, Y', strtotime($membership['join_date'])); ?></span></p>
                                    <p>Expires: <span class="<?php echo strtotime($membership['end_date']) < time() ? 'text-danger' : ''; ?>"><?php echo date('M d, Y', strtotime($membership['end_date'])); ?></span></p>
                                </div>
                                <?php if (!$membership['is_active'] || strtotime($membership['end_date']) < strtotime('+30 days')): ?>
                                    <a href="renew.php" class="btn btn-warning btn-block mt-10">Renew Membership Now</a>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="status-summary">
                                    <span class="membership-level">No Active Membership</span>
                                </div>
                                <p class="description-text">Sign up for a membership tier to access member benefits.</p>
                                <a href="renew.php" class="btn btn-primary btn-block mt-10">Purchase Membership</a>
                            <?php endif; ?>
                        </div>

                        <!-- Core Navigation Actions -->
                        <div class="dashboard-card actions-card">
                            <h3>Quick Actions</h3>
                            <div class="action-buttons-list">
                                <a href="profile.php?id=<?php echo (int)$_SESSION['user']['contact_id']; ?>" class="action-btn">
                                    <span class="icon">👤</span>
                                    <div class="btn-text">
                                        <strong>My Profile</strong>
                                        <span>Manage privacy & contact details</span>
                                    </div>
                                </a>
                                <a href="calendar.php" class="action-btn">
                                    <span class="icon">📅</span>
                                    <div class="btn-text">
                                        <strong>Club Calendar</strong>
                                        <span>Schedule of events & volunteer signups</span>
                                    </div>
                                </a>
                                <a href="checkin.php" class="action-btn">
                                    <span class="icon">🎟️</span>
                                    <div class="btn-text">
                                        <strong>Check-In</strong>
                                        <span>Record a club visit or attendance</span>
                                    </div>
                                </a>
                                <?php if (has_role('admin')): ?>
                                    <a href="admin/dashboard.php" class="action-btn admin-action-btn">
                                        <span class="icon">⚙️</span>
                                        <div class="btn-text">
                                            <strong>Admin Dashboard</strong>
                                            <span>Manage members, events & reports</span>
                                        </div>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

            <?php else: ?>
                <!-- LOGGED OUT LOGIN FORM -->
                <div class="auth-panel glass-panel">
                    <h2>Member Portal</h2>
                    <p class="subtitle">Access your membership details, events, and schedules.</p>

                    <form action="index.php" method="POST" class="auth-form">
                        <input type="hidden" name="csrf_token" value="<?php echo e(get_csrf_token()); ?>">
                        
                        <div class="form-group">
                            <label for="email">Email Address</label>
                            <input type="email" id="email" name="email" required placeholder="member@example.com">
                        </div>

                        <div class="form-group">
                            <label for="password">Password</label>
                            <div class="password-toggle-wrapper">
                                <input type="password" id="password" name="password" required placeholder="••••••••">
                                <span class="password-toggle-icon" onclick="togglePasswordVisibility('password')">👁️</span>
                            </div>
                        </div>

                        <button type="submit" name="login_submit" class="btn btn-primary btn-block">Sign In</button>
                    </form>

                    <div class="auth-footer">
                        <p>Not a member yet? <a href="join.php">Join the club today</a></p>
                        <p>Forgot password? <a href="forgot-password.php">Reset it here</a></p>
                        <p>Need to check-in? <a href="checkin.php">Check-In Portal</a></p>
                    </div>
                </div>
            <?php endif; ?>
        </main>

        <?php include __DIR__ . '/partials/footer.php'; ?>
    <script>
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('sw.js')
                .then(reg => console.log('Service Worker registered'))
                .catch(err => console.error('Service Worker registration failed', err));
        });
    }
    </script>
</body>
</html>
