<?php
/**
 * Main Portal Homepage & Login Gateway
 * Routes logged-out users to authentication and logged-in members to their dashboard.
 * The logged-in dashboard adapts to the user's role and whether they're actively
 * hosting a session right now (Hosting View vs Standard View), plus an Admin Snapshot.
 */
require_once dirname(dirname(__DIR__)) . '/config/bootstrap.php';

use App\Auth;
use App\BillingHelper;
use App\CiviCRMImporter;
use App\Database;
use App\Event;

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

// Handle Quick Member Actions lookup (Hosting View tool: Check-In / Renew / Manage by email or ID)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['member_lookup_action'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errorMsg = "Invalid security token.";
    } elseif (!has_permission('edit checkins')) {
        $errorMsg = "You do not have permission to perform this action.";
    } else {
        $action = $_POST['member_lookup_action'] ?? '';
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

// Handle Add Member submission (Hosting View quick action: a host adding a brand-new
// walk-in always gets a free Trial membership and an immediate check-in for the
// session they're hosting right now -- no plan/payment choices here. To upgrade or
// renew past Trial, use the member's profile afterward. The Admin Dashboard's separate
// Add Member, with full plan/payment control, is unaffected by this.)
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

            redirect('index.php?success=' . urlencode("{$result['display_name']} was added with a free Trial membership and checked in!"));
        } catch (Exception $e) {
            $errorMsg = safe_err("Failed to add member: ", $e);
        }
    }
}

// Handle Check-In deletion from the dashboard's Check-Ins Log
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
                redirect('index.php?success=' . urlencode('Check-in deleted successfully.'));
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
        $membership = CiviCRMImporter::getMemberMembershipDetails($_SESSION['user']['contact_id']);
    } catch (Exception $e) {
        // Silent fail for membership fetch in dashboard
    }
}

// Detect active hosting session / hosting-now state
$activeSession = null;
$isHostingNow = false;
if (Auth::check()) {
    try {
        $activeSession = Event::getActiveSession();
        if ($activeSession && has_permission('edit checkins')) {
            $rolesToday = Event::getMemberRolesForEvent((int)$activeSession['id'], (int)$_SESSION['user']['contact_id']);
            $isHostingNow = !empty($rolesToday);
        }
    } catch (Exception $e) {
        // Silent fail - fall back to Standard View
    }
}

