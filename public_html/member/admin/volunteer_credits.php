<?php
/**
 * Admin Volunteer Credits Config & Processing
 * Allows administrators to update the credits rewarded for specific volunteer roles
 * and convert earned volunteer credits into membership expiration date extensions.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config/bootstrap.php';

use App\Auth;
use App\AuditLog;
use App\Database;
use App\EventSlot;
use App\MailHelper;

Auth::requirePermission('manage hosting');

$errorMsg = null;
$successMsg = null;

/**
 * Recalculate member credit totals (earned, applied, expired, available) and sync database.
 */
function updateMemberCredits($appDb, $contactId, $expirationDays) {
    // 1. Fetch all transactions for this member
    $stmt = $appDb->prepare("
        SELECT id, volunteer_date, credits_earned, credits_applied 
        FROM tgg_volunteer_credit_transactions 
        WHERE contact_id = :contact_id 
        ORDER BY volunteer_date ASC, id ASC
    ");
    $stmt->execute(['contact_id' => $contactId]);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $earned = [];
    $applied = [];
    
    $totalEarned = 0.0;
    $totalApplied = 0.0;
    
    foreach ($transactions as $tx) {
        $valEarned = (float)$tx['credits_earned'];
        $valApplied = (float)$tx['credits_applied'];
        if ($valEarned > 0.0) {
            $earned[] = [
                'id' => $tx['id'],
                'date' => $tx['volunteer_date'],
                'amount' => $valEarned,
                'remaining' => $valEarned
            ];
            $totalEarned += $valEarned;
        }
        if ($valApplied > 0.0) {
            $applied[] = [
                'id' => $tx['id'],
                'date' => $tx['volunteer_date'],
                'amount' => $valApplied
            ];
            $totalApplied += $valApplied;
        }
    }
    
    $totalExpired = 0.0;
    
    if ($expirationDays > 0.0) {
        // Match applications to earned credits using FIFO
        foreach ($applied as $appTx) {
            $appDate = $appTx['date'];
            $appAmount = $appTx['amount'];
            
            foreach ($earned as &$earnTx) {
                if ($earnTx['remaining'] <= 0.0) {
                    continue;
                }
                
                // Check if this earned transaction was expired AT the time of this application
                $earnDate = $earnTx['date'];
                $expireDate = date('Y-m-d', strtotime($earnDate . " + " . (int)$expirationDays . " days"));
                if ($expireDate < $appDate) {
                    continue; // Skip expired
                }
                
                if ($appAmount >= $earnTx['remaining']) {
                    $appAmount -= $earnTx['remaining'];
                    $earnTx['remaining'] = 0.0;
                } else {
                    $earnTx['remaining'] -= $appAmount;
                    $appAmount = 0.0;
                    break; // Application fully allocated
                }
            }
            unset($earnTx);
        }
        
        // Count expired unapplied credits as of today
        $today = date('Y-m-d');
        foreach ($earned as $earnTx) {
            if ($earnTx['remaining'] > 0.0) {
                $earnDate = $earnTx['date'];
                $expireDate = date('Y-m-d', strtotime($earnDate . " + " . (int)$expirationDays . " days"));
                if ($expireDate < $today) {
                    $totalExpired += $earnTx['remaining'];
                }
            }
        }
    } else {
        $totalExpired = 0.0;
    }
    
    // Update or Insert into tgg_member_settings
    $stmtSelect = $appDb->prepare("SELECT contact_id FROM tgg_member_settings WHERE contact_id = :contact_id LIMIT 1");
    $stmtSelect->execute(['contact_id' => $contactId]);
    if ($stmtSelect->fetch()) {
        $stmtUpdate = $appDb->prepare("
            UPDATE tgg_member_settings 
            SET credits_earned = :earned, credits_applied = :applied, expired_credits = :expired 
            WHERE contact_id = :contact_id
        ");
        $stmtUpdate->execute([
            'earned' => $totalEarned,
            'applied' => $totalApplied,
            'expired' => $totalExpired,
            'contact_id' => $contactId
        ]);
    } else {
        $stmtInsert = $appDb->prepare("
            INSERT INTO tgg_member_settings (contact_id, password_hash, role, credits_earned, credits_applied, expired_credits)
            VALUES (:contact_id, '', 'member', :earned, :applied, :expired)
        ");
        $stmtInsert->execute([
            'contact_id' => $contactId,
            'earned' => $totalEarned,
            'applied' => $totalApplied,
            'expired' => $totalExpired
        ]);
    }
    
    return [
        'credits_earned' => $totalEarned,
        'credits_applied' => $totalApplied,
        'expired_credits' => $totalExpired,
        'available_credits' => max(0.0, $totalEarned - $totalApplied - $totalExpired)
    ];
}

// POST Handler: Update settings or process conversions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errorMsg = "Invalid security token.";
    } elseif (isset($_POST['action_update_settings'])) {
        // A. Update configuration settings
        $credits = $_POST['credits'] ?? [];
        try {
            $appDb = Database::getAppConnection();

            // Before-snapshot so the audit event records only what actually changed.
            $beforeStmt = $appDb->query("SELECT credit_key, credits FROM tgg_volunteer_credits");
            $beforeValues = $beforeStmt->fetchAll(PDO::FETCH_KEY_PAIR);

            $stmt = $appDb->prepare("UPDATE tgg_volunteer_credits SET credits = :credits WHERE credit_key = :key");

            $changes = [];
            $appDb->beginTransaction();
            foreach ($credits as $key => $val) {
                // Membership setting stored in this table; only editable on the Memberships page.
                if ($key === 'renewal_grace_days') {
                    continue;
                }
                $valFloat = (float)$val;
                if ($valFloat < 0) {
                    throw new Exception("Credit values cannot be negative.");
                }
                $stmt->execute([
                    'credits' => $valFloat,
                    'key' => $key
                ]);
                if (array_key_exists($key, $beforeValues) && (float)$beforeValues[$key] !== $valFloat) {
                    $changes[$key] = ['old' => (float)$beforeValues[$key], 'new' => $valFloat];
                }
            }
            $appDb->commit();

            if (!empty($changes)) {
                AuditLog::log('volunteer_config', 'credit_settings_updated', ['changes' => $changes]);
            }
            $successMsg = "Volunteer credit settings updated successfully.";
        } catch (Exception $e) {
            if (isset($appDb) && $appDb->inTransaction()) {
                $appDb->rollBack();
            }
            $errorMsg = safe_err("Failed to update credits: ", $e);
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
                
                // Fetch conversion rate
                $rateQuery = $appDb->query("SELECT credits FROM tgg_volunteer_credits WHERE credit_key = 'credits_per_month' LIMIT 1");
                $conversionRate = (float)$rateQuery->fetchColumn();
                if ($conversionRate <= 0) {
                    $conversionRate = 4.0;
                }
                
                // Fetch all credit configs into key-value map
                $configsStmt = $appDb->query("SELECT credit_key, credits FROM tgg_volunteer_credits");
                $creditsMap = $configsStmt->fetchAll(PDO::FETCH_KEY_PAIR);
                $expirationDays = (float)($creditsMap['credit_expiration_days'] ?? 365.0);
                
                $appDb->beginTransaction();
                
                $todayStr = date('Y-m-d');
                $emailsToSend = [];
                
                // Insert transaction statement. shift keeps a label snapshot for
                // display; slot_id is the precise processed-signup marker.
                $actorCols = AuditLog::actorColumns();
                $insertTrans = $appDb->prepare("
                    INSERT INTO tgg_volunteer_credit_transactions (contact_id, event_id, slot_id, volunteer_date, shift, credits_earned, credits_applied, created_by, impersonator_id, source)
                    VALUES (:contact_id, :event_id, :slot_id, :volunteer_date, :shift, :credits_earned, 0.0, :created_by, :impersonator_id, :source)
                ");

                foreach ($selectedMembers as $cid) {
                    $cid = (int)$cid;

                    // Fetch unprocessed signups for this member in date range
                    $stmtUnprocessed = $appDb->prepare("
                        SELECT s.slot_id, sl.event_id, sl.slot_label AS role, sl.slot_type, e.start_time
                        FROM tgg_volunteer_signups s
                        INNER JOIN tgg_event_slots sl ON sl.id = s.slot_id
                        INNER JOIN tgg_events e ON sl.event_id = e.id
                        LEFT JOIN tgg_volunteer_credit_transactions t ON t.slot_id = s.slot_id AND t.contact_id = s.contact_id
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
                    
                    foreach ($newShifts as $shift) {
                        $key = EventSlot::creditKey($shift['slot_type'], $shift['start_time']);
                        $earned = (float)($creditsMap[$key] ?? 0.0);

                        // Log shifts transaction
                        $insertTrans->execute([
                            'contact_id' => $cid,
                            'event_id' => (int)$shift['event_id'],
                            'slot_id' => (int)$shift['slot_id'],
                            'volunteer_date' => date('Y-m-d', strtotime($shift['start_time'])),
                            'shift' => $shift['role'],
                            'credits_earned' => $earned,
                            'created_by' => $actorCols['created_by'],
                            'impersonator_id' => $actorCols['impersonator_id'],
                            'source' => $actorCols['source']
                        ]);
                    }
                    
                    // Recalculate member credits including the newly logged transactions
                    $details = updateMemberCredits($appDb, $cid, $expirationDays);
                    
                    $monthsToExtend = (int)floor($details['available_credits'] / $conversionRate);
                    
                    if ($monthsToExtend > 0) {
                        $appliedDiff = $monthsToExtend * $conversionRate;
                        
                        // Log extension transaction
                        $insertExtension = $appDb->prepare("
                            INSERT INTO tgg_volunteer_credit_transactions (contact_id, event_id, volunteer_date, shift, credits_earned, credits_applied, created_by, impersonator_id, source)
                            VALUES (:contact_id, NULL, :volunteer_date, 'Apply Extension', 0.0, :credits_applied, :created_by, :impersonator_id, :source)
                        ");
                        $insertExtension->execute([
                            'contact_id' => $cid,
                            'volunteer_date' => $todayStr,
                            'credits_applied' => $appliedDiff,
                            'created_by' => $actorCols['created_by'],
                            'impersonator_id' => $actorCols['impersonator_id'],
                            'source' => $actorCols['source']
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
                                INSERT INTO tgg_subscriptions (contact_id, plan_id, status, join_date, start_date, end_date, rate_id)
                                VALUES (:contact_id, 2, 'active', :join_date, :start_date, :end_date, (SELECT id FROM tgg_subscription_rates WHERE plan_id = 2 LIMIT 1))
                            ");
                            $insertSub->execute([
                                'contact_id' => $cid,
                                'join_date' => $todayStr,
                                'start_date' => $todayStr,
                                'end_date' => $newEndDate
                            ]);
                        }
                        
                        // No CiviCRM Membership updates needed anymore
                        
                        // Recalculate and write final values (including applied credits) to settings
                        updateMemberCredits($appDb, $cid, $expirationDays);

                        $emailsToSend[] = [
                            'contact_id' => $cid,
                            'credits_used' => $appliedDiff,
                            'months_extended' => $monthsToExtend,
                            'new_end_date' => $newEndDate
                        ];
                    }
                }
                
                $appDb->commit();

                // Send credit conversion notification emails
                foreach ($emailsToSend as $mailInfo) {
                    try {
                        $contactQuery = $appDb->prepare("SELECT display_name, email FROM tgg_contacts WHERE id = :contact_id LIMIT 1");
                        $contactQuery->execute(['contact_id' => $mailInfo['contact_id']]);
                        $contact = $contactQuery->fetch(PDO::FETCH_ASSOC);

                        if ($contact && !empty($contact['email'])) {
                            $placeholders = [
                                'display_name' => $contact['display_name'] ?? 'Member',
                                'credits_used' => number_format($mailInfo['credits_used'], 1),
                                'months_extended' => $mailInfo['months_extended'],
                                'new_end_date' => $mailInfo['new_end_date']
                            ];
                            MailHelper::sendTemplate($contact['email'], 'credits_converted', $placeholders, $mailInfo['contact_id'], $_SESSION['user']['contact_id']);
                        }
                    } catch (Exception $mailEx) {
                        error_log("Failed to send volunteer credits conversion email: " . $mailEx->getMessage());
                    }
                }
                
                $successMsg = "Successfully processed volunteer credits and extended memberships for selected members.";
            } catch (Exception $e) {
                if (isset($appDb) && $appDb->inTransaction()) $appDb->rollBack();
                $errorMsg = safe_err("Processing failed: ", $e);
            }
        }
    }
}

// GET Handler: Retrieve Settings
// renewal_grace_days lives in this table for storage convenience but is a
// membership setting, edited on the Memberships page -- not shown here.
try {
    $appDb = Database::getAppConnection();
    $stmt = $appDb->query("SELECT credit_key, credit_label, credits FROM tgg_volunteer_credits WHERE credit_key <> 'renewal_grace_days' ORDER BY id ASC");
    $creditSettings = $stmt->fetchAll();
} catch (Exception $e) {
    $creditSettings = [];
    $errorMsg = safe_err("Unable to retrieve credits: ", $e);
}

// GET Handler: Load Eligible Unprocessed Signups
$startDate = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$endDate = isset($_GET['end_date']) ? $_GET['end_date'] : '';
$eligibleMembers = [];

if (!empty($startDate) && !empty($endDate)) {
    try {
        $appDb = Database::getAppConnection();
        
        // Fetch all credits configs
        $configsStmt = $appDb->query("SELECT credit_key, credits FROM tgg_volunteer_credits");
        $creditsMap = $configsStmt->fetchAll(PDO::FETCH_KEY_PAIR);
        $conversionRate = (float)($creditsMap['credits_per_month'] ?? 4.0);
        if ($conversionRate <= 0) $conversionRate = 4.0;
        $expirationDays = (float)($creditsMap['credit_expiration_days'] ?? 365.0);
        
        // Fetch all unprocessed signups during the time range
        $stmtSignups = $appDb->prepare("
            SELECT s.slot_id, sl.event_id, s.contact_id, sl.slot_label AS role, sl.slot_type, e.title, e.start_time
            FROM tgg_volunteer_signups s
            INNER JOIN tgg_event_slots sl ON sl.id = s.slot_id
            INNER JOIN tgg_events e ON sl.event_id = e.id
            LEFT JOIN tgg_volunteer_credit_transactions t ON t.slot_id = s.slot_id AND t.contact_id = s.contact_id
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
            
            // Fetch contact names from local contacts
            $contactIds = array_keys($groupedSignups);
            $placeholders = implode(',', array_fill(0, count($contactIds), '?'));
            $stmtNames = $appDb->prepare("SELECT id, display_name FROM tgg_contacts WHERE id IN ({$placeholders})");
            $stmtNames->execute(array_values($contactIds));
            $namesMap = $stmtNames->fetchAll(PDO::FETCH_KEY_PAIR);
            
            // Recalculate member settings (current credits_earned & credits_applied & expired_credits)
            $settingsMap = [];
            foreach ($contactIds as $cid) {
                $settingsMap[$cid] = updateMemberCredits($appDb, (int)$cid, $expirationDays);
            }
            
            // Fetch current expiration dates
            $stmtSubs = $appDb->prepare("SELECT contact_id, end_date FROM tgg_subscriptions WHERE contact_id IN ({$placeholders})");
            $stmtSubs->execute(array_values($contactIds));
            $subsMap = $stmtSubs->fetchAll(PDO::FETCH_KEY_PAIR);
            
            foreach ($groupedSignups as $cid => $shifts) {
                $newCredits = 0.0;
                $shiftDetails = [];
                
                foreach ($shifts as $shift) {
                    $key = EventSlot::creditKey($shift['slot_type'], $shift['start_time']);
                    $val = (float)($creditsMap[$key] ?? 0.0);
                    $newCredits += $val;
                    
                    $valFormatted = ($val == (int)$val) ? (int)$val : $val;
                    $shiftDetails[] = date('M j', strtotime($shift['start_time'])) . ' ' . $valFormatted;
                }
                
                $currAvailableBefore = (float)($settingsMap[$cid]['available_credits'] ?? 0.0);
                
                $totalUnapplied = $currAvailableBefore + $newCredits;
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
                    'shift_list' => implode("\n", $shiftDetails),
                    'new_credits' => $newCredits,
                    'unapplied_credits' => $totalUnapplied,
                    'expired_credits' => $settingsMap[$cid]['expired_credits'] ?? 0.0,
                    'current_end_date' => $currEnd ?: 'No active membership',
                    'extension_months' => $monthsToExtend,
                    'proposed_end_date' => $proposedEnd
                ];
            }
        }
    } catch (Exception $e) {
        $errorMsg = safe_err("Error loading eligible signups: ", $e);
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
    <link rel="stylesheet" href="../assets/css/style.css<?php echo asset_version('assets/css/style.css'); ?>">
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
        <?php $navAdminArea = true; $navActive = 'admin'; include __DIR__ . '/../partials/navbar.php'; ?>

        <main class="main-content">
            <div class="admin-grid">
                <?php include 'sidebar.php'; ?>

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
                                        <?php foreach ($creditSettings as $setting): 
                                            $isDays = ($setting['credit_key'] === 'credit_expiration_days');
                                        ?>
                                            <tr>
                                                <td style="font-weight: 600; color: #fff;">
                                                    <?php echo e($setting['credit_label']); ?>
                                                </td>
                                                <td>
                                                    <input type="number" 
                                                        class="credits-input" 
                                                        name="credits[<?php echo e($setting['credit_key']); ?>]" 
                                                        value="<?php echo $isDays ? (int)$setting['credits'] : (float)$setting['credits']; ?>" 
                                                        step="<?php echo $isDays ? '1' : '0.1'; ?>" 
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
                                                        <span style="display: block; font-size: 0.75rem; color: var(--color-text-secondary); font-weight: normal;">
                                                            ID: <?php echo e($member['contact_id']); ?>
                                                            <?php if ($member['expired_credits'] > 0.0): ?>
                                                                | Expired: <?php echo (float)$member['expired_credits']; ?>
                                                            <?php endif; ?>
                                                        </span>
                                                    </td>
                                                    <td style="font-size: 0.8rem; color: var(--color-text-secondary); line-height: 1.4;">
                                                        <?php echo nl2br(e($member['shift_list'])); ?>
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

        <?php include __DIR__ . '/../partials/footer.php'; ?>

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
