<?php
/**
 * Member Portal Dashboard & Authentication Gateway
 * Handles logged-in member dashboard (Hosting View vs Standard View),
 * admin snapshots, login processing, and authentication forms.
 */
require_once dirname(dirname(__DIR__)) . '/config/bootstrap.php';

use App\Auth;
use App\BillingHelper;
use App\MembershipService;
use App\Database;
use App\Event;

$errorMsg = null;
$successMsg = null;

$wantsLogin = isset($_GET['action']) && $_GET['action'] === 'login';

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
        redirect('portal.php');
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
                $redirectUrl = $_SESSION['redirect_after_login'] ?? 'portal.php';
                unset($_SESSION['redirect_after_login']);

                $parsed = parse_url($redirectUrl);
                $allowedHost = parse_url($_ENV['BASE_URL'] ?? '', PHP_URL_HOST) ?: $_SERVER['HTTP_HOST'];
                if (empty($parsed['host']) || $parsed['host'] === $allowedHost) {
                    header("Location: " . $redirectUrl);
                } else {
                    header("Location: portal.php");
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

// If user is logged out and didn't request login, redirect to login view on portal.php
if (!Auth::check() && !$wantsLogin) {
    // If accessing portal.php directly logged out, show login form
    $wantsLogin = true;
}

// Handle Quick Member Actions lookup
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['member_lookup_action'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errorMsg = "Invalid security token.";
    } elseif (!has_permission('edit checkins')) {
        $errorMsg = "You do not have permission to perform this action.";
    } else {
        $action = $_POST['member_lookup_action'] ?? '';
        $identifier = trim($_POST['identifier'] ?? '');

        if (empty($identifier)) {
            $errorMsg = "Please enter an Email, Phone Number, or Member ID.";
        } else {
            try {
                $appDb = Database::getAppConnection();
                $contactId = 0;

                if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
                    $stmt = $appDb->prepare("SELECT id FROM tgg_contacts WHERE email = :email AND is_deleted = 0 LIMIT 1");
                    $stmt->execute(['email' => strtolower($identifier)]);
                    $contactId = (int)($stmt->fetchColumn() ?: 0);
                } elseif (strlen(normalize_phone($identifier)) === 10) {
                    $digits = normalize_phone($identifier);
                    $stmt = $appDb->prepare("SELECT id FROM tgg_contacts WHERE REGEXP_REPLACE(phone, '[^0-9]', '') = :phone AND is_deleted = 0");
                    $stmt->execute(['phone' => $digits]);
                    $phoneRows = $stmt->fetchAll();
                    if (count($phoneRows) === 1) {
                        $contactId = (int)$phoneRows[0]['id'];
                    } elseif (count($phoneRows) > 1) {
                        $errorMsg = "Multiple accounts share that phone number. Please use email or member ID.";
                    }
                } elseif (is_numeric($identifier)) {
                    $stmt = $appDb->prepare("SELECT id FROM tgg_contacts WHERE id = :id AND is_deleted = 0 LIMIT 1");
                    $stmt->execute(['id' => $identifier]);
                    $contactId = (int)($stmt->fetchColumn() ?: 0);
                }

                if ($contactId <= 0 && empty($errorMsg)) {
                    $errorMsg = "Member not found. Please check the email, phone number, or member ID.";
                } else if ($action === 'renew') {
                    redirect("renew.php?contact_id={$contactId}");
                } else if ($action === 'checkin') {
                    redirect("host_checkin.php?contact_id={$contactId}");
                } else if ($action === 'manage') {
                    redirect("profile.php?id={$contactId}");
                }
            } catch (Exception $e) {
                $errorMsg = safe_err("Lookup error: ", $e);
            }
        }
    }
}

