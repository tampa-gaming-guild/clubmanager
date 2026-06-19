<?php
/**
 * Admin Rates Management
 * Manage multiple payment rates for membership plans.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config/bootstrap.php';

use App\Auth;
use App\Database;

Auth::requireAdmin();

$errorMsg = null;
$successMsg = null;
$rates = [];
$editRate = null;

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
    die("Database Connection Error: " . $e->getMessage());
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
                // Update existing rate
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
            } else {
                // Insert new rate
                $insertRate = $appDb->prepare("
                    INSERT INTO tgg_subscription_rates (plan_id, name, price, billing_frequency, inactive, expiration_date) 
                    VALUES (:plan_id, :name, :price, :billing_frequency, :inactive, :expiration_date)
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
            }
            
            header("Location: rates.php?plan_id=" . $planId . "&success=" . urlencode($successMsg));
            exit;
        } catch (Exception $e) {
            $errorMsg = safe_err("Failed to save rate: ", $e);
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

// Fetch all rates for this plan
try {
    $ratesStmt = $appDb->prepare("SELECT * FROM tgg_subscription_rates WHERE plan_id = :plan_id ORDER BY price ASC");
    $ratesStmt->execute(['plan_id' => $planId]);
    $rates = $ratesStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $rates = [];
}
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
        .membership-grid {
            display: grid;
            grid-template-columns: 1.5fr 1fr;
            gap: 20px;
        }
        @media (max-width: 900px) {
            .membership-grid {
                grid-template-columns: 1fr;
            }
        }
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

                    <div class="membership-grid mt-20">
                        
                        <!-- List of Rates -->
                        <div class="plans-list-container">
                            <h3>Rates Configured</h3>
                            <table class="reports-table">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Price</th>
                                        <th>Frequency</th>
                                        <th>Expiration</th>
                                        <th>Status</th>
                                        <th style="width: 80px; text-align: center;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($rates)): ?>
                                        <?php foreach ($rates as $rate): 
                                            $isExpired = $rate['expiration_date'] && strtotime($rate['expiration_date']) < strtotime(date('Y-m-d'));
                                            $isCurrentlyInactive = $rate['inactive'] || $isExpired;
                                        ?>
                                            <tr>
                                                <td><strong><?php echo e($rate['name']); ?></strong></td>
                                                <td style="font-size: 0.85rem;">$<?php echo number_format($rate['price'], 2); ?></td>
                                                <td style="font-size: 0.85rem; text-transform: capitalize;"><?php echo e($rate['billing_frequency']); ?></td>
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
                                                <td style="text-align: center;">
                                                    <a href="rates.php?plan_id=<?php echo $planId; ?>&action=edit&id=<?php echo (int)$rate['id']; ?>" class="btn btn-warning btn-sm" style="display: inline-block; padding: 4px 8px; font-size: 0.8rem;">Edit</a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="6" class="text-center text-muted">No payment rates defined.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Add/Edit Form -->
                        <div class="plan-form-container glass-panel" style="padding: 20px;">
                            <h3><?php echo $editRate ? 'Edit Rate' : 'Add Rate'; ?></h3>
                            
                            <form action="rates.php?plan_id=<?php echo $planId; ?>" method="POST" class="auth-form mt-10">
                                <input type="hidden" name="csrf_token" value="<?php echo e(get_csrf_token()); ?>">
                                <input type="hidden" name="rate_id" value="<?php echo $editRate ? (int)$editRate['id'] : ''; ?>">
                                
                                <div class="form-group">
                                    <label for="name">Rate Name</label>
                                    <input type="text" id="name" name="name" required placeholder="e.g. Standard Monthly" value="<?php echo $editRate ? e($editRate['name']) : ''; ?>">
                                </div>

                                <div class="form-group">
                                    <label for="price">Price (USD)</label>
                                    <input type="number" id="price" name="price" required min="0" step="0.01" placeholder="e.g. 15.00" value="<?php echo $editRate ? (float)$editRate['price'] : ''; ?>">
                                </div>

                                <div class="form-group">
                                    <label for="billing_frequency">Billing Frequency</label>
                                    <select id="billing_frequency" name="billing_frequency" required>
                                        <option value="daily" <?php echo ($editRate && $editRate['billing_frequency'] === 'daily') ? 'selected' : ''; ?>>Daily</option>
                                        <option value="monthly" <?php echo (!$editRate || $editRate['billing_frequency'] === 'monthly') ? 'selected' : ''; ?>>Monthly</option>
                                        <option value="annual" <?php echo ($editRate && $editRate['billing_frequency'] === 'annual') ? 'selected' : ''; ?>>Annual</option>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label for="expiration_date">Expiration Date (Optional)</label>
                                    <input type="date" id="expiration_date" name="expiration_date" value="<?php echo $editRate ? e($editRate['expiration_date']) : ''; ?>">
                                </div>
                                
                                <div class="form-group checkbox-group" style="margin-top: 15px; margin-bottom: 15px;">
                                    <input type="checkbox" id="inactive" name="inactive" value="1" <?php echo ($editRate && $editRate['inactive']) ? 'checked' : ''; ?>>
                                    <label for="inactive" style="color: #fff;">Mark as Inactive</label>
                                </div>

                                <div class="form-actions">
                                    <button type="submit" name="save_rate" class="btn btn-primary"><?php echo $editRate ? 'Save Changes' : 'Create Rate'; ?></button>
                                    <?php if ($editRate): ?>
                                        <a href="rates.php?plan_id=<?php echo $planId; ?>" class="btn btn-secondary" style="text-decoration: none; display: flex; align-items: center; justify-content: center; height: 38px; padding: 0 15px;">Cancel</a>
                                    <?php endif; ?>
                                </div>
                            </form>
                        </div>
                    </div>
                </section>
            </div>
        </main>

        <?php include __DIR__ . '/../partials/footer.php'; ?>
    </div>
</body>
</html>