$wantsStandardView = ($_GET['view'] ?? '') === 'standard';
$showHostingView = $isHostingNow && !$wantsStandardView;

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
if (Auth::check() && has_role('admin')) {
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

        // Members by Level & Status pivot matrix
        $tiers = CiviCRMImporter::getMembershipTiers();
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

        $allMembers = CiviCRMImporter::getMembersList();
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
        // Silent fail for admin snapshot
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
                        <?php if ($showHostingView): ?>
                            <h2><?php echo e($_SESSION['user']['display_name']); ?>, Hosting: <?php echo e($activeSession['title']); ?></h2>
                            <span class="user-role-badge"><?php echo date('g:i A', strtotime($activeSession['start_time'])); ?> &ndash; <?php echo date('g:i A', strtotime($activeSession['end_time'])); ?></span>
                        <?php else: ?>
                            <h2>Welcome Back, <?php echo e($_SESSION['user']['display_name']); ?>!</h2>
                            <span class="user-role-badge"><?php echo e(ucfirst($_SESSION['user']['role'])); ?> Portal</span>
                        <?php endif; ?>
                    </div>

                    <?php if ($isHostingNow && $wantsStandardView): ?>
                        <div class="alert alert-info" style="margin-bottom: 20px;">
                            You're hosting right now — <a href="index.php"><strong>switch to Hosting View</strong></a>.
                        </div>
                    <?php endif; ?>

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

                                <!-- Quick Member Actions -->
                                <div class="dashboard-card">
                                    <h3>Quick Member Actions</h3>
                                    <p style="color: var(--color-text-secondary); font-size: 0.9rem; margin-bottom: 12px;">
                                        Check in, renew, or manage a member by email or ID:
                                    </p>
                                    <form action="index.php" method="POST" autocomplete="off" style="display: flex; flex-wrap: wrap; gap: 10px;">
                                        <input type="hidden" name="csrf_token" value="<?php echo e(get_csrf_token()); ?>">
                                        <input type="text" id="identifier" name="identifier" required placeholder="Enter Email or Member ID..."
                                               style="background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.15); color: #fff; padding: 10px; border-radius: 6px; width: 100%; box-sizing: border-box;">
                                        <div style="display: flex; gap: 10px; width: 100%;">
                                            <button type="submit" name="member_lookup_action" value="checkin" class="btn btn-primary" style="flex: 1; padding: 10px; border-radius: 6px; font-weight: 600;">Check-In</button>
                                            <button type="submit" name="member_lookup_action" value="renew" class="btn btn-secondary" style="flex: 1; padding: 10px; border-radius: 6px; font-weight: 600;">Renew</button>
                                            <button type="submit" name="member_lookup_action" value="manage" class="btn btn-secondary" style="flex: 1; padding: 10px; border-radius: 6px; font-weight: 600;">Manage</button>
                                        </div>
                                    </form>
                                    <p style="margin-top: 12px; text-align: center;">
                                        <a href="host_checkin.php" class="card-link">Check In With Name Search &rarr;</a>
                                        &nbsp;|&nbsp;
                                        <a href="#" class="card-link" onclick="openAddMemberModal(); return false;">+ Add Member</a>
                                    </p>
                                </div>
                            </div>

                            <!-- Pending Cash Approvals -->
                            <div class="table-card glass-panel">
                                <div style="display: flex; justify-content: space-between; align-items: center; padding: 15px 15px 0; flex-wrap: wrap; gap: 10px;">
                                    <h3 style="margin: 0;">Pending Cash Approvals <span id="pending-count-badge"></span></h3>
                                    <button type="button" id="enable-alerts-btn" class="btn btn-secondary btn-small" style="display: none;">Enable Alerts</button>
                                </div>
                                <div id="pending-payments-list" style="padding: 15px;">
                                    <p id="pending-payments-empty" style="color: var(--color-text-secondary); margin: 0;">No pending cash payments right now.</p>
                                </div>
                            </div>

                            <!-- Check-Ins Log (today only, same table as the admin Check-In List) -->
                            <div class="table-card glass-panel">
                                <h3 style="padding: 15px 15px 0;">Check-Ins Log</h3>
                                <?php
                                $checkinsList = $todaysCheckins;
                                $checkinDeleteFormAction = 'index.php';
                                $checkinEmptyMessage = 'No check-ins yet today.';
                                include __DIR__ . '/partials/checkin_list_table.php';
                                ?>
                            </div>

                            <div style="text-align: center;">
                                <a href="index.php?view=standard" class="card-link">View Standard Dashboard &rarr;</a>
                            </div>
                        </div>

                    <?php else: ?>
                        <!-- STANDARD VIEW -->
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
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (has_role('admin') && !$showHostingView): ?>
                        <!-- ADMIN SNAPSHOT -->
                        <div class="dashboard-card" style="margin-top: 20px;">
                            <h3>Admin Snapshot</h3>
                            <div class="stats-panel-grid">
                                <?php if ($hasEventToday && has_permission('edit checkins')): ?>
                                    <div class="stat-card glass-panel border-left-orange">
                                        <span class="stat-icon">🎟️</span>
                                        <div class="stat-vals">
                                            <strong><?php echo $checkinsToday; ?></strong>
                                            <span>Check-Ins Today</span>
                                            <a href="admin/checkins.php" class="card-link" style="font-size: 0.7rem; color: var(--color-primary); text-decoration: none; margin-top: 5px; display: inline-block;">View Check-In Log &rarr;</a>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <div class="stat-card glass-panel border-left-blue">
                                    <span class="stat-icon">👥</span>
                                    <div class="stat-vals">
                                        <strong><?php echo $totalContacts; ?></strong>
                                        <span>Total Contacts</span>
                                    </div>
                                </div>

                                <?php if (has_permission('process payments')): ?>
                                    <div class="stat-card glass-panel border-left-yellow">
                                        <span class="stat-icon">💲</span>
                                        <div class="stat-vals">
                                            <strong>$<?php echo number_format($monthRevenue, 2); ?></strong>
                                            <span>Revenue (Month)</span>
                                            <a href="admin/reports.php#payments-report-table" class="card-link" style="font-size: 0.7rem; color: var(--color-primary); text-decoration: none; margin-top: 5px; display: inline-block;">View Payments Log &rarr;</a>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Members by Level & Status -->
                            <div style="margin-top: 20px;">
                                <span style="font-size: 0.85rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--color-text-secondary); margin-bottom: 15px; display: block;">Members by Level & Status</span>
                                <div style="overflow-x: auto; font-size: 0.8rem;">
                                    <table style="width: 100%; border-collapse: collapse; text-align: left; min-width: 600px;">
                                        <thead>
                                            <tr style="border-bottom: 1px solid rgba(255,255,255,0.08); color: var(--color-text-secondary);">
                                                <th style="padding: 6px 8px;">Membership Level</th>
                                                <?php foreach ($statuses as $stat): ?>
                                                    <th style="padding: 6px 8px; text-align: right; white-space: nowrap;"><?php echo e($stat['label']); ?></th>
                                                <?php endforeach; ?>
                                                <th style="padding: 6px 8px; text-align: right; white-space: nowrap;">Total</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            $colTotals = [];
                                            $grandTotal = 0;
                                            foreach ($statuses as $stat) {
                                                $colTotals[$stat['label']] = 0;
                                            }

                                            foreach ($matrix as $lvl => $stats):
                                                $rowTotal = 0;
                                            ?>
                                                <tr style="border-bottom: 1px solid rgba(255,255,255,0.04);">
                                                    <td style="padding: 6px 8px; font-weight: 500; color: #fff; white-space: nowrap;"><a href="admin/dashboard.php?level=<?php echo urlencode($lvl); ?>" style="color: var(--color-primary); text-decoration: none; font-weight: 600;"><?php echo e($lvl); ?></a></td>
                                                    <?php foreach ($statuses as $stat):
                                                        $count = $stats[$stat['label']] ?? 0;
                                                        if ($stat['is_active']) {
                                                            $rowTotal += $count;
                                                        }
                                                        $colTotals[$stat['label']] += $count;
                                                        $color = $count > 0 ? ($stat['label'] === 'Current' || $stat['label'] === 'New' ? 'var(--color-success)' : ($stat['label'] === 'Expired' ? 'var(--color-danger)' : '#fff')) : 'rgba(255,255,255,0.15)';
                                                        $weight = $count > 0 ? '700' : '400';
                                                    ?>
                                                        <td style="padding: 6px 8px; text-align: right; font-weight: <?php echo $weight; ?>; color: <?php echo $color; ?>;">
                                                            <?php if ($count > 0): ?>
                                                                <a href="admin/dashboard.php?level=<?php echo urlencode($lvl); ?>&status=<?php echo urlencode($stat['label']); ?>" style="color: inherit; text-decoration: none;"><?php echo $count; ?></a>
                                                            <?php else: ?>
                                                                <?php echo $count; ?>
                                                            <?php endif; ?>
                                                        </td>
                                                    <?php endforeach; ?>
                                                    <td style="padding: 6px 8px; text-align: right; font-weight: 700; color: #fff;">
                                                        <?php if ($rowTotal > 0): ?>
                                                            <a href="admin/dashboard.php?level=<?php echo urlencode($lvl); ?>&status=" style="color: inherit; text-decoration: none;"><?php echo $rowTotal; ?></a>
                                                        <?php else: ?>
                                                            <?php echo $rowTotal; ?>
                                                        <?php endif; ?>
                                                        <?php $grandTotal += $rowTotal; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                        <tfoot>
                                            <tr style="border-top: 2px solid rgba(255,255,255,0.15); font-weight: 700; color: #fff;">
                                                <td style="padding: 8px; font-weight: 700;">Total</td>
                                                <?php foreach ($statuses as $stat):
                                                    $colVal = $colTotals[$stat['label']];
                                                ?>
                                                    <td style="padding: 8px; text-align: right; font-weight: 700;">
                                                        <?php if ($colVal > 0): ?>
                                                            <a href="admin/dashboard.php?level=&status=<?php echo urlencode($stat['label']); ?>" style="color: inherit; text-decoration: none;"><?php echo $colVal; ?></a>
                                                        <?php else: ?>
                                                            <?php echo $colVal; ?>
                                                        <?php endif; ?>
                                                    </td>
                                                <?php endforeach; ?>
                                                <td style="padding: 8px; text-align: right; font-weight: 700;">
                                                    <?php if ($grandTotal > 0): ?>
                                                        <a href="admin/dashboard.php?level=&status=" style="color: inherit; text-decoration: none;"><?php echo $grandTotal; ?></a>
                                                    <?php else: ?>
                                                        <?php echo $grandTotal; ?>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div>

                            <a href="admin/dashboard.php" class="card-link" style="margin-top: 15px; display: inline-block;">Full Admin Dashboard &rarr;</a>
                        </div>
                    <?php endif; ?>
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

    <?php if ($showHostingView): ?>
    <!-- Add Member Modal -->
    <div id="add-member-modal" class="modal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.6); backdrop-filter: blur(5px);">
        <div class="modal-content glass-panel" style="background: rgba(30, 30, 40, 0.95); margin: 5% auto; padding: 25px; border: 1px solid rgba(255, 255, 255, 0.1); width: 90%; max-width: 480px; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.5);">
            <div class="modal-header" style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid rgba(255, 255, 255, 0.1); padding-bottom: 15px; margin-bottom: 20px;">
                <h3 style="margin: 0; color: #fff; font-size: 1.2rem;">Add Member</h3>
                <span class="close" onclick="closeAddMemberModal()" style="color: rgba(255,255,255,0.6); font-size: 28px; font-weight: bold; cursor: pointer; transition: color 0.2s;">&times;</span>
            </div>
            <form action="index.php" method="POST" class="auth-form">
                <input type="hidden" name="csrf_token" value="<?php echo e(get_csrf_token()); ?>">

                <div class="form-row">
                    <div class="form-group">
                        <label for="add_member_first_name">First Name</label>
                        <input type="text" id="add_member_first_name" name="first_name" required>
                    </div>
                    <div class="form-group">
                        <label for="add_member_last_name">Last Name</label>
                        <input type="text" id="add_member_last_name" name="last_name" required>
                    </div>
                </div>

                <div class="form-group">
                    <label for="add_member_email">Email Address</label>
                    <input type="email" id="add_member_email" name="email" required autocomplete="email">
                </div>

                <div class="form-group">
                    <label for="add_member_phone">Phone Number (Optional)</label>
                    <input type="tel" id="add_member_phone" name="phone">
                </div>

                <p class="field-hint" style="margin-bottom: 10px;">New members get a free 30-day Trial membership and are checked in for today's session right away. To upgrade or renew past Trial, use their profile afterward.</p>

                <button type="submit" name="add_member" value="1" class="btn btn-primary btn-block">Add Member</button>
            </form>
        </div>
    </div>

    <script>
        (function () {
            const csrfToken = <?php echo json_encode(get_csrf_token()); ?>;
            const enableBtn = document.getElementById('enable-alerts-btn');
            const listEl = document.getElementById('pending-payments-list');
            const emptyEl = document.getElementById('pending-payments-empty');
            const badgeEl = document.getElementById('pending-count-badge');
            let knownIds = new Set();
            let firstPoll = true;

            if ('Notification' in window) {
                if (Notification.permission === 'default') {
                    enableBtn.style.display = 'inline-block';
                }
                enableBtn.addEventListener('click', () => {
                    Notification.requestPermission().then(() => {
                        enableBtn.style.display = (Notification.permission === 'default') ? 'inline-block' : 'none';
                    });
                });
            }

            function escapeHtml(str) {
                return String(str || '')
                    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
            }

            function typeLabel(type) {
                return type === 'entrance_fee' ? 'Entrance Fee' : 'Membership Renewal';
            }

            function renderList(pending) {
                badgeEl.textContent = pending.length > 0 ? `(${pending.length})` : '';

                if (pending.length === 0) {
                    listEl.innerHTML = '';
                    emptyEl.style.display = 'block';
                    listEl.appendChild(emptyEl);
                    return;
                }

                listEl.innerHTML = '';
                pending.forEach((p) => {
                    const row = document.createElement('div');
                    row.style.cssText = 'display: flex; justify-content: space-between; align-items: center; gap: 10px; padding: 10px 0; border-bottom: 1px solid rgba(255,255,255,0.08); flex-wrap: wrap;';
                    row.innerHTML = `
                        <div>
                            <strong>${escapeHtml(p.display_name)}</strong>
                            <div style="font-size: 0.85rem; color: var(--color-text-secondary);">${escapeHtml(typeLabel(p.type))} &mdash; $${escapeHtml(p.amount)} cash</div>
                        </div>
                        <div style="display: flex; gap: 8px;">
                            <button type="button" class="btn btn-primary btn-small approve-btn" data-id="${p.id}">Approve</button>
                            <button type="button" class="btn btn-secondary btn-small deny-btn" data-id="${p.id}">Deny</button>
                        </div>
                    `;
                    listEl.appendChild(row);
                });

                listEl.querySelectorAll('.approve-btn').forEach((btn) => {
                    btn.addEventListener('click', () => resolvePending(btn.getAttribute('data-id'), 'approve', btn));
                });
                listEl.querySelectorAll('.deny-btn').forEach((btn) => {
                    btn.addEventListener('click', () => resolvePending(btn.getAttribute('data-id'), 'deny', btn));
                });
            }

            function resolvePending(pendingId, action, btn) {
                btn.disabled = true;
                const data = new URLSearchParams();
                data.append('pending_id', pendingId);
                data.append('action', action);
                data.append('csrf_token', csrfToken);

                fetch('pending-payments.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: data
                })
                    .then((res) => res.json())
                    .then(() => poll())
                    .catch(() => { btn.disabled = false; });
            }

            function poll() {
                fetch('pending-payments.php')
                    .then((res) => res.json())
                    .then((data) => {
                        if (!data.success) return;
                        const pending = data.pending || [];

                        if (!firstPoll) {
                            const newOnes = pending.filter((p) => !knownIds.has(p.id));
                            if (newOnes.length > 0 && 'Notification' in window && Notification.permission === 'granted') {
                                newOnes.forEach((p) => {
                                    new Notification('Payment Pending', {
                                        body: `${p.display_name} owes $${p.amount} cash (${typeLabel(p.type)})`
                                    });
                                });
                            }
                        }
                        firstPoll = false;
                        knownIds = new Set(pending.map((p) => p.id));

                        renderList(pending);
                    })
                    .catch(() => {});
            }

            poll();
            setInterval(poll, 12000);
        })();
    </script>

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