// Handle Add Member submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_member'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errorMsg = "Invalid security token.";
    } elseif (!has_permission('edit checkins')) {
        $errorMsg = "You do not have permission to perform this action.";
    } else {
        try {
            $trialPlan = BillingHelper::getTrialPlan();
            if (!$trialPlan) {
                throw new Exception("No Trial membership plan is configured.");
            }

            $result = BillingHelper::addMember(
                $_POST['first_name'] ?? '',
                $_POST['last_name'] ?? '',
                $_POST['email'] ?? '',
                $_POST['phone'] ?? '',
                (int)$trialPlan['id'],
                '',
                true,
                $_SESSION['user']['contact_id'] ?? null
            );

            $appDb = Database::getAppConnection();
            $insertCheckin = $appDb->prepare("INSERT INTO tgg_checkins (contact_id, checked_in_at, notes) VALUES (:contact_id, NOW(), :notes)");
            $insertCheckin->execute([
                'contact_id' => $result['contact_id'],
                'notes' => 'New member -- Trial signup via Add Member'
            ]);

            redirect('portal.php?success=' . urlencode("{$result['display_name']} was added with a free Trial membership and checked in!"));
        } catch (Exception $e) {
            $errorMsg = safe_err("Failed to add member: ", $e);
        }
    }
}

// Handle Check-In deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_checkin'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errorMsg = "Invalid security token.";
    } elseif (!has_permission('edit checkins')) {
        $errorMsg = "You do not have permission to perform this action.";
    } else {
        $checkinId = (int)($_POST['checkin_id'] ?? 0);
        if ($checkinId > 0) {
            try {
                $appDb = Database::getAppConnection();
                $deleteStmt = $appDb->prepare("DELETE FROM tgg_checkins WHERE id = :id");
                $deleteStmt->execute(['id' => $checkinId]);
                redirect('portal.php?success=' . urlencode('Check-in deleted successfully.'));
            } catch (Exception $e) {
                $errorMsg = safe_err("Delete error: ", $e);
            }
        } else {
            $errorMsg = "Invalid check-in ID.";
        }
    }
}

// Load current membership if logged in
$membership = null;
if (Auth::check()) {
    try {
        $membership = MembershipService::getMemberMembershipDetails($_SESSION['user']['contact_id']);
    } catch (Exception $e) {
        // Silent fail
    }
}

// Detect active hosting session
$activeSession = null;
$isHostingNow = false;
$canSwitchToHosting = false;
if (Auth::check()) {
    try {
        $activeSession = Event::getActiveSession();
        if ($activeSession && has_permission('edit checkins')) {
            $rolesToday = Event::getMemberRolesForEvent((int)$activeSession['id'], (int)$_SESSION['user']['contact_id']);
            $isHostingNow = !empty($rolesToday);
            $canSwitchToHosting = !$isHostingNow;
        }
    } catch (Exception $e) {
        // Silent fail
    }
}

$wantsStandardView = ($_GET['view'] ?? '') === 'standard';
$wantsHostingView  = ($_GET['view'] ?? '') === 'hosting';
$showHostingView = ($isHostingNow || ($canSwitchToHosting && $wantsHostingView)) && !$wantsStandardView;

