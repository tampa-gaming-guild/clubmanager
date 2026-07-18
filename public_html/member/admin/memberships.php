<?php
/**
 * Admin Memberships Management
 * Manage membership levels, descriptions, pricing, and billing intervals (monthly/yearly).
 * Synchronizes local plans and CiviCRM membership types.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config/bootstrap.php';

use App\Auth;
use App\AuditLog;
use App\BillingHelper;

Auth::requirePermission('manage configuration');

$errorMsg = null;
$successMsg = null;
$plans = [];
$editPlan = null;
$planFormError = false;

// Handle Form Submission (Save Grace Period)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_grace_period'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errorMsg = "Invalid security token. Please try again.";
    } else {
        $graceDays = (int)($_POST['renewal_grace_days'] ?? 30);
        try {
            $appDb = App\Database::getAppConnection();
            $stmt = $appDb->prepare("
                INSERT INTO tgg_volunteer_credits (credit_key, credit_label, credits) 
                VALUES ('renewal_grace_days', 'Renewal Grace Period (Days)', :days)
                ON DUPLICATE KEY UPDATE credits = VALUES(credits)
            ");
            $stmt->execute(['days' => $graceDays]);
            $successMsg = "Renewal grace period updated successfully!";
            header("Location: memberships.php?success=" . urlencode($successMsg));
            exit;
        } catch (Exception $e) {
            $errorMsg = safe_err("Failed to save grace period: ", $e);
        }
    }
}

// Handle Form Submission (Add/Edit Plan)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_plan'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errorMsg = "Invalid security token. Please try again.";
    } else {
        $id = !empty($_POST['plan_id']) ? (int)$_POST['plan_id'] : null;
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $price = (float)($_POST['price'] ?? 0);
        $durationInterval = (int)($_POST['duration_interval'] ?? 1);
        $durationUnit = strtolower($_POST['duration_unit'] ?? 'year');
        $active = trim($_POST['active'] ?? 'active');
        $guestsPerMonth = (int)($_POST['guests_per_month'] ?? 0);

        try {
            $data = [
                'id' => $id,
                'name' => $name,
                'description' => $description,
                'price' => $price,
                'duration_interval' => $durationInterval,
                'duration_unit' => $durationUnit,
                'active' => $active,
                'guests_per_month' => $guestsPerMonth
            ];

            $result = BillingHelper::savePlan($data);

            AuditLog::log('rates', 'plan_saved', [
                'plan_id' => $result['plan_id'],
                'name' => $name,
                'price' => $price,
                'active' => $active,
                'new_rate_created' => $result['new_rate_created'],
                'effective_date' => $result['effective_date']
            ]);

            if ($result['new_rate_created']) {
                $successMsg = "Membership level updated. New rate added, effective " . date('F j, Y', strtotime($result['effective_date'])) . ".";
            } else {
                $successMsg = $id ? "Membership level updated successfully!" : "New membership level added successfully!";
            }

            // Redirect to clean POST state
            header("Location: memberships.php?success=" . urlencode($successMsg));
            exit;
        } catch (Exception $e) {
            $errorMsg = safe_err("Failed to save plan: ", $e);
            $planFormError = true;
        }
    }
}

// Fetch successful redirect status
if (isset($_GET['success'])) {
    $successMsg = $_GET['success'];
}

// Load plan details if in edit mode
if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
    $editId = (int)$_GET['id'];
    $plans = BillingHelper::getSubscriptionPlans();
    foreach ($plans as $p) {
        if ((int)$p['id'] === $editId) {
            $editPlan = $p;
            break;
        }
    }
    if (!$editPlan) {
        $errorMsg = "Plan not found.";
    }
} else {
    $plans = BillingHelper::getSubscriptionPlans();
}

$renewalGraceDays = BillingHelper::getRenewalGraceDays();

// Add/Edit Membership Level lives in a modal (see bottom of page) -- it auto-opens
// whenever there's something for it to show: editing, or a validation error from a
// submission it needs to redisplay.
$autoOpenPlanModal = $editPlan || $planFormError;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Memberships - Admin Panel</title>
    <link rel="shortcut icon" href="../favicon.ico" type="image/x-icon">
    <link rel="icon" type="image/png" href="../favicon.png">
    <link rel="apple-touch-icon" href="../favicon.png">
    <link rel="manifest" href="../manifest.json">
    <link rel="stylesheet" href="../assets/css/style.css<?php echo asset_version('assets/css/style.css'); ?>">
    <style>
        .form-actions {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }
        .reports-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            margin-top: 10px;
        }
        .reports-table th {
            padding: 12px 24px;
            border-bottom: 1px solid rgba(255,255,255,0.08);
            color: var(--color-text-secondary);
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .reports-table td {
            padding: 14px 24px;
            border-bottom: 1px solid rgba(255,255,255,0.04);
        }
        .reports-table tr:hover {
            background: rgba(255, 255, 255, 0.02);
        }
    </style>
</head>
<body>
    <div class="app-container">
        <?php $navAdminArea = true; $navActive = 'admin'; include __DIR__ . '/../partials/navbar.php'; ?>

        <main class="main-content">
            <div class="admin-grid">
                
                <?php include 'sidebar.php'; ?>

                <!-- Work Area -->
                <section class="admin-workspace glass-panel">
                    <h2>Membership Levels & Pricing</h2>
                    <p class="description-text" style="margin-bottom: 20px;">
                        Configure pricing plans, descriptions, and durations for member subscriptions.
                    </p>

                    <?php if ($errorMsg): ?>
                        <div class="alert alert-danger"><?php echo e($errorMsg); ?></div>
                    <?php endif; ?>

                    <?php if ($successMsg): ?>
                        <div class="alert alert-success"><?php echo e($successMsg); ?></div>
                    <?php endif; ?>

                    <!-- List of Plans -->
                    <div class="plans-list-container mt-20">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                            <h3 style="margin: 0;">Active Membership Tiers</h3>
                            <button type="button" class="btn btn-primary btn-sm" onclick="openPlanModal()">+ Add Membership Level</button>
                        </div>
                        <div style="overflow-x: auto;">
                            <table class="reports-table">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Price</th>
                                        <th>Billing Cycle</th>
                                        <th>Guests/mo</th>
                                        <th>Status</th>
                                        <th style="width: 100px; text-align: center;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($plans)): ?>
                                        <?php foreach ($plans as $plan): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo e($plan['name']); ?></strong><br>
                                                    <small style="color: var(--color-text-muted);"><?php echo e($plan['description'] ?? 'No description'); ?></small>
                                                </td>
                                                <td style="font-size: 0.85rem;">$<?php echo (float)$plan['price'] == (int)$plan['price'] ? number_format($plan['price'], 0) : number_format($plan['price'], 2); ?></td>
                                                <td style="font-size: 0.85rem;">
                                                    <?php if (strtolower($plan['duration_unit']) === 'session'): ?>
                                                        Per Session (extends 1 year)
                                                    <?php elseif (strtolower($plan['duration_unit']) !== 'day'): ?>
                                                        Every <?php echo (int)$plan['duration_interval']; ?> <?php echo e(ucfirst($plan['duration_unit'])); ?>(s)
                                                    <?php endif; ?>
                                                </td>
                                                <td style="font-size: 0.85rem;"><?php echo (int)($plan['guests_per_month'] ?? 0); ?></td>
                                                <td style="font-size: 0.85rem;">
                                                    <span class="badge <?php echo $plan['active'] === 'active' ? 'badge-active' : 'badge-expired'; ?>" style="font-size: 0.7rem; padding: 2px 6px;">
                                                        <?php echo e(ucfirst($plan['active'] ?? 'active')); ?>
                                                    </span>
                                                </td>
                                                 <td style="text-align: center; white-space: nowrap;">
                                                     <a href="memberships.php?action=edit&id=<?php echo (int)$plan['id']; ?>" class="btn btn-warning btn-sm" style="display: inline-block; padding: 4px 8px; font-size: 0.8rem;">Edit</a>
                                                     <a href="rates.php?plan_id=<?php echo (int)$plan['id']; ?>" class="btn btn-primary btn-sm" style="display: inline-block; padding: 4px 8px; font-size: 0.8rem;">Rates</a>
                                                 </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="6" class="text-center text-muted">No membership levels defined.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Renewal Grace Period Setting Form -->
                    <div class="glass-panel mt-20" style="padding: 20px;">
                        <h3>Renewal Grace Period</h3>
                        <p class="description-text" style="margin-bottom: 15px;">
                            Configure the number of days a member can renew their existing membership after expiration.
                            If renewed within this window, the membership period extends from their former expiration date.
                            Otherwise, a brand-new membership period starts from the current date.
                        </p>
                        <form action="memberships.php" method="POST" class="auth-form" style="max-width: 400px;">
                            <input type="hidden" name="csrf_token" value="<?php echo e(get_csrf_token()); ?>">
                            <div class="form-group">
                                <label for="renewal_grace_days">Grace Period Limit (Days)</label>
                                <input type="number" id="renewal_grace_days" name="renewal_grace_days" required min="0" placeholder="30" value="<?php echo (int)$renewalGraceDays; ?>">
                            </div>
                            <div class="form-actions">
                                <button type="submit" name="save_grace_period" class="btn btn-primary">Save Settings</button>
                            </div>
                        </form>
                    </div>
                </section>
            </div>
        </main>

        <?php include __DIR__ . '/../partials/footer.php'; ?>

        <!-- Add/Edit Membership Level Modal -->
        <div id="plan-modal" class="modal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.6); backdrop-filter: blur(5px);">
            <div class="modal-content glass-panel" style="background: rgba(30, 30, 40, 0.95); margin: 5% auto; padding: 25px; border: 1px solid rgba(255, 255, 255, 0.1); width: 90%; max-width: 480px; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.5);">
                <div class="modal-header" style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid rgba(255, 255, 255, 0.1); padding-bottom: 15px; margin-bottom: 20px;">
                    <h3 style="margin: 0; color: #fff; font-size: 1.2rem;"><?php echo $editPlan ? 'Edit Membership Level' : 'Add Membership Level'; ?></h3>
                    <span class="close" onclick="closePlanModal()" style="color: rgba(255,255,255,0.6); font-size: 28px; font-weight: bold; cursor: pointer; transition: color 0.2s;">&times;</span>
                </div>

                <form action="memberships.php" method="POST" class="auth-form">
                    <input type="hidden" name="csrf_token" value="<?php echo e(get_csrf_token()); ?>">
                    <input type="hidden" name="plan_id" value="<?php echo $editPlan ? (int)$editPlan['id'] : ''; ?>">

                    <div class="form-group">
                        <label for="name">Level Name</label>
                        <input type="text" id="name" name="name" required placeholder="e.g. Monthly Standard" value="<?php echo $editPlan ? e($editPlan['name']) : ''; ?>">
                    </div>

                    <div class="form-group">
                        <label for="description">Description</label>
                        <input type="text" id="description" name="description" placeholder="e.g. Standard monthly individual membership" value="<?php echo $editPlan ? e($editPlan['description']) : ''; ?>">
                    </div>

                    <div class="form-group">
                        <label for="price">Price (USD)</label>
                        <input type="number" id="price" name="price" required min="0" step="0.01" placeholder="e.g. 15.00" value="<?php echo $editPlan ? (float)$editPlan['price'] : ''; ?>">
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="duration_interval">Billing Every</label>
                            <input type="number" id="duration_interval" name="duration_interval" required min="1" placeholder="1" value="<?php echo $editPlan ? (int)$editPlan['duration_interval'] : '1'; ?>">
                        </div>
                        <div class="form-group">
                            <label for="duration_unit">Interval Unit</label>
                            <select id="duration_unit" name="duration_unit" required>
                                <option value="day" <?php echo ($editPlan && $editPlan['duration_unit'] === 'day') ? 'selected' : ''; ?>>Day(s)</option>
                                <option value="month" <?php echo ($editPlan && $editPlan['duration_unit'] === 'month') ? 'selected' : ''; ?>>Month(s)</option>
                                <option value="year" <?php echo (!$editPlan || ($editPlan && $editPlan['duration_unit'] === 'year')) ? 'selected' : ''; ?>>Year(s)</option>
                                <option value="session" <?php echo ($editPlan && $editPlan['duration_unit'] === 'session') ? 'selected' : ''; ?>>Session</option>
                            </select>
                            <small class="field-hint">"Session" ignores Billing Every: members are never charged at join/renewal, pay this plan's rate at check-in instead, and each paid check-in extends them exactly one year. Not offered as a brand-new self-service signup -- renewal or staff-added only.</small>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="guests_per_month">Guest Passes per Month</label>
                        <input type="number" id="guests_per_month" name="guests_per_month" required min="0" step="1" placeholder="0" value="<?php echo $editPlan ? (int)$editPlan['guests_per_month'] : '0'; ?>">
                    </div>

                    <div class="form-group">
                        <label for="active">Status</label>
                        <select id="active" name="active" required>
                            <option value="active" <?php echo (!$editPlan || ($editPlan && ($editPlan['active'] ?? 'active') === 'active')) ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo ($editPlan && ($editPlan['active'] ?? 'active') === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>

                    <div class="form-actions">
                        <button type="submit" name="save_plan" class="btn btn-primary"><?php echo $editPlan ? 'Save Changes' : 'Create Level'; ?></button>
                        <?php if ($editPlan): ?>
                            <a href="memberships.php" class="btn btn-secondary" style="text-decoration: none; display: flex; align-items: center; justify-content: center; height: 38px; padding: 0 15px;">Cancel</a>
                        <?php else: ?>
                            <button type="button" class="btn btn-secondary" onclick="closePlanModal()">Cancel</button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
    function openPlanModal() {
        document.getElementById('plan-modal').style.display = 'block';
    }
    function closePlanModal() {
        document.getElementById('plan-modal').style.display = 'none';
    }
    window.addEventListener('click', function(event) {
        const modal = document.getElementById('plan-modal');
        if (event.target === modal) {
            modal.style.display = 'none';
        }
    });
    <?php if ($autoOpenPlanModal): ?>
    document.addEventListener('DOMContentLoaded', openPlanModal);
    <?php endif; ?>

    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('../sw.js')
                .then(reg => console.log('Service Worker registered'))
                .catch(err => console.error('Service Worker registration failed', err));
        });
    }
    </script>
</body>
</html>
