<?php
/**
 * Admin Memberships Management
 * Manage membership levels, descriptions, pricing, and billing intervals (monthly/yearly).
 * Synchronizes local plans and CiviCRM membership types.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config/bootstrap.php';

use App\Auth;
use App\BillingHelper;

Auth::requireAdmin();

$errorMsg = null;
$successMsg = null;
$plans = [];
$editPlan = null;

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

        try {
            $data = [
                'id' => $id,
                'name' => $name,
                'description' => $description,
                'price' => $price,
                'duration_interval' => $durationInterval,
                'duration_unit' => $durationUnit
            ];

            BillingHelper::savePlan($data);
            $successMsg = $id ? "Membership level updated successfully!" : "New membership level added successfully!";
            
            // Redirect to clean POST state
            header("Location: memberships.php?success=" . urlencode($successMsg));
            exit;
        } catch (Exception $e) {
            $errorMsg = "Failed to save plan: " . $e->getMessage();
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Memberships - Admin Panel</title>
    <link rel="stylesheet" href="../assets/css/style.css">
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
    </style>
</head>
<body>
    <div class="app-container">
        <!-- Navigation Bar -->
        <header class="navbar">
            <div class="logo">TGG Members</div>
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
                        <li><a href="dashboard.php">Control Hub</a></li>
                        <li><a href="scheduler.php">Event Scheduler</a></li>
                        <li><a href="import.php">CiviCRM Importer</a></li>
                        <li><a href="memberships.php" class="active">Memberships</a></li>
                        <li><a href="reports.php">Reports & Analytics</a></li>
                    </ul>
                </aside>

                <!-- Work Area -->
                <section class="admin-workspace glass-panel">
                    <h2>Membership Levels & Pricing</h2>
                    <p class="description-text">
                        Configure pricing plans, descriptions, and durations for member subscriptions. Changes are saved locally and synced with WordPress CiviCRM tables.
                    </p>

                    <?php if ($errorMsg): ?>
                        <div class="alert alert-danger"><?php echo e($errorMsg); ?></div>
                    <?php endif; ?>

                    <?php if ($successMsg): ?>
                        <div class="alert alert-success"><?php echo e($successMsg); ?></div>
                    <?php endif; ?>

                    <div class="membership-grid mt-20">
                        
                        <!-- List of Plans -->
                        <div class="plans-list-container">
                            <h3>Active Membership Tiers</h3>
                            <table class="reports-table">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Price</th>
                                        <th>Billing Cycle</th>
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
                                                <td>$<?php echo number_format($plan['price'], 2); ?></td>
                                                <td>
                                                    Every <?php echo (int)$plan['duration_interval']; ?> <?php echo e(ucfirst($plan['duration_unit'])); ?>(s)
                                                </td>
                                                <td style="text-align: center;">
                                                    <a href="memberships.php?action=edit&id=<?php echo (int)$plan['id']; ?>" class="btn btn-warning btn-sm" style="display: inline-block; padding: 4px 8px; font-size: 0.8rem;">Edit</a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="4" class="text-center text-muted">No membership levels defined.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Add/Edit Form -->
                        <div class="plan-form-container glass-panel" style="padding: 20px;">
                            <h3><?php echo $editPlan ? 'Edit Membership Level' : 'Add Membership Level'; ?></h3>
                            
                            <form action="memberships.php" method="POST" class="auth-form mt-10">
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
                                            <option value="month" <?php echo ($editPlan && $editPlan['duration_unit'] === 'month') ? 'selected' : ''; ?>>Month(s)</option>
                                            <option value="year" <?php echo (!$editPlan || $editPlan['duration_unit'] === 'year') ? 'selected' : 'selected'; ?>>Year(s)</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="form-actions">
                                    <button type="submit" name="save_plan" class="btn btn-primary"><?php echo $editPlan ? 'Save Changes' : 'Create Level'; ?></button>
                                    <?php if ($editPlan): ?>
                                        <a href="memberships.php" class="btn btn-secondary" style="text-decoration: none; display: flex; align-items: center; justify-content: center; height: 38px; padding: 0 15px;">Cancel</a>
                                    <?php endif; ?>
                                </div>
                            </form>
                        </div>
                    </div>
                </section>
            </div>
        </main>

        <footer class="app-footer">
            <p>&copy; <?php echo date('Y'); ?> TGG Club Membership System. Secure Public Portal.</p>
        </footer>
    </div>
</body>
</html>