$todaysCheckins = [];
$pendingPayments = [];
if ($showHostingView) {
    try {
        $todaysCheckins = Event::getTodaysCheckins();
    } catch (Exception $e) {
        $todaysCheckins = [];
    }
    try {
        $appDb = Database::getAppConnection();
        $pendingStmt = $appDb->query("
            SELECT pp.id, pp.contact_id, pp.type, pp.amount, pp.requested_at, c.display_name
            FROM tgg_pending_payments pp
            LEFT JOIN tgg_contacts c ON c.id = pp.contact_id
            WHERE pp.status = 'pending'
            ORDER BY pp.requested_at ASC
        ");
        $pendingPayments = $pendingStmt->fetchAll();
    } catch (Exception $e) {
        $pendingPayments = [];
    }
}

// Admin snapshot data
$checkinsToday = 0;
$totalContacts = 0;
$monthRevenue = 0.00;
$hasEventToday = false;
$statuses = [];
$matrix = [];
if (Auth::check() && has_permission('admin panel')) {
    try {
        $appDb = Database::getAppConnection();
        $hasEventToday = (bool)$appDb->query("SELECT COUNT(*) FROM tgg_events WHERE DATE(start_time) = CURDATE()")->fetchColumn();
        if ($hasEventToday && has_permission('edit checkins')) {
            $checkinsToday = (int)$appDb->query("SELECT COUNT(*) FROM tgg_checkins WHERE DATE(checked_in_at) = CURRENT_DATE()")->fetchColumn();
        }
        $totalContacts = (int)$appDb->query("SELECT COUNT(*) FROM tgg_contacts WHERE is_deleted = 0")->fetchColumn();
        if (has_permission('process payments')) {
            $monthRevenue = (float)$appDb->query("
                SELECT SUM(amount) FROM tgg_billing_ledger
                WHERE MONTH(created_at) = MONTH(CURRENT_DATE())
                  AND YEAR(created_at) = YEAR(CURRENT_DATE())
                  AND payment_status = 'paid'
            ")->fetchColumn();
        }

        $tiers = MembershipService::getMembershipTiers();
        $statuses = $appDb->query("
            SELECT id, name, label, is_active
            FROM tgg_membership_statuses
            WHERE label NOT IN ('Deceased', 'Current Renewed', 'Future Start')
              AND name NOT IN ('Deceased', 'Current Renewed', 'Future Start')
            ORDER BY id ASC
        ")->fetchAll();

        foreach ($tiers as $tier) {
            $matrix[$tier['name']] = [];
            foreach ($statuses as $stat) {
                $matrix[$tier['name']][$stat['label']] = 0;
            }
        }

        $allMembers = MembershipService::getMembersList();
        foreach ($allMembers as $m) {
            $lvl = $m['membership_name'];
            $stat = $m['status_label'];
            if ($lvl && $stat) {
                if (!isset($matrix[$lvl])) {
                    $matrix[$lvl] = [];
                    foreach ($statuses as $s) {
                        $matrix[$lvl][$s['label']] = 0;
                    }
                }
                if (!isset($matrix[$lvl][$stat])) {
                    $matrix[$lvl][$stat] = 0;
                }
                $matrix[$lvl][$stat]++;
            }
        }
        ksort($matrix);
    } catch (Exception $e) {
        // Silent fail
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo Auth::check() ? 'Member Portal - Club Management' : 'Login - Tampa Gaming Guild'; ?></title>
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <link rel="icon" type="image/png" href="favicon.png">
    <link rel="apple-touch-icon" href="favicon.png">
    <link rel="manifest" href="manifest.json">
    <link rel="stylesheet" href="assets/css/style.css<?php echo asset_version('assets/css/style.css'); ?>">
    <link rel="stylesheet" href="assets/css/marketing.css<?php echo asset_version('assets/css/marketing.css'); ?>">
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
                        <?php if ($showHostingView): ?>
                            <h2><?php echo e($_SESSION['user']['display_name']); ?>, Hosting: <?php echo e($activeSession['title']); ?></h2>
                            <span class="user-role-badge"><?php echo date('g:i A', strtotime($activeSession['start_time'])); ?> &ndash; <?php echo date('g:i A', strtotime($activeSession['end_time'])); ?></span>
                        <?php else: ?>
                            <h2>Welcome Back, <?php echo e($_SESSION['user']['display_name']); ?>!</h2>
                            <span class="user-role-badge"><?php echo e(ucfirst($_SESSION['user']['role'])); ?> Portal</span>
                        <?php endif; ?>
                    </div>

                    <?php if ($showHostingView): ?>
                        <!-- HOSTING VIEW -->
                        <div class="hosting-view-stack">
                            <div class="dashboard-grid">
                                <div class="stat-card glass-panel border-left-orange">
                                    <span class="stat-icon">🎟️</span>
                                    <div class="stat-vals">
                                        <strong><?php echo count($todaysCheckins); ?></strong>
                                        <span>Check-Ins Today</span>
                                    </div>
                                </div>

                                <div class="dashboard-card">
                                    <h3>Quick Member Actions</h3>
                                    <p style="color: var(--color-text-secondary); font-size: 0.9rem; margin-bottom: 12px;">
                                        Check in, renew, or manage a member by email, phone, or ID:
                                    </p>
                                    <form action="portal.php" method="POST" autocomplete="off" style="display: flex; flex-wrap: wrap; gap: 10px;">
                                        <input type="hidden" name="csrf_token" value="<?php echo e(get_csrf_token()); ?>">
                                        <input type="text" id="identifier" name="identifier" required placeholder="Enter email, phone, or ID…"
                                               style="background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.15); color: #fff; padding: 10px; border-radius: 6px; width: 100%; box-sizing: border-box;">
                                        <div style="display: flex; gap: 10px; width: 100%;">
                                            <button type="submit" name="member_lookup_action" value="checkin" class="btn btn-primary" style="flex: 1; padding: 10px; border-radius: 6px; font-weight: 600;">Check-In</button>
                                            <button type="submit" name="member_lookup_action" value="renew" class="btn btn-secondary" style="flex: 1; padding: 10px; border-radius: 6px; font-weight: 600;">Renew</button>
                                            <button type="submit" name="member_lookup_action" value="manage" class="btn btn-secondary" style="flex: 1; padding: 10px; border-radius: 6px; font-weight: 600;">Manage</button>
                                        </div>
                                    </form>
                                    <p style="margin-top: 12px; text-align: center;">
                                        <a href="host_checkin.php" class="card-link">Member Search &rarr;</a>
                                        &nbsp;|&nbsp;
                                        <a href="#" class="card-link" onclick="openAddMemberModal(); return false;">+ Add Member</a>
                                    </p>
                                </div>
                            </div>

                            <div id="pending-payments-section" class="table-card glass-panel" style="display: none;">
                                <div style="display: flex; justify-content: space-between; align-items: center; padding: 15px 15px 0; flex-wrap: wrap; gap: 10px;">
                                    <h3 style="margin: 0;">Pending Cash Approvals <span id="pending-count-badge"></span></h3>
                                    <button type="button" id="enable-alerts-btn" class="btn btn-secondary btn-small" style="display: none;">Enable Alerts</button>
                                </div>
                                <div id="pending-payments-list" style="padding: 15px;"></div>
                            </div>

                            <div class="table-card glass-panel">
                                <h3 style="padding: 15px 15px 0;">Check-Ins Log</h3>
                                <?php
                                $checkinsList = $todaysCheckins;
                                $checkinDeleteFormAction = 'portal.php';
                                $checkinEmptyMessage = 'No check-ins yet today.';
                                include __DIR__ . '/partials/checkin_list_table.php';
                                ?>
                            </div>

                            <div style="text-align: center;">
                                <a href="portal.php?view=standard" class="card-link">View Standard Dashboard &rarr;</a>
                            </div>
                        </div>

                    <?php else: ?>
                        <!-- STANDARD VIEW -->
                        <div class="dashboard-grid">
                            <div class="dashboard-card status-card">
                                <h3>Membership Status</h3>
                                <?php if ($membership): ?>
                                     <div class="status-summary" style="display: flex; flex-direction: column; align-items: flex-start; gap: 5px;">
                                         <span class="membership-level" style="font-size: 0.9rem;">
                                             <?php
                                             echo e($membership['membership_name']);
                                             if (isset($membership['minimum_fee'])) {
                                                 $formattedPrice = '$' . number_format($membership['minimum_fee'], 2);
                                                 $intervalText = '';
                                                 if (isset($membership['billing_interval'])) {
                                                     $intervalText = '/' . e($membership['billing_interval']);
                                                 }
                                                 echo ' (' . $formattedPrice . $intervalText . ')';
                                             }
                                             ?>
                                         </span>
                                         <span class="badge badge-<?php echo e(strtolower($membership['status_name'] ?? 'inactive')); ?>">
                                             <?php echo e($membership['status_name'] ?? 'Inactive'); ?>
                                         </span>

                                         <?php if (isset($membership['expires_at']) && $membership['expires_at']): ?>
                                             <small style="margin-top: 5px; color: var(--color-text-secondary); display: block;">
                                                 <?php
                                                 $expDate = new DateTime($membership['expires_at']);
                                                 $now = new DateTime();
                                                 if ($expDate < $now) {
                                                     echo "Expired on " . $expDate->format('M j, Y');
                                                 } else {
                                                     echo "Expires on " . $expDate->format('M j, Y');
                                                 }
                                                 ?>
                                             </small>
                                         <?php endif; ?>
                                     </div>
                                <?php else: ?>
                                     <p>No active membership record found.</p>
                                <?php endif; ?>

                                <a href="profile.php" class="card-link" style="margin-top: 15px; display: inline-block;">Manage Subscription &rarr;</a>
                            </div>

                            <div class="dashboard-card action-card">
                                <h3>Quick Actions</h3>
                                <div class="action-buttons">
                                    <a href="calendar.php" class="btn btn-secondary">View Calendar</a>
                                    <a href="library.php" class="btn btn-secondary">Game Library</a>
                                    <a href="profile.php" class="btn btn-secondary">Edit Profile</a>
                                    <?php if ($canSwitchToHosting): ?>
                                        <a href="portal.php?view=hosting" class="btn btn-primary" style="margin-top: 6px;">Switch to Hosting View &rarr;</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <?php if (Auth::check() && has_permission('admin panel')): ?>
                            <!-- ADMIN SNAPSHOT -->
                            <div class="admin-snapshot-section" style="margin-top: 28px;">
                                <div class="admin-snapshot-header">
                                    <h3>Admin Snapshot</h3>
                                    <a href="admin/dashboard.php" class="card-link">Open Full Admin Console &rarr;</a>
                                </div>

                                <div class="stat-cards-grid">
                                    <div class="stat-card glass-panel border-left-blue">
                                        <span class="stat-icon">👥</span>
                                        <div class="stat-vals">
                                            <strong><?php echo $totalContacts; ?></strong>
                                            <span>Total Members</span>
                                        </div>
                                    </div>
                                    <div class="stat-card glass-panel border-left-green">
                                        <span class="stat-icon">🎟️</span>
                                        <div class="stat-vals">
                                            <strong><?php echo $checkinsToday; ?></strong>
                                            <span>Check-Ins Today</span>
                                        </div>
                                    </div>
                                    <?php if (has_permission('process payments')): ?>
                                        <div class="stat-card glass-panel border-left-gold">
                                            <span class="stat-icon">💵</span>
                                            <div class="stat-vals">
                                                <strong>$<?php echo number_format($monthRevenue, 2); ?></strong>
                                                <span>This Month Revenue</span>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <?php if (!empty($matrix)): ?>
                                    <div class="table-card glass-panel" style="margin-top: 16px;">
                                        <h4 style="padding: 15px 15px 0; margin: 0; font-size: 0.95rem;">Members Matrix</h4>
                                        <div class="table-responsive" style="padding: 12px 15px 15px;">
                                            <table class="data-table compact-table" style="width: 100%; font-size: 0.82rem;">
                                                <thead>
                                                    <tr>
                                                        <th>Plan</th>
                                                        <?php foreach ($statuses as $st): ?>
                                                            <th style="text-align: center;"><?php echo e($st['label']); ?></th>
                                                        <?php endforeach; ?>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($matrix as $lvlName => $statRow): ?>
                                                        <tr>
                                                            <td><strong><?php echo e($lvlName); ?></strong></td>
                                                            <?php foreach ($statuses as $st): ?>
                                                                <?php $count = $statRow[$st['label']] ?? 0; ?>
                                                                <td style="text-align: center; <?php echo $count > 0 ? 'font-weight:700;' : 'opacity:0.4;'; ?>">
                                                                    <?php echo $count; ?>
                                                                </td>
                                                            <?php endforeach; ?>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

            <?php else: ?>
                <!-- LOGGED OUT LOGIN FORM -->
                <div class="login-container glass-panel">
                    <h2>Sign In to Tampa Gaming Guild</h2>
                    <form action="portal.php" method="POST" class="login-form">
                        <input type="hidden" name="csrf_token" value="<?php echo e(get_csrf_token()); ?>">
                        <div class="form-group">
                            <label for="email">Email Address</label>
                            <input type="email" id="email" name="email" required placeholder="member@example.com" value="<?php echo isset($_POST['email']) ? e($_POST['email']) : ''; ?>">
                        </div>
                        <div class="form-group">
                            <label for="password">Password</label>
                            <input type="password" id="password" name="password" required placeholder="&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;">
                        </div>
                        <button type="submit" name="login_submit" class="btn btn-primary btn-block">Sign In</button>
                    </form>
                    <div style="margin-top: 16px; text-align: center; font-size: 0.88rem;">
                        <p style="color: var(--color-text-secondary); margin-bottom: 6px;">Don&rsquo;t have an account yet?</p>
                        <a href="join.php" class="card-link">Join the Club &rarr;</a>
                    </div>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <!-- Quick Add Member Modal -->
    <?php if (Auth::check() && $showHostingView): ?>
    <div id="add-member-modal" class="modal-backdrop" style="display: none;">
        <div class="modal-card glass-panel" style="max-width: 480px; margin: 60px auto; padding: 24px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                <h3 style="margin: 0;">Add New Walk-In Member</h3>
                <button type="button" onclick="closeAddMemberModal()" style="background: none; border: none; color: #fff; font-size: 1.4rem; cursor: pointer;">&times;</button>
            </div>
            <form action="portal.php" method="POST" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo e(get_csrf_token()); ?>">
                <input type="hidden" name="add_member" value="1">
                <div class="form-group" style="margin-bottom: 12px;">
                    <label for="first_name" style="display: block; font-size: 0.85rem; margin-bottom: 4px;">First Name *</label>
                    <input type="text" id="first_name" name="first_name" required style="width: 100%; padding: 8px; border-radius: 6px; border: 1px solid rgba(255,255,255,0.2); background: rgba(255,255,255,0.05); color: #fff;">
                </div>
                <div class="form-group" style="margin-bottom: 12px;">
                    <label for="last_name" style="display: block; font-size: 0.85rem; margin-bottom: 4px;">Last Name *</label>
                    <input type="text" id="last_name" name="last_name" required style="width: 100%; padding: 8px; border-radius: 6px; border: 1px solid rgba(255,255,255,0.2); background: rgba(255,255,255,0.05); color: #fff;">
                </div>
                <div class="form-group" style="margin-bottom: 12px;">
                    <label for="modal_email" style="display: block; font-size: 0.85rem; margin-bottom: 4px;">Email Address *</label>
                    <input type="email" id="modal_email" name="email" required style="width: 100%; padding: 8px; border-radius: 6px; border: 1px solid rgba(255,255,255,0.2); background: rgba(255,255,255,0.05); color: #fff;">
                </div>
                <div class="form-group" style="margin-bottom: 16px;">
                    <label for="phone" style="display: block; font-size: 0.85rem; margin-bottom: 4px;">Phone Number</label>
                    <input type="text" id="phone" name="phone" placeholder="(813) 555-0199" style="width: 100%; padding: 8px; border-radius: 6px; border: 1px solid rgba(255,255,255,0.2); background: rgba(255,255,255,0.05); color: #fff;">
                </div>
                <div style="display: flex; gap: 10px; justify-content: flex-end;">
                    <button type="button" onclick="closeAddMemberModal()" class="btn btn-secondary">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Member &amp; Check In</button>
                </div>
            </form>
        </div>
    </div>
    <script>
        function openAddMemberModal() {
            document.getElementById('add-member-modal').style.display = 'block';
        }
        function closeAddMemberModal() {
            document.getElementById('add-member-modal').style.display = 'none';
        }
        window.addEventListener('click', function(event) {
            const modal = document.getElementById('add-member-modal');
            if (event.target === modal) {
                modal.style.display = 'none';
            }
        });
        <?php if ($errorMsg && isset($_POST['add_member'])): ?>
        document.addEventListener('DOMContentLoaded', openAddMemberModal);
        <?php endif; ?>
    </script>
    <?php endif; ?>
</body>
</html>
