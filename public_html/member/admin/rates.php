<?php
/**
 * Admin Rates Management
 * Manage multiple payment rates for membership plans.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config/bootstrap.php';

use App\Auth;
use App\BillingHelper;
use App\Database;

Auth::requirePermission('manage configuration');

$errorMsg = null;
$successMsg = null;
$rates = [];
$editRate = null;
$pendingConfirm = null;
$rateFormError = false;

$planId = isset($_GET['plan_id']) ? (int)$_GET['plan_id'] : 0;
if ($planId <= 0) {
    header("Location: memberships.php");
    exit;
}

try {
    $appDb = Database::getAppConnection();
    
    // Fetch plan details
    $planStmt = $appDb->prepare("SELECT * FROM tgg_subscription_plans WHERE id = :id LIMIT 1");
    $planStmt->execute(['id' => $planId]);
    $plan = $planStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$plan) {
        header("Location: memberships.php");
        exit;
    }
} catch (Exception $e) {
    die(safe_err("Database Connection Error: ", $e));
}

// Handle Form Submission (Add/Edit Rate)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_rate'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errorMsg = "Invalid security token. Please try again.";
    } else {
        $rateId = !empty($_POST['rate_id']) ? (int)$_POST['rate_id'] : null;
        $name = trim($_POST['name'] ?? '');
        $price = (float)($_POST['price'] ?? 0);
        $billingFrequency = trim($_POST['billing_frequency'] ?? 'monthly');
        $inactive = isset($_POST['inactive']) ? 1 : 0;
        $expirationDate = !empty($_POST['expiration_date']) ? $_POST['expiration_date'] : null;
        $confirmPriceChange = isset($_POST['confirm_price_change']);

        try {
            if (empty($name)) {
                throw new Exception("Rate name cannot be empty.");
            }
            if ($price < 0) {
                throw new Exception("Price cannot be negative.");
            }
            if (!in_array($billingFrequency, ['annual', 'monthly', 'daily'])) {
                throw new Exception("Invalid billing frequency.");
            }

            if ($rateId) {
                // Editing a rate's price in place changes what every member currently on it
                // owes immediately -- unlike changing price via the Plan form (which creates a
                // new rate and leaves this one alone for anyone grandfathered onto it). Warn
                // before allowing that here, but don't hard-block it -- this screen is meant to
                // stay usable as an admin power-tool/escape-hatch.
                $existingStmt = $appDb->prepare("SELECT price FROM tgg_subscription_rates WHERE id = :id AND plan_id = :plan_id LIMIT 1");
                $existingStmt->execute(['id' => $rateId, 'plan_id' => $planId]);
                $existingPrice = $existingStmt->fetchColumn();
                if ($existingPrice === false) {
                    throw new Exception("Rate not found.");
                }

                $memberCountStmt = $appDb->prepare("SELECT COUNT(*) FROM tgg_subscriptions WHERE rate_id = :id");
                $memberCountStmt->execute(['id' => $rateId]);
                $memberCount = (int)$memberCountStmt->fetchColumn();

                $priceChanging = round((float)$existingPrice, 2) !== round($price, 2);

                if ($priceChanging && $memberCount > 0 && !$confirmPriceChange) {
                    $errorMsg = "{$memberCount} member(s) are currently on this rate. Changing the price changes what they're charged immediately. Review and confirm below to proceed.";
                    // Keyed like $editRate (a plain "SELECT *" row, so 'id' not 'rate_id') since
                    // the form below reads whichever of the two -- $pendingConfirm or $editRate
                    // -- is active through a single $formValues variable.
                    $pendingConfirm = [
                        'id' => $rateId, 'name' => $name, 'price' => $price,
                        'billing_frequency' => $billingFrequency, 'inactive' => $inactive,
                        'expiration_date' => $expirationDate
                    ];
                } else {
                    $updateRate = $appDb->prepare("
                        UPDATE tgg_subscription_rates
                        SET name = :name, price = :price, billing_frequency = :billing_frequency, inactive = :inactive, expiration_date = :expiration_date
                        WHERE id = :id AND plan_id = :plan_id
                    ");
                    $updateRate->execute([
                        'name' => $name,
                        'price' => $price,
                        'billing_frequency' => $billingFrequency,
                        'inactive' => $inactive,
                        'expiration_date' => $expirationDate,
                        'id' => $rateId,
                        'plan_id' => $planId
                    ]);
                    $successMsg = "Payment rate updated successfully!";
                    header("Location: rates.php?plan_id=" . $planId . "&success=" . urlencode($successMsg));
                    exit;
                }
            } else {
                // Insert new rate
                $insertRate = $appDb->prepare("
                    INSERT INTO tgg_subscription_rates (plan_id, name, price, billing_frequency, inactive, expiration_date, created_at)
                    VALUES (:plan_id, :name, :price, :billing_frequency, :inactive, :expiration_date, NOW())
                ");
                $insertRate->execute([
                    'plan_id' => $planId,
                    'name' => $name,
                    'price' => $price,
                    'billing_frequency' => $billingFrequency,
                    'inactive' => $inactive,
                    'expiration_date' => $expirationDate
                ]);
                $successMsg = "New payment rate added successfully!";
                header("Location: rates.php?plan_id=" . $planId . "&success=" . urlencode($successMsg));
                exit;
            }
        } catch (Exception $e) {
            $errorMsg = safe_err("Failed to save rate: ", $e);
            $rateFormError = true;
        }
    }
}

// Handle "End Rate & Move Members" -- explicit, admin-triggered retirement of a non-default
// rate. Never happens automatically.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['retire_rate'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errorMsg = "Invalid security token. Please try again.";
    } else {
        $retireId = (int)($_POST['rate_id'] ?? 0);
        try {
            $result = BillingHelper::retireRate($retireId, $planId);
            $successMsg = "Rate retired. {$result['moved']} member(s) moved to the plan's current rate, {$result['emailed']} notified by email.";
            header("Location: rates.php?plan_id=" . $planId . "&success=" . urlencode($successMsg));
            exit;
        } catch (Exception $e) {
            $errorMsg = safe_err("Failed to retire rate: ", $e);
        }
    }
}

// Handle "Delete" -- only for rates nobody is currently on. A plan's current default rate
// can also be deleted (e.g. undoing a price change made by mistake), but only when there's
// a prior rate to fall back to -- deleting it then reverts the plan's default to whichever
// rate was most recently in that role, rather than leaving the plan with no default at all.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_rate'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errorMsg = "Invalid security token. Please try again.";
    } else {
        $deleteId = (int)($_POST['rate_id'] ?? 0);
        try {
            $memberCountStmt = $appDb->prepare("SELECT COUNT(*) FROM tgg_subscriptions WHERE rate_id = :id");
            $memberCountStmt->execute(['id' => $deleteId]);
            if ((int)$memberCountStmt->fetchColumn() > 0) {
                throw new Exception("Members are still on this rate -- use \"End Rate\" to move them off first.");
            }

            $isDefault = (int)($plan['default_rate_id'] ?? 0) === $deleteId;
            if ($isDefault) {
                $fallbackStmt = $appDb->prepare("
                    SELECT id FROM tgg_subscription_rates
                    WHERE plan_id = :plan_id AND id != :id
                    ORDER BY created_at DESC, id DESC
                    LIMIT 1
                ");
                $fallbackStmt->execute(['plan_id' => $planId, 'id' => $deleteId]);
                $fallbackRateId = $fallbackStmt->fetchColumn();
                if (!$fallbackRateId) {
                    throw new Exception("Cannot delete this plan's only rate -- every plan needs at least one rate.");
                }

                $appDb->beginTransaction();
                try {
                    $appDb->prepare("UPDATE tgg_subscription_plans SET default_rate_id = :rate_id WHERE id = :id")
                        ->execute(['rate_id' => $fallbackRateId, 'id' => $planId]);
                    $delStmt = $appDb->prepare("DELETE FROM tgg_subscription_rates WHERE id = :id AND plan_id = :plan_id");
                    $delStmt->execute(['id' => $deleteId, 'plan_id' => $planId]);
                    $appDb->commit();
                } catch (Exception $e) {
                    if ($appDb->inTransaction()) {
                        $appDb->rollBack();
                    }
                    throw $e;
                }
                $successMsg = "Rate deleted. The plan's default reverted to its previous rate.";
            } else {
                $delStmt = $appDb->prepare("DELETE FROM tgg_subscription_rates WHERE id = :id AND plan_id = :plan_id");
                $delStmt->execute(['id' => $deleteId, 'plan_id' => $planId]);
                $successMsg = "Rate deleted.";
            }

            header("Location: rates.php?plan_id=" . $planId . "&success=" . urlencode($successMsg));
            exit;
        } catch (Exception $e) {
            $errorMsg = safe_err("Failed to delete rate: ", $e);
        }
    }
}

// Fetch successful redirect status
if (isset($_GET['success'])) {
    $successMsg = $_GET['success'];
}

// Load rate details if in edit mode
if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
    $editId = (int)$_GET['id'];
    try {
        $editStmt = $appDb->prepare("SELECT * FROM tgg_subscription_rates WHERE id = :id AND plan_id = :plan_id LIMIT 1");
        $editStmt->execute(['id' => $editId, 'plan_id' => $planId]);
        $editRate = $editStmt->fetch(PDO::FETCH_ASSOC);
        if (!$editRate) {
            $errorMsg = "Rate not found.";
        }
    } catch (Exception $e) {
        $errorMsg = "Error loading rate details.";
    }
}

// Fetch all rates for this plan, with a live count of members currently on each one --
// used both to render the table and to decide which action (End Rate vs Delete vs neither)
// is available per row.
try {
    $ratesStmt = $appDb->prepare("
        SELECT r.*, (SELECT COUNT(*) FROM tgg_subscriptions s WHERE s.rate_id = r.id) AS member_count
        FROM tgg_subscription_rates r
        WHERE r.plan_id = :plan_id
        ORDER BY r.created_at DESC, r.id DESC
    ");
    $ratesStmt->execute(['plan_id' => $planId]);
    $rates = $ratesStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $rates = [];
}

// $pendingConfirm (set above when a price change on an in-use rate needs confirmation)
// takes precedence over $editRate so the form re-shows exactly what the admin just typed,
// not what's still saved in the DB. Add/Edit Rate lives in a modal (see bottom of page) --
// it auto-opens whenever there's something for it to show: editing, a pending price-change
// confirmation, or a validation error from a submission it needs to redisplay.
$formValues = $pendingConfirm ?: $editRate;
$isEditing = $editRate || $pendingConfirm;
$autoOpenRateModal = $isEditing || $rateFormError;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Rates for <?php echo e($plan['name']); ?> - Admin Panel</title>
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
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                        <h2>Manage Rates: <?php echo e($plan['name']); ?></h2>
                        <a href="memberships.php" class="btn btn-secondary btn-sm" style="text-decoration: none;">&larr; Back to Memberships</a>
                    </div>
                    <p class="description-text" style="margin-bottom: 20px;">
                        Configure multiple payment rates (prices and billing frequencies) for the <strong><?php echo e($plan['name']); ?></strong> plan.
                    </p>

                    <?php if ($errorMsg): ?>
                        <div class="alert alert-danger"><?php echo e($errorMsg); ?></div>
                    <?php endif; ?>

                    <?php if ($successMsg): ?>
                        <div class="alert alert-success"><?php echo e($successMsg); ?></div>
                    <?php endif; ?>

                    <!-- List of Rates -->
                    <div class="plans-list-container mt-20">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                            <h3 style="margin: 0;">Rates Configured</h3>
                            <button type="button" class="btn btn-primary btn-sm" onclick="openRateModal()">+ Add Rate</button>
                        </div>
                        <div style="overflow-x: auto;">
                            <table class="reports-table">
                                <thead>
                                    <tr>
                                        <th>Effective Date</th>
                                        <th>Price</th>
                                        <th>Frequency</th>
                                        <th>Members</th>
                                        <th>Expiration</th>
                                        <th>Status</th>
                                        <th style="width: 160px; text-align: center;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($rates)): ?>
                                        <?php foreach ($rates as $rate):
                                            $isExpired = $rate['expiration_date'] && strtotime($rate['expiration_date']) < strtotime(date('Y-m-d'));
                                            $isCurrentlyInactive = $rate['inactive'] || $isExpired;
                                            $isDefault = (int)($plan['default_rate_id'] ?? 0) === (int)$rate['id'];
                                            $memberCount = (int)$rate['member_count'];
                                        ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo $rate['created_at'] ? date('M j, Y', strtotime($rate['created_at'])) : 'Unknown'; ?></strong>
                                                    <?php if ($isDefault): ?>
                                                        <span class="badge badge-active" style="font-size: 0.65rem; padding: 2px 6px; margin-left: 6px;">Default</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td style="font-size: 0.85rem;">$<?php echo number_format($rate['price'], 2); ?></td>
                                                <td style="font-size: 0.85rem; text-transform: capitalize;"><?php echo e($rate['billing_frequency']); ?></td>
                                                <td style="font-size: 0.85rem;"><?php echo $memberCount; ?></td>
                                                <td style="font-size: 0.85rem; color: var(--color-text-muted);">
                                                    <?php echo $rate['expiration_date'] ? date('M j, Y', strtotime($rate['expiration_date'])) : 'Never'; ?>
                                                </td>
                                                <td style="font-size: 0.85rem;">
                                                    <?php if ($isCurrentlyInactive): ?>
                                                        <span class="badge badge-expired" style="font-size: 0.7rem; padding: 2px 6px;">
                                                            <?php echo $isExpired ? 'Expired' : 'Inactive'; ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="badge badge-active" style="font-size: 0.7rem; padding: 2px 6px;">Active</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td style="text-align: center; white-space: nowrap;">
                                                    <a href="rates.php?plan_id=<?php echo $planId; ?>&action=edit&id=<?php echo (int)$rate['id']; ?>" class="btn btn-warning btn-sm" style="display: inline-block; padding: 4px 8px; font-size: 0.8rem;">Edit</a>
                                                    <?php if ($memberCount > 0): ?>
                                                        <?php if (!$isDefault): ?>
                                                            <form action="rates.php?plan_id=<?php echo $planId; ?>" method="POST" class="inline-form" style="display: inline;"
                                                                  data-confirm="<?php echo e("Move all {$memberCount} member(s) on this rate to the plan's current default rate, and retire this rate? Active members will be emailed about the change; expired members will not. This cannot be undone."); ?>">
                                                                <input type="hidden" name="csrf_token" value="<?php echo e(get_csrf_token()); ?>">
                                                                <input type="hidden" name="rate_id" value="<?php echo (int)$rate['id']; ?>">
                                                                <button type="submit" name="retire_rate" class="btn btn-danger btn-sm" style="padding: 4px 8px; font-size: 0.8rem;">End Rate</button>
                                                            </form>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <form action="rates.php?plan_id=<?php echo $planId; ?>" method="POST" class="inline-form" style="display: inline;"
                                                              data-confirm="<?php echo e(($isDefault ? "Delete this rate and revert the plan's default back to its previous rate?" : "Delete this unused rate?") . " This cannot be undone."); ?>">
                                                            <input type="hidden" name="csrf_token" value="<?php echo e(get_csrf_token()); ?>">
                                                            <input type="hidden" name="rate_id" value="<?php echo (int)$rate['id']; ?>">
                                                            <button type="submit" name="delete_rate" class="btn btn-danger btn-sm" style="padding: 4px 8px; font-size: 0.8rem;">Delete</button>
                                                        </form>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="7" class="text-center text-muted">No payment rates defined.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>
            </div>
        </main>

        <?php include __DIR__ . '/../partials/footer.php'; ?>

        <!-- Add/Edit Rate Modal -->
        <div id="rate-modal" class="modal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.6); backdrop-filter: blur(5px);">
            <div class="modal-content glass-panel" style="background: rgba(30, 30, 40, 0.95); margin: 5% auto; padding: 25px; border: 1px solid rgba(255, 255, 255, 0.1); width: 90%; max-width: 480px; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.5);">
                <div class="modal-header" style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid rgba(255, 255, 255, 0.1); padding-bottom: 15px; margin-bottom: 20px;">
                    <h3 style="margin: 0; color: #fff; font-size: 1.2rem;"><?php echo $isEditing ? 'Edit Rate' : 'Add Rate'; ?></h3>
                    <span class="close" onclick="closeRateModal()" style="color: rgba(255,255,255,0.6); font-size: 28px; font-weight: bold; cursor: pointer; transition: color 0.2s;">&times;</span>
                </div>

                <?php if ($pendingConfirm): ?>
                    <p class="description-text" style="margin-bottom: 15px;">
                        Review the price change above, then click "Confirm Price Change" to proceed, or Cancel to leave this rate as-is.
                    </p>
                <?php endif; ?>

                <form action="rates.php?plan_id=<?php echo $planId; ?>" method="POST" class="auth-form">
                    <input type="hidden" name="csrf_token" value="<?php echo e(get_csrf_token()); ?>">
                    <input type="hidden" name="rate_id" value="<?php echo $formValues ? (int)$formValues['id'] : ''; ?>">
                    <?php if ($pendingConfirm): ?>
                        <input type="hidden" name="confirm_price_change" value="1">
                    <?php endif; ?>

                    <div class="form-group">
                        <label for="name">Rate Name</label>
                        <input type="text" id="name" name="name" required placeholder="e.g. Standard Monthly" value="<?php echo $formValues ? e($formValues['name']) : ''; ?>">
                    </div>

                    <div class="form-group">
                        <label for="price">Price (USD)</label>
                        <input type="number" id="price" name="price" required min="0" step="0.01" placeholder="e.g. 15.00" value="<?php echo $formValues ? (float)$formValues['price'] : ''; ?>">
                    </div>

                    <div class="form-group">
                        <label for="billing_frequency">Billing Frequency</label>
                        <select id="billing_frequency" name="billing_frequency" required>
                            <option value="daily" <?php echo ($formValues && $formValues['billing_frequency'] === 'daily') ? 'selected' : ''; ?>>Daily</option>
                            <option value="monthly" <?php echo (!$formValues || $formValues['billing_frequency'] === 'monthly') ? 'selected' : ''; ?>>Monthly</option>
                            <option value="annual" <?php echo ($formValues && $formValues['billing_frequency'] === 'annual') ? 'selected' : ''; ?>>Annual</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="expiration_date">Expiration Date (Optional)</label>
                        <input type="date" id="expiration_date" name="expiration_date" value="<?php echo $formValues ? e($formValues['expiration_date']) : ''; ?>">
                    </div>

                    <div class="form-group checkbox-group" style="margin-top: 15px; margin-bottom: 15px;">
                        <input type="checkbox" id="inactive" name="inactive" value="1" <?php echo ($formValues && $formValues['inactive']) ? 'checked' : ''; ?>>
                        <label for="inactive" style="color: #fff;">Mark as Inactive</label>
                    </div>

                    <div class="form-actions">
                        <button type="submit" name="save_rate" class="btn <?php echo $pendingConfirm ? 'btn-danger' : 'btn-primary'; ?>">
                            <?php echo $pendingConfirm ? 'Confirm Price Change' : ($isEditing ? 'Save Changes' : 'Create Rate'); ?>
                        </button>
                        <?php if ($isEditing): ?>
                            <a href="rates.php?plan_id=<?php echo $planId; ?>" class="btn btn-secondary" style="text-decoration: none; display: flex; align-items: center; justify-content: center; height: 38px; padding: 0 15px;">Cancel</a>
                        <?php else: ?>
                            <button type="button" class="btn btn-secondary" onclick="closeRateModal()">Cancel</button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function openRateModal() {
            document.getElementById('rate-modal').style.display = 'block';
        }
        function closeRateModal() {
            document.getElementById('rate-modal').style.display = 'none';
        }
        window.addEventListener('click', function(event) {
            const modal = document.getElementById('rate-modal');
            if (event.target === modal) {
                modal.style.display = 'none';
            }
        });
        <?php if ($autoOpenRateModal): ?>
        document.addEventListener('DOMContentLoaded', openRateModal);
        <?php endif; ?>
    </script>
</body>
</html>
