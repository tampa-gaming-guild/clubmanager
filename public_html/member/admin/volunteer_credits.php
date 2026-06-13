<?php
/**
 * Admin Volunteer Credits Config & Processing
 * Allows administrators to update the credits rewarded for specific volunteer roles
 * and convert earned volunteer credits into membership expiration date extensions.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config/bootstrap.php';

use App\Auth;
use App\Database;

Auth::requireAdmin();

$errorMsg = null;
$successMsg = null;

// POST Handler: Update settings or process conversions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errorMsg = "Invalid security token.";
    } elseif (isset($_POST['action_update_settings'])) {
        // A. Update configuration settings
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
            if (isset($appDb) && $appDb->inTransaction()) {
                $appDb->rollBack();
            }
            $errorMsg = "Failed to update credits: " . $e->getMessage();
        }
    } elseif (isset($_POST['action_convert'])) {
        // B. Process credits and extend memberships
        $selectedMembers = $_POST['selected_members'] ?? [];
        $startDate = $_POST['start_date'] ?? '';
        $endDate = $_POST['end_date'] ?? '';
        
        if (empty($selectedMembers)) {
            $errorMsg = "No members selected for processing.";
        } else {
            try {
                $appDb = Database::getAppConnection();
                $civiDb = Database::getCiviConnection();
                
                // Fetch conversion rate
                $rateQuery = $appDb->query("SELECT credits FROM tgg_volunteer_credits WHERE credit_key = 'credits_per_month' LIMIT 1");
                $conversionRate = (float)$rateQuery->fetchColumn();
                if ($conversionRate <= 0) {
                    $conversionRate = 4.0;
                }
                
                // Fetch all credit configs into key-value map
                $configsStmt = $appDb->query("SELECT credit_key, credits FROM tgg_volunteer_credits");
                $creditsMap = $configsStmt->fetchAll(PDO::FETCH_KEY_PAIR);
                
                $appDb->beginTransaction();
                $civiDb->beginTransaction();
                
                $todayStr = date('Y-m-d');
                
                // Insert transaction statement
                $insertTrans = $appDb->prepare("
                    INSERT INTO tgg_volunteer_credit_transactions (contact_id, event_id, volunteer_date, shift, credits_earned, credits_applied)
                    VALUES (:contact_id, :event_id, :volunteer_date, :shift, :credits_earned, 0.0)
                ");
                
                // Fetch member settings or insert statement
                $selectSettings = $appDb->prepare("SELECT credits_earned, credits_applied FROM tgg_member_settings WHERE contact_id = :contact_id LIMIT 1");
                $updateSettings = $appDb->prepare("
                    UPDATE tgg_member_settings 
                    SET credits_earned = :credits_earned, credits_applied = :credits_applied 
                    WHERE contact_id = :contact_id
                ");
                
                foreach ($selectedMembers as $cid) {
                    $cid = (int)$cid;
                    
                    // Fetch unprocessed signups for this member in date range
                    $stmtUnprocessed = $appDb->prepare("
                        SELECT s.event_id, s.role, e.start_time
                        FROM tgg_volunteer_signups s
                        INNER JOIN tgg_events e ON s.event_id = e.id
                        LEFT JOIN tgg_volunteer_credit_transactions t ON t.event_id = s.event_id AND t.contact_id = s.contact_id AND t.shift = s.role
                        WHERE s.contact_id = :contact_id 
                          AND e.start_time >= :start_time 
                          AND e.start_time <= :end_time 
                          AND t.id IS NULL
                    ");
                    $stmtUnprocessed->execute([
                        'contact_id' => $cid,
                        'start_time' => $startDate . ' 00:00:00',
                        'end_time' => $endDate . ' 23:59:59'
                    ]);
                    $newShifts = $stmtUnprocessed->fetchAll();
                    
                    if (empty($newShifts)) {
                        continue;
                    }
                    
                    $earnedInPeriod = 0.0;
                    foreach ($newShifts as $shift) {
                        $role = $shift['role'];
                        $isSunday = (date('w', strtotime($shift['start_time'])) == 0);
                        
                        if ($role === 'Open') {
                            $key = $isSunday ? 'sunday_open' : 'weekday_open';
                        } elseif ($role === 'Close') {
                            $key = $isSunday ? 'sunday_close' : 'weekday_close';
                        } elseif ($role === 'Greeter') {
                            $key = $isSunday ? 'sunday_greeter' : 'weekday_greeter';
                        } else {
                            $key = 'weekday_open';
                        }
                        
                        $earned = (float)($creditsMap[$key] ?? 0.0);
                        $earnedInPeriod += $earned;
                        
                        // Log shifts transaction
                        $insertTrans->execute([
                            'contact_id' => $cid,
                            'event_id' => (int)$shift['event_id'],
                            'volunteer_date' => date('Y-m-d', strtotime($shift['start_time'])),
                            'shift' => $role,
                            'credits_earned' => $earned
                        ]);
                    }
                    
                    // Fetch current settings
                    $selectSettings->execute(['contact_id' => $cid]);
                    $currSettings = $selectSettings->fetch();
                    
                    if ($currSettings) {
                        $creditsEarned = (float)$currSettings['credits_earned'] + $earnedInPeriod;
                        $creditsApplied = (float)$currSettings['credits_applied'];
                    } else {
                        $creditsEarned = $earnedInPeriod;
                        $creditsApplied = 0.0;
                        
                        // Insert standard record if missing
                        $insertSettings = $appDb->prepare("
                            INSERT INTO tgg_member_settings (contact_id, password_hash, role, credits_earned, credits_applied)
                            VALUES (:contact_id, '', 'member', :credits_earned, 0.0)
                        ");
                        $insertSettings->execute([
                            'contact_id' => $cid,
                            'credits_earned' => $creditsEarned
                        ]);
                    }
                    
                    $unapplied = $creditsEarned - $creditsApplied;
                    $monthsToExtend = (int)floor($unapplied / $conversionRate);
                    
                    if ($monthsToExtend > 0) {
                        $appliedDiff = $monthsToExtend * $conversionRate;
                        $creditsApplied += $appliedDiff;
                        
                        // Log extension transaction
                        $insertExtension = $appDb->prepare("
                            INSERT INTO tgg_volunteer_credit_transactions (contact_id, event_id, volunteer_date, shift, credits_earned, credits_applied)
                            VALUES (:contact_id, NULL, :volunteer_date, 'Apply Extension', 0.0, :credits_applied)
                        ");
                        $insertExtension->execute([
                            'contact_id' => $cid,
                            'volunteer_date' => $todayStr,
                            'credits_applied' => $appliedDiff
                        ]);
                        
                        // Retrieve local subscription details
                        $subStmt = $appDb->prepare("SELECT plan_id, end_date FROM tgg_subscriptions WHERE contact_id = :contact_id LIMIT 1");
                        $subStmt->execute(['contact_id' => $cid]);
                        $existingSub = $subStmt->fetch();
                        
                        // Calculate new end date
                        $currentEndDate = null;
                        if ($existingSub) {
                            $currentEndDate = $existingSub['end_date'];
                        }
                        
                        $startDateStr = $todayStr;
                        if ($currentEndDate && strtotime($currentEndDate) >= strtotime($todayStr)) {
                            $startDateStr = $currentEndDate;
                        }
                        $newEndDate = date('Y-m-d', strtotime($startDateStr . " +{$monthsToExtend} month"));
                        
                        // Insert or Update local subscription
                        if ($existingSub) {
                            $updateSub = $appDb->prepare("
                                UPDATE tgg_subscriptions 
                                SET status = 'active', end_date = :end_date 
                                WHERE contact_id = :contact_id
                            ");
                            $updateSub->execute([
                                'end_date' => $newEndDate,
                                'contact_id' => $cid
                            ]);
                        } else {
                            $insertSub = $appDb->prepare("
                                INSERT INTO tgg_subscriptions (contact_id, plan_id, status, join_date, start_date, end_date)
                                VALUES (:contact_id, 2, 'active', :join_date, :start_date, :end_date)
                            ");
                            $insertSub->execute([
                                'contact_id' => $cid,
                                'join_date' => $todayStr,
                                'start_date' => $todayStr,
                                'end_date' => $newEndDate
                            ]);
                        }
                        
                        // Update CiviCRM Membership expiration
                        $civiStmt = $civiDb->prepare("SELECT id FROM civicrm_membership WHERE contact_id = :contact_id LIMIT 1");
                        $civiStmt->execute(['contact_id' => $cid]);
                        $existingCivi = $civiStmt->fetch();
                        
                        if ($existingCivi) {
                            $updateCivi = $civiDb->prepare("
                                UPDATE civicrm_membership 
                                SET end_date = :end_date, status_id = 2 
                                WHERE id = :id
                            ");
                            $updateCivi->execute([
                                'end_date' => $newEndDate,
                                'id' => (int)$existingCivi['id']
                            ]);
                        } else {
                            $insertCivi = $civiDb->prepare("
                                INSERT INTO civicrm_membership (contact_id, membership_type_id, join_date, start_date, end_date, status_id)
                                VALUES (:contact_id, 2, :join_date, :start_date, :end_date, 2)
                            ");
                            $insertCivi->execute([
                                'contact_id' => $cid,
                                'join_date' => $todayStr,
                                'start_date' => $todayStr,
                                'end_date' => $newEndDate
                            ]);
                        }
                    }
                    
                    // Save updated member settings
                    $updateSettings->execute([
                        'credits_earned' => $creditsEarned,
                        'credits_applied' => $creditsApplied,
                        'contact_id' => $cid
                    ]);
                }
                
                $appDb->commit();
                $civiDb->commit();
                
                $successMsg = "Successfully processed volunteer credits and extended memberships for selected members.";
            } catch (Exception $e) {
                if (isset($appDb) && $appDb->inTransaction()) $appDb->rollBack();
                if (isset($civiDb) && $civiDb->inTransaction()) $civiDb->rollBack();
                $errorMsg = "Processing failed: " . $e->getMessage();
            }
        }
    }
}

// GET Handler: Retrieve Settings
try {
    $appDb = Database::getAppConnection();
    $stmt = $appDb->query("SELECT credit_key, credit_label, credits FROM tgg_volunteer_credits ORDER BY id ASC");
    $creditSettings = $stmt->fetchAll();
} catch (Exception $e) {
    $creditSettings = [];
    $errorMsg = "Unable to retrieve credits: " . $e->getMessage();
}

// GET Handler: Load Eligible Unprocessed Signups
$startDate = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$endDate = isset($_GET['end_date']) ? $_GET['end_date'] : '';
$eligibleMembers = [];

if (!empty($startDate) && !empty($endDate)) {
    try {
        $appDb = Database::getAppConnection();
        $civiDb = Database::getCiviConnection();
        
        // Fetch all credits configs
        $configsStmt = $appDb->query("SELECT credit_key, credits FROM tgg_volunteer_credits");
        $creditsMap = $configsStmt->fetchAll(PDO::FETCH_KEY_PAIR);
        $conversionRate = (float)($creditsMap['credits_per_month'] ?? 4.0);
        if ($conversionRate <= 0) $conversionRate = 4.0;
        
        // Fetch all unprocessed signups during the time range
        $stmtSignups = $appDb->prepare("
            SELECT s.event_id, s.contact_id, s.role, e.title, e.start_time
            FROM tgg_volunteer_signups s
            INNER JOIN tgg_events e ON s.event_id = e.id
            LEFT JOIN tgg_volunteer_credit_transactions t ON t.event_id = s.event_id AND t.contact_id = s.contact_id AND t.shift = s.role
            WHERE e.start_time >= :start_time 
              AND e.start_time <= :end_time 
              AND t.id IS NULL
            ORDER BY e.start_time ASC
        ");
        $stmtSignups->execute([
            'start_time' => $startDate . ' 00:00:00',
            'end_time' => $endDate . ' 23:59:59'
        ]);
        $unprocessedSignups = $stmtSignups->fetchAll();
        
        if (!empty($unprocessedSignups)) {
            // Group by contact_id
            $groupedSignups = [];
            foreach ($unprocessedSignups as $signup) {
                $groupedSignups[$signup['contact_id']][] = $signup;
            }
            
            // Fetch contact names from CiviCRM
            $contactIds = array_keys($groupedSignups);
            $placeholders = implode(',', array_fill(0, count($contactIds), '?'));
            $stmtNames = $civiDb->prepare("SELECT id, display_name FROM civicrm_contact WHERE id IN ({$placeholders})");
            $stmtNames->execute(array_values($contactIds));
            $namesMap = $stmtNames->fetchAll(PDO::FETCH_KEY_PAIR);
            
            // Fetch member settings (current credits_earned & credits_applied)
            $stmtSettings = $appDb->prepare("SELECT contact_id, credits_earned, credits_applied FROM tgg_member_settings WHERE contact_id IN ({$placeholders})");
            $stmtSettings->execute(array_values($contactIds));
            $settingsRaw = $stmtSettings->fetchAll();
            $settingsMap = [];
            foreach ($settingsRaw as $row) {
                $settingsMap[$row['contact_id']] = $row;
            }
            
            // Fetch current expiration dates
            $stmtSubs = $appDb->prepare("SELECT contact_id, end_date FROM tgg_subscriptions WHERE contact_id IN ({$placeholders})");
            $stmtSubs->execute(array_values($contactIds));
            $subsMap = $stmtSubs->fetchAll(PDO::FETCH_KEY_PAIR);
            
            foreach ($groupedSignups as $cid => $shifts) {
                $newCredits = 0.0;
                $shiftDetails = [];
                
                foreach ($shifts as $shift) {
                    $role = $shift['role'];
                    $isSunday = (date('w', strtotime($shift['start_time'])) == 0);
                    
                    if ($role === 'Open') {
                        $key = $isSunday ? 'sunday_open' : 'weekday_open';
                    } elseif ($role === 'Close') {
                        $key = $isSunday ? 'sunday_close' : 'weekday_close';
                    } elseif ($role === 'Greeter') {
                        $key = $isSunday ? 'sunday_greeter' : 'weekday_greeter';
                    } else {
                        $key = 'weekday_open';
                    }
                    
                    $val = (float)($creditsMap[$key] ?? 0.0);
                    $newCredits += $val;
                    
                    $shiftDetails[] = date('M d', strtotime($shift['start_time'])) . ' (' . $role . ' - ' . $val . ' cr)';
                }
                
                $currEarned = (float)($settingsMap[$cid]['credits_earned'] ?? 0.0);
                $currApplied = (float)($settingsMap[$cid]['credits_applied'] ?? 0.0);
                
                $totalUnapplied = ($currEarned - $currApplied) + $newCredits;
                $monthsToExtend = (int)floor($totalUnapplied / $conversionRate);
                
                $currEnd = $subsMap[$cid] ?? null;
                $proposedEnd = 'No extension';
                if ($monthsToExtend > 0) {
                    $todayStr = date('Y-m-d');
                    $baseDate = $todayStr;
                    if ($currEnd && strtotime($currEnd) >= strtotime($todayStr)) {
                        $baseDate = $currEnd;
                    }
                    $proposedEnd = date('Y-m-d', strtotime($baseDate . " +{$monthsToExtend} month"));
                }
                
                $eligibleMembers[] = [
                    'contact_id' => $cid,
                    'display_name' => $namesMap[$cid] ?? "Member #{$cid}",
                    'shift_list' => implode(', ', $shiftDetails),
                    'new_credits' => $newCredits,
                    'unapplied_credits' => $totalUnapplied,
                    'current_end_date' => $currEnd ?: 'No active membership',
                    'extension_months' => $monthsToExtend,
                    'proposed_end_date' => $proposedEnd
                ];
            }
        }
    } catch (Exception $e) {
        $errorMsg = "Error loading eligible signups: " . $e->getMessage();
    }
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
        .date-input {
            padding: 8px 12px;
            font-size: 0.9rem;
            border-radius: 6px;
            border: 1px solid var(--border-glass);
            background: rgba(255, 255, 255, 0.05);
            color: #fff;
            outline: none;
            transition: all 0.2s ease;
        }
        .date-input:focus {
            border-color: var(--color-primary);
        }
        .form-row {
            display: flex;
            gap: 15px;
            align-items: flex-end;
            margin-bottom: 25px;
        }
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .form-group label {
            font-size: 0.85rem;
            color: var(--color-text-secondary);
            font-weight: 500;
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
                    <?php if ($errorMsg): ?>
                        <div class="alert alert-danger" style="margin-bottom: 20px;"><?php echo e($errorMsg); ?></div>
                    <?php endif; ?>

                    <?php if ($successMsg): ?>
                        <div class="alert alert-success" style="margin-bottom: 20px;"><?php echo e($successMsg); ?></div>
                    <?php endif; ?>

                    <h2>Volunteer Credits Configuration</h2>
                    <p class="description-text" style="margin-bottom: 25px;">
                        Configure the credit weights earned by members for filling specific shift roles, and the exchange rate for free membership months.
                    </p>

                    <form action="volunteer_credits.php" method="POST" style="margin-bottom: 50px;">
                        <input type="hidden" name="csrf_token" value="<?php echo e(get_csrf_token()); ?>">
                        <input type="hidden" name="action_update_settings" value="1">
                        
                        <div class="admin-table-container">
                            <table class="admin-table" style="width: 100%; margin-bottom: 20px;">
                                <thead>
                                    <tr>
                                        <th style="width: 60%;">Shift / Configuration Property</th>
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

                    <hr style="border: none; border-bottom: 1px solid var(--border-glass); margin-bottom: 40px;">

                    <h2>Process & Convert Credits</h2>
                    <p class="description-text" style="margin-bottom: 25px;">
                        Select a date range to compile past volunteer shifts. Convert accumulated unapplied credits to extend members' subscription expiration dates.
                    </p>

                    <!-- Date Range Selection Form -->
                    <form action="volunteer_credits.php" method="GET" class="form-row">
                        <div class="form-group">
                            <label for="start-date">Start Date</label>
                            <input type="date" id="start-date" name="start_date" class="date-input" value="<?php echo e($startDate ?: date('Y-m-01')); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="end-date">End Date</label>
                            <input type="date" id="end-date" name="end_date" class="date-input" value="<?php echo e($endDate ?: date('Y-m-d')); ?>" required>
                        </div>
                        <button type="submit" class="btn btn-primary" style="padding: 10px 20px;">Load Eligible Signups</button>
                    </form>

                    <!-- Eligible Signups Table -->
                    <?php if (!empty($startDate) && !empty($endDate)): ?>
                        <form action="volunteer_credits.php" method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo e(get_csrf_token()); ?>">
                            <input type="hidden" name="action_convert" value="1">
                            <input type="hidden" name="start_date" value="<?php echo e($startDate); ?>">
                            <input type="hidden" name="end_date" value="<?php echo e($endDate); ?>">

                            <div class="admin-table-container" style="margin-top: 20px;">
                                <table class="admin-table" style="width: 100%; margin-bottom: 20px;">
                                    <thead>
                                        <tr>
                                            <th style="width: 5%; text-align: center;"><input type="checkbox" id="select-all" onclick="toggleAllCheckboxes(this)"></th>
                                            <th style="width: 25%;">Member Name</th>
                                            <th style="width: 30%;">New Shifts Completed</th>
                                            <th style="width: 10%; text-align: center;">New Credits</th>
                                            <th style="width: 10%; text-align: center;">Unapplied Balance</th>
                                            <th style="width: 10%; text-align: center;">Extension</th>
                                            <th style="width: 10%;">Proposed Expiration</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($eligibleMembers)): ?>
                                            <tr>
                                                <td colspan="7" style="text-align: center; color: var(--color-text-secondary); padding: 30px;">
                                                    No unprocessed volunteer shifts found for the selected date range.
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($eligibleMembers as $member): ?>
                                                <tr>
                                                    <td style="text-align: center;">
                                                        <input type="checkbox" name="selected_members[]" class="member-checkbox" value="<?php echo e($member['contact_id']); ?>">
                                                    </td>
                                                    <td style="font-weight: 600; color: #fff;">
                                                        <?php echo e($member['display_name']); ?>
                                                        <span style="display: block; font-size: 0.75rem; color: var(--color-text-secondary); font-weight: normal;">ID: <?php echo e($member['contact_id']); ?></span>
                                                    </td>
                                                    <td style="font-size: 0.8rem; color: var(--color-text-secondary); line-height: 1.4;">
                                                        <?php echo e($member['shift_list']); ?>
                                                    </td>
                                                    <td style="text-align: center; font-weight: bold; color: var(--color-primary);">
                                                        +<?php echo number_format($member['new_credits'], 1); ?>
                                                    </td>
                                                    <td style="text-align: center; font-weight: 500;">
                                                        <?php echo number_format($member['unapplied_credits'], 1); ?>
                                                    </td>
                                                    <td style="text-align: center;">
                                                        <?php if ($member['extension_months'] > 0): ?>
                                                            <span class="badge badge-active" style="background: rgba(34, 197, 94, 0.15); color: var(--color-success); border: 1px solid rgba(34, 197, 94, 0.3);">
                                                                +<?php echo $member['extension_months']; ?> Month<?php echo $member['extension_months'] > 1 ? 's' : ''; ?>
                                                            </span>
                                                        <?php else: ?>
                                                            <span style="color: var(--color-text-muted); font-size: 0.8rem;">None</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td style="font-size: 0.85rem; color: #fff;">
                                                        <?php if ($member['extension_months'] > 0): ?>
                                                            <strong><?php echo e($member['proposed_end_date']); ?></strong>
                                                        <?php else: ?>
                                                            <span style="color: var(--color-text-secondary);"><?php echo e($member['current_end_date']); ?></span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>

                            <?php if (!empty($eligibleMembers)): ?>
                                <div style="text-align: left;">
                                    <button type="submit" class="btn btn-success" style="padding: 10px 24px;">Process & Convert Selected Credits</button>
                                </div>
                            <?php endif; ?>
                        </form>
                    <?php endif; ?>
                </section>
            </div>
        </main>

        <footer class="app-footer">
            <p>&copy; <?php echo date('Y'); ?> TGG Club Membership System. Secure Public Portal.</p>
        </footer>
    </div>

    <script>
    function toggleAllCheckboxes(master) {
        const checkboxes = document.querySelectorAll('.member-checkbox');
        checkboxes.forEach(cb => {
            cb.checked = master.checked;
        });
    }
    </script>
</body>
</html>
