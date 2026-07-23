<?php
/**
 * Admin Membership Credits: Config & Manual Override
 * Lets a Hosting Manager configure the Membership Credits earned for specific
 * hosting shifts, and manually apply banked credits to extend a member's
 * subscription outside of normal renewal timing. Credits themselves are
 * granted automatically by bin/autorenew.php once a worked shift clears its
 * grace period -- see MembershipCredits::autoConfirmEligibleAttendance().
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config/bootstrap.php';

use App\Auth;
use App\AuditLog;
use App\BillingHelper;
use App\Database;
use App\MembershipCredits;
use App\MembershipService;

Auth::requireAuth();
if (!has_permission('manage hosting') && !has_permission('admin panel')) {
    redirect('index.php?error=unauthorized');
}
$canManageHosting = has_permission('manage hosting');
$canGrantCredits = has_permission('admin panel');

$errorMsg = null;
$successMsg = null;

$actorId = $_SESSION['user']['contact_id'] ?? null;

// Load credit weight settings (Section 1) and the member picker list
// (Sections 2 & 3) up front -- the POST handler below needs the lookup maps
// derived from these to resolve a submitted grant reason/member name.
// renewal_grace_days lives in this table for storage convenience but is a
// membership setting, edited on the Memberships page -- not shown here.
try {
    $appDb = Database::getAppConnection();
    $stmt = $appDb->query("SELECT credit_key, credit_label, credits FROM tgg_volunteer_credits WHERE credit_key <> 'renewal_grace_days' ORDER BY id ASC");
    $creditSettings = $stmt->fetchAll();
} catch (Exception $e) {
    $creditSettings = [];
    $errorMsg = safe_err(($errorMsg ? $errorMsg . " | " : "") . "Unable to retrieve credits: ", $e);
}
$creditLabelByKey = array_column($creditSettings, 'credit_label', 'credit_key');

$allActiveMembers = [];
try {
    $allActiveMembers = MembershipService::getMembersList();
} catch (Exception $e) {
    // Fallback to empty
}
$memberNameById = array_column($allActiveMembers, 'display_name', 'id');

// POST Handler: Update settings, apply credits now, or grant credits
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errorMsg = "Invalid security token.";
    } elseif (isset($_POST['action_update_settings'])) {
        // A. Update credit weight configuration
        if (!$canManageHosting) {
            $errorMsg = "You do not have permission to update credit settings.";
        } else {
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
                    $valInt = (int)round((float)$val);
                    if ($valInt < 0) {
                        throw new Exception("Credit values cannot be negative.");
                    }
                    $stmt->execute([
                        'credits' => $valInt,
                        'key' => $key
                    ]);
                    if (array_key_exists($key, $beforeValues) && (int)round((float)$beforeValues[$key]) !== $valInt) {
                        $changes[$key] = ['old' => (int)round((float)$beforeValues[$key]), 'new' => $valInt];
                    }
                }
                $appDb->commit();

                if (!empty($changes)) {
                    AuditLog::log('volunteer_config', 'credit_settings_updated', ['changes' => $changes]);
                }
                $successMsg = "Membership Credit settings updated successfully.";
            } catch (Exception $e) {
                if (isset($appDb) && $appDb->inTransaction()) {
                    $appDb->rollBack();
                }
                $errorMsg = safe_err("Failed to update credits: ", $e);
            }
        }
    } elseif (isset($_POST['action_apply_credits'])) {
        // B. Manual override: apply a member's banked Membership Credits now,
        // outside of normal renewal timing.
        if (!$canManageHosting) {
            $errorMsg = "You do not have permission to apply Membership Credits.";
        } else {
            $targetContactId = (int)($_POST['apply_contact_id'] ?? 0);
            $months = (int)($_POST['apply_months'] ?? 0);
            if ($targetContactId <= 0) {
                $errorMsg = "Please select a member.";
            } elseif ($months < 1) {
                $errorMsg = "Please enter at least one month to apply.";
            } else {
                try {
                    $result = BillingHelper::applyMembershipCreditsToMembership($targetContactId, $months, (int)$actorId);
                    $successMsg = "Applied {$result['months_applied']} month(s) of Membership Credits -- new expiration date " . date('F j, Y', strtotime($result['end_date'])) . ".";
                } catch (Exception $e) {
                    $errorMsg = safe_err("Failed to apply Membership Credits: ", $e);
                }
            }
        }
    } elseif (isset($_POST['action_grant_credits'])) {
        // C. Grant Membership Credits directly, for a reason outside the
        // normal hosting-attendance earning flow (Admin Panel permission).
        if (!$canGrantCredits) {
            $errorMsg = "You do not have permission to grant Membership Credits.";
        } else {
            $targetContactId = (int)($_POST['grant_contact_id'] ?? 0);
            $creditsToGrant = (int)($_POST['grant_credits'] ?? 0);
            $reasonKey = $_POST['grant_reason_key'] ?? '';
            $customReason = trim($_POST['grant_reason_custom'] ?? '');
            $reason = ($reasonKey === '__other__')
                ? $customReason
                : ($creditLabelByKey[$reasonKey] ?? $customReason);

            if ($targetContactId <= 0) {
                $errorMsg = "Please select a member.";
            } else {
                try {
                    MembershipCredits::grantManualCredits($targetContactId, $creditsToGrant, $reason);
                    $memberName = $memberNameById[$targetContactId] ?? "Member #{$targetContactId}";
                    $successMsg = "Granted {$creditsToGrant} Membership Credit(s) to " . $memberName . ".";
                } catch (Exception $e) {
                    $errorMsg = safe_err("Failed to grant Membership Credits: ", $e);
                }
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
    <title>Membership Credits - Admin Dashboard</title>
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
            flex-wrap: wrap;
        }
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
            margin-bottom: 0;
        }
        .form-group label {
            font-size: 0.85rem;
            color: var(--color-text-secondary);
            font-weight: 500;
        }
        .form-group-btn {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .form-group-btn .btn-spacer {
            font-size: 0.85rem;
            line-height: 1;
            visibility: hidden;
        }
    </style>
</head>
<body>
    <div class="app-container">
        <?php $navAdminArea = true; $navActive = 'admin'; include __DIR__ . '/../partials/navbar.php'; ?>

        <main class="main-content">
            <div class="admin-grid">
                <?php include 'sidebar.php'; ?>

                <!-- Membership Credits Workspace -->
                <section class="admin-workspace glass-panel">
                    <?php if ($errorMsg): ?>
                        <div class="alert alert-danger" style="margin-bottom: 20px;"><?php echo e($errorMsg); ?></div>
                    <?php endif; ?>

                    <?php if ($successMsg): ?>
                        <div class="alert alert-success" style="margin-bottom: 20px;"><?php echo e($successMsg); ?></div>
                    <?php endif; ?>

                    <?php $priorSectionShown = false; ?>

                    <?php if ($canManageHosting): ?>
                    <h2>Membership Credit Weights</h2>
                    <p class="description-text" style="margin-bottom: 25px;">
                        Configure the Membership Credits earned by members for filling specific hosting shift roles, and the exchange rate for free membership months. Hosting is currently the only way to earn Membership Credits automatically, but they can also be granted manually below.
                    </p>

                    <form action="volunteer_credits.php" method="POST" style="margin-bottom: 50px;">
                        <input type="hidden" name="csrf_token" value="<?php echo e(get_csrf_token()); ?>">
                        <input type="hidden" name="action_update_settings" value="1">

                        <div class="admin-table-container">
                            <table class="admin-table" style="width: 100%; margin-bottom: 20px;">
                                <thead>
                                    <tr>
                                        <th style="width: 60%;">Shift / Configuration Property</th>
                                        <th style="width: 40%;">Membership Credits Value</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($creditSettings)): ?>
                                        <tr>
                                            <td colspan="2" style="text-align: center; color: var(--color-text-secondary); padding: 20px;">
                                                No Membership Credit records found.
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
                                                        value="<?php echo (int)round((float)$setting['credits']); ?>"
                                                        step="1"
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
                    <?php $priorSectionShown = true; ?>
                    <?php endif; ?>

                    <?php if ($canGrantCredits): ?>
                    <?php if ($priorSectionShown): ?>
                        <hr style="border: none; border-bottom: 1px solid var(--border-glass); margin-bottom: 40px;">
                    <?php endif; ?>
                    <h2>Grant Membership Credits</h2>
                    <p class="description-text" style="margin-bottom: 25px;">
                        Award Membership Credits to a member directly, for a reason outside the normal hosting-attendance earning flow -- pick one of the configured credit types above, or enter a custom reason (e.g. "Saturday Cleanup"). Granted credits post immediately and are recorded in the Audit Log just like automatic grants.
                    </p>

                    <form action="volunteer_credits.php" method="POST" class="form-row" data-confirm="Grant these Membership Credits now?">
                        <input type="hidden" name="csrf_token" value="<?php echo e(get_csrf_token()); ?>">
                        <input type="hidden" name="action_grant_credits" value="1">

                        <div class="form-group">
                            <label for="grant-member">Member</label>
                            <input type="text" id="grant-member" list="grant-members-list" placeholder="Type member name..." style="width: 260px; padding: 8px 12px; border-radius: 6px; border: 1px solid var(--border-glass); background: rgba(255, 255, 255, 0.05); color: #fff;" oninput="updateGrantMemberId(this)" required>
                            <input type="hidden" id="grant_contact_id" name="grant_contact_id" value="">
                        </div>
                        <div class="form-group">
                            <label for="grant-reason-key">Reason</label>
                            <select id="grant-reason-key" name="grant_reason_key" class="date-input" style="width: 220px;" onchange="toggleGrantCustomReason(this)">
                                <?php foreach ($creditSettings as $setting): ?>
                                    <option value="<?php echo e($setting['credit_key']); ?>"><?php echo e($setting['credit_label']); ?></option>
                                <?php endforeach; ?>
                                <option value="__other__">Other (custom reason)&hellip;</option>
                            </select>
                        </div>
                        <div class="form-group" id="grant-custom-reason-group" style="display: none;">
                            <label for="grant-reason-custom">Custom Reason</label>
                            <input type="text" id="grant-reason-custom" name="grant_reason_custom" placeholder="e.g. Saturday Cleanup" style="width: 220px; padding: 8px 12px; border-radius: 6px; border: 1px solid var(--border-glass); background: rgba(255, 255, 255, 0.05); color: #fff;">
                        </div>
                        <div class="form-group">
                            <label for="grant-credits">Credits to Grant</label>
                            <input type="number" id="grant-credits" name="grant_credits" class="date-input" style="width: 100px;" min="1" step="1" required>
                        </div>
                        <div class="form-group-btn">
                            <span class="btn-spacer">&nbsp;</span>
                            <button type="submit" class="btn btn-success" style="padding: 10px 20px;">Grant Credits</button>
                        </div>
                    </form>

                    <datalist id="grant-members-list">
                        <?php foreach ($allActiveMembers as $member): ?>
                            <option value="<?php echo e($member['display_name'] . ' (ID: ' . $member['id'] . ')'); ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                    <?php $priorSectionShown = true; ?>
                    <?php endif; ?>

                    <?php if ($canManageHosting): ?>
                    <?php if ($priorSectionShown): ?>
                        <hr style="border: none; border-bottom: 1px solid var(--border-glass); margin-bottom: 40px;">
                    <?php endif; ?>
                    <h2>Apply Membership Credits Now</h2>
                    <p class="description-text" style="margin-bottom: 25px;">
                        Manual override for support cases outside normal renewal timing -- Membership Credits are otherwise applied automatically at auto-renewal (if the member opted in) or offered as a choice during manual renewal.
                    </p>

                    <form action="volunteer_credits.php" method="POST" class="form-row" data-confirm="Apply this member's banked Membership Credits now to extend their membership?">
                        <input type="hidden" name="csrf_token" value="<?php echo e(get_csrf_token()); ?>">
                        <input type="hidden" name="action_apply_credits" value="1">

                        <div class="form-group">
                            <label for="apply-member">Member</label>
                            <input type="text" id="apply-member" list="apply-members-list" placeholder="Type member name..." style="width: 260px; padding: 8px 12px; border-radius: 6px; border: 1px solid var(--border-glass); background: rgba(255, 255, 255, 0.05); color: #fff;" oninput="updateApplyMemberId(this)" required>
                            <input type="hidden" id="apply_contact_id" name="apply_contact_id" value="">
                        </div>
                        <div class="form-group">
                            <label for="apply-months">Months to Apply</label>
                            <input type="number" id="apply-months" name="apply_months" class="date-input" style="width: 100px;" min="1" step="1" required>
                        </div>
                        <div class="form-group-btn">
                            <span class="btn-spacer">&nbsp;</span>
                            <button type="submit" class="btn btn-warning" style="padding: 10px 20px;">Apply Now</button>
                        </div>
                    </form>

                    <datalist id="apply-members-list">
                        <?php foreach ($allActiveMembers as $member): ?>
                            <option value="<?php echo e($member['display_name'] . ' (ID: ' . $member['id'] . ')'); ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                    <?php endif; ?>
                </section>
            </div>
        </main>

        <?php include __DIR__ . '/../partials/footer.php'; ?>

    <script>
    function updateApplyMemberId(input) {
        const match = input.value.match(/\(ID:\s*(\d+)\)/);
        document.getElementById('apply_contact_id').value = match ? match[1] : '';
    }
    function updateGrantMemberId(input) {
        const match = input.value.match(/\(ID:\s*(\d+)\)/);
        document.getElementById('grant_contact_id').value = match ? match[1] : '';
    }
    function toggleGrantCustomReason(select) {
        const group = document.getElementById('grant-custom-reason-group');
        const customInput = document.getElementById('grant-reason-custom');
        const isOther = select.value === '__other__';
        group.style.display = isOther ? 'flex' : 'none';
        customInput.required = isOther;
    }
    </script>
</body>
</html>
