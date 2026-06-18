<?php
/**
 * Member Renewal Page
 * Displays current membership details and directs members to Stripe for renewal payment.
 */
require_once dirname(dirname(__DIR__)) . '/config/bootstrap.php';

use App\Database;
use App\CiviCRMImporter;
use App\StripeHelper;
use App\Auth;
use App\BillingHelper;

$errorMsg = null;
$successMsg = null;
$membership = null;
$tiers = [];

// 1. Handle Successful Redirect from Stripe (Run BEFORE Auth check as fallback)
if (isset($_GET['status']) && $_GET['status'] === 'success' && isset($_GET['session_id'])) {
    $sessionId = $_GET['session_id'];
    try {
        $session = StripeHelper::retrieveCheckoutSession($sessionId);
        if ($session['payment_status'] === 'paid') {
            BillingHelper::processCheckoutSession($session);
            
            // If the user's session was lost (e.g. domain/protocol mismatch), redirect to login page with success msg
            if (!Auth::check()) {
                redirect("index.php?renew_success=1&amount=" . ($session['amount_total'] / 100));
            }
            
            $successMsg = "Your renewal payment of $" . number_format($session['amount_total'] / 100, 2) . " was processed successfully! Your membership status is updated.";
        } else {
            $errorMsg = "Payment verification is pending.";
        }
    } catch (Exception $e) {
        $errorMsg = safe_err("Verification error: ", $e);
    }
}

// 2. Handle Cancelled Redirect from Stripe
if (isset($_GET['status']) && $_GET['status'] === 'cancelled') {
    $errorMsg = "Renewal process cancelled. No changes have been made.";
}

// 3. Auth Gate & Load Details
Auth::requireAuth();
$contactId = $_SESSION['user']['contact_id'];
$isAdmin = has_role('admin');
if ($isAdmin && isset($_GET['contact_id'])) {
    $contactId = (int)$_GET['contact_id'];
}

$contactName = null;
try {
    $appDb = Database::getAppConnection();
    $nameStmt = $appDb->prepare("SELECT display_name FROM tgg_contacts WHERE id = :id LIMIT 1");
    $nameStmt->execute(['id' => $contactId]);
    $contactName = $nameStmt->fetchColumn() ?: "Member #{$contactId}";

    $membership = BillingHelper::getMemberSubscriptionDetails($contactId);
    if (!$membership) {
        $membership = CiviCRMImporter::getMemberMembershipDetails($contactId);
    }
    // The Trial membership is a one-time, non-renewable offer, so it's never a valid renewal choice.
    $tiers = array_values(array_filter(BillingHelper::getSubscriptionPlans(true), function ($tier) {
        return !BillingHelper::isTrialPlan($tier);
    }));
} catch (Exception $e) {
    $errorMsg = safe_err("Unable to fetch membership details: ", $e);
}

// 4. Handle Renewal Request Form POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_GET['status'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errorMsg = "Invalid security token. Please try again.";
    } else {
        $paymentFlow = $_POST['payment_flow'] ?? 'stripe';

        if ($paymentFlow === 'offline' && has_role('admin')) {
            $paymentMethod = $_POST['payment_method'] ?? '';
            $durationMode = $_POST['duration_mode'] ?? 'standard';
            $levelChangeMode = $_POST['level_change_mode'] ?? 'extend_current';
            $customExpiryDate = $_POST['custom_expiry_date'] ?? null;
            $amountReceivedInput = $_POST['amount_received'] ?? '';
            $customAmount = ($amountReceivedInput !== '') ? (float)$amountReceivedInput : null;
            
            // For offline renewals, if durationMode is not 'standard', we use Plan 1 as a fallback for tierName retrieval
            // but the resolved final plan will remain the current one.
            $tierId = ($durationMode === 'standard') ? (int)($_POST['tier_id'] ?? 0) : 1; 

            if (empty($tierId)) {
                $errorMsg = "Please select a membership level.";
            } else {
                try {
                    // Find chosen tier info
                    $tierIndex = array_search($tierId, array_column($tiers, 'id'));
                    if ($tierIndex === false) {
                        throw new Exception("Invalid membership tier selected.");
                    }
                    $tier = $tiers[$tierIndex];
                    $tierName = $tier['name'];

                    BillingHelper::processOfflineRenewal(
                        $contactId,
                        $tierId,
                        $paymentMethod,
                        'renew',
                        $levelChangeMode,
                        $durationMode,
                        $customExpiryDate,
                        $customAmount
                    );

                    // Load updated membership details
                    $updatedMembership = BillingHelper::getMemberSubscriptionDetails($contactId);
                    if (!$updatedMembership) {
                        $updatedMembership = CiviCRMImporter::getMemberMembershipDetails($contactId);
                    }

                    $extendedLevelName = ($updatedMembership && isset($updatedMembership['membership_name'])) ? $updatedMembership['membership_name'] : $tierName;

                    if ($durationMode === 'custom_date') {
                        $successMsg = "Offline renewal processed successfully! Expiration date set to " . date('F j, Y', strtotime($customExpiryDate)) . " via " . htmlspecialchars(ucwords($paymentMethod)) . ".";
                    } elseif ($durationMode === '1_month') {
                        $successMsg = "Offline renewal processed successfully! Membership extended by 1 month via " . htmlspecialchars(ucwords($paymentMethod)) . ".";
                    } elseif ($durationMode === '1_year') {
                        $successMsg = "Offline renewal processed successfully! Membership extended by 1 year via " . htmlspecialchars(ucwords($paymentMethod)) . ".";
                    } else {
                        if ($levelChangeMode === 'extend_current' && $membership) {
                            $successMsg = "Offline renewal processed successfully! Membership level " . htmlspecialchars($extendedLevelName) . " extended via " . htmlspecialchars(ucwords($paymentMethod)) . " (added time based on " . htmlspecialchars($tierName) . ").";
                        } else {
                            $successMsg = "Offline renewal processed successfully! Membership changed to " . htmlspecialchars($extendedLevelName) . " via " . htmlspecialchars(ucwords($paymentMethod)) . ".";
                        }
                    }
                    $successMsg .= ' <a href="profile.php?id=' . $contactId . '" class="btn btn-secondary btn-small" style="display: inline-block; margin-left: 15px; padding: 4px 10px; font-size: 0.8rem; vertical-align: middle; background: rgba(255, 255, 255, 0.15); border: 1px solid rgba(255, 255, 255, 0.25); color: #fff;">Back to Profile</a>';

                    $membership = $updatedMembership;
                } catch (Exception $e) {
                    $errorMsg = safe_err("Failed to process offline renewal: ", $e);
                }
            }
        } else {
            // Stripe payment flow
            $tierId = (int)($_POST['tier_id'] ?? 0);
            if (empty($tierId)) {
                $errorMsg = "Please select a membership level.";
            } else {
                try {
                    $tierIndex = array_search($tierId, array_column($tiers, 'id'));
                    if ($tierIndex === false) {
                        throw new Exception("Invalid membership tier selected.");
                    }
                    $tier = $tiers[$tierIndex];
                    $fee = (float)$tier['price'];
                    $tierName = $tier['name'];
                    $civicrmTypeId = (int)$tier['civicrm_membership_type_id'];

                    // Create Checkout Session
                    $session = StripeHelper::createCheckoutSession($contactId, $tierId, $civicrmTypeId, $tierName, $fee, 'renew');
                    header("Location: " . $session['url']);
                    exit;
                } catch (Exception $e) {
                    $errorMsg = safe_err("Failed to process renewal: ", $e);
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
    <title>Renew Membership - TGG Member Portal</title>
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <link rel="icon" type="image/png" href="favicon.png">
    <link rel="apple-touch-icon" href="favicon.png">
    <link rel="manifest" href="manifest.json">
    <link rel="stylesheet" href="assets/css/style.css<?php echo asset_version('assets/css/style.css'); ?>">
</head>
<body>
    <div class="app-container">
        <?php include __DIR__ . '/partials/navbar.php'; ?>

        <main class="main-content centered-content">
            <div class="auth-panel glass-panel">
                <h2>Membership Renewal</h2>
                <?php if ($contactId !== $_SESSION['user']['contact_id']): ?>
                    <p class="subtitle" style="color: var(--color-primary); font-weight: 600;">Renewing for: <?php echo e($contactName); ?> (ID #<?php echo $contactId; ?>)</p>
                <?php else: ?>
                    <p class="subtitle">Keep your club benefits active by renewing your subscription.</p>
                <?php endif; ?>

                <?php if ($errorMsg): ?>
                    <div class="alert alert-danger"><?php echo e($errorMsg); ?></div>
                <?php endif; ?>

                <?php if ($successMsg): ?>
                    <div class="alert alert-success" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;"><?php echo $successMsg; ?></div>
                <?php endif; ?>

                <!-- Current Membership Status Box -->
                <div class="current-membership-box">
                    <h3>Current Status</h3>
                    <?php if ($membership): ?>
                        <table class="status-table" style="width: 100%; margin-top: 10px; border-collapse: collapse;">
                            <tr>
                                <td style="text-align: right; padding: 6px 15px 6px 0; width: 45%; font-weight: bold; color: var(--color-text-secondary); border: none;">Level:</td>
                                <td style="text-align: left; padding: 6px 0 6px 15px; color: #fff; font-weight: 600; border: none;"><?php echo e($membership['membership_name']); ?></td>
                            </tr>
                            <tr>
                                <td style="text-align: right; padding: 6px 15px 6px 0; width: 45%; font-weight: bold; color: var(--color-text-secondary); border: none;">Status:</td>
                                <td style="text-align: left; padding: 6px 0 6px 15px; border: none;">
                                    <span class="badge badge-status <?php echo $membership['is_active'] ? 'badge-active' : 'badge-expired'; ?>">
                                        <?php echo e($membership['status_label']); ?>
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <td style="text-align: right; padding: 6px 15px 6px 0; width: 45%; font-weight: bold; color: var(--color-text-secondary); border: none;">Join Date:</td>
                                <td style="text-align: left; padding: 6px 0 6px 15px; color: #fff; border: none;"><?php echo date('F j, Y', strtotime($membership['join_date'])); ?></td>
                            </tr>
                            <tr>
                                <td style="text-align: right; padding: 6px 15px 6px 0; width: 45%; font-weight: bold; color: var(--color-text-secondary); border: none;">Current Expires:</td>
                                <td style="text-align: left; padding: 6px 0 6px 15px; border: none;">
                                    <strong class="<?php echo strtotime($membership['end_date']) < time() ? 'text-danger' : ''; ?>" style="color: #fff;">
                                        <?php echo date('F j, Y', strtotime($membership['end_date'])); ?>
                                    </strong>
                                </td>
                            </tr>
                        </table>
                    <?php else: ?>
                        <div class="alert alert-info">
                            No active membership record found. You can choose a tier below to join.
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Renewal Forms -->
                <?php if (has_role('admin')): ?>
                    <div class="renewal-sections-container" style="display: flex; flex-direction: column; gap: 25px; margin-top: 20px;">
                        
                        <!-- SECTION 1: STANDARD RENEWAL (STRIPE) -->
                        <div class="renewal-section-card" style="background: rgba(255, 255, 255, 0.02); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 12px; padding: 20px; text-align: left;">
                            <h3 style="margin-top: 0; margin-bottom: 15px; color: var(--color-primary); display: flex; align-items: center; gap: 8px; font-size: 1.1rem;">
                                <span>💳</span> Standard Renewal via Stripe
                            </h3>
                            <form action="renew.php?contact_id=<?php echo $contactId; ?>" method="POST" class="auth-form">
                                <input type="hidden" name="csrf_token" value="<?php echo e(get_csrf_token()); ?>">
                                <input type="hidden" name="payment_flow" value="stripe">
                                
                                <div class="form-group">
                                    <label for="stripe_tier_id">Select Renewal Level</label>
                                    <select id="stripe_tier_id" name="tier_id" required>
                                        <option value="" disabled selected>-- Select a Level --</option>
                                        <?php foreach ($tiers as $tier): ?>
                                            <option value="<?php echo (int)$tier['id']; ?>" 
                                                <?php echo ($membership && $membership['membership_name'] === $tier['name']) ? 'selected' : ''; ?>>
                                                <?php echo e($tier['name']); ?> - $<?php echo number_format($tier['minimum_fee'], 2); ?> / <?php echo e($tier['duration_interval']); ?> <?php echo e($tier['duration_unit']); ?>(s)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="info-block mt-10" style="margin-bottom: 15px;">
                                    <p><strong>Note:</strong> Pressing "Pay Dues via Stripe" will redirect you to Stripe Checkout to securely process the credit card payment.</p>
                                </div>
                                
                                <button type="submit" class="btn btn-primary btn-block">Pay Dues via Stripe</button>
                            </form>
                        </div>

                        <!-- SECTION 2: OFFLINE PAYMENT (ADMIN DIRECT ENTRY) -->
                        <div class="renewal-section-card" style="background: rgba(255, 255, 255, 0.02); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 12px; padding: 20px; text-align: left;">
                            <h3 style="margin-top: 0; margin-bottom: 15px; color: var(--color-warning); display: flex; align-items: center; gap: 8px; font-size: 1.1rem;">
                                <span>📝</span> Record Offline Payment (Direct Entry)
                            </h3>
                            <form action="renew.php?contact_id=<?php echo $contactId; ?>" method="POST" class="auth-form" onsubmit="return validateOfflineForm(event)">
                                <input type="hidden" name="csrf_token" value="<?php echo e(get_csrf_token()); ?>">
                                <input type="hidden" name="payment_flow" value="offline">
                                
                                <div class="form-group">
                                    <label for="offline_payment_method">Offline Payment Method</label>
                                    <select id="offline_payment_method" name="payment_method" required>
                                        <option value="cash">Cash</option>
                                        <option value="check">Check</option>
                                        <option value="complimentary">Complimentary</option>
                                        <option value="volunteer credit">Volunteer Credit</option>
                                    </select>
                                </div>
                                
                                <div class="form-group mt-15">
                                    <label style="display: block; margin-bottom: 8px; font-weight: bold; font-size: 0.9rem;">Select Duration Option</label>
                                    <div style="display: flex; flex-direction: column; gap: 12px; background: rgba(255, 255, 255, 0.02); border: 1px solid rgba(255, 255, 255, 0.05); border-radius: 8px; padding: 15px;">
                                        
                                        <!-- Option 1: Standard Membership Levels & Periods -->
                                        <div style="display: flex; flex-direction: column; gap: 5px;">
                                            <label style="display: flex; align-items: center; gap: 8px; font-weight: normal; font-size: 0.85rem; cursor: pointer;">
                                                <input type="radio" name="duration_mode" value="standard" checked onchange="toggleDurationOptions()">
                                                <span><strong>Standard Membership Levels & Periods</strong></span>
                                            </label>
                                            <div id="standard_tier_select_wrapper" style="margin-left: 24px; margin-top: 5px;">
                                                <select id="offline_tier_id" name="tier_id" style="width: 100%;">
                                                    <option value="" disabled selected>-- Select a Level --</option>
                                                    <?php foreach ($tiers as $tier): ?>
                                                        <option value="<?php echo (int)$tier['id']; ?>" 
                                                            data-interval="<?php echo (int)$tier['duration_interval']; ?>"
                                                            data-unit="<?php echo e(strtolower($tier['duration_unit'])); ?>"
                                                            data-price="<?php echo (float)$tier['price']; ?>"
                                                            data-name="<?php echo e($tier['name']); ?>"
                                                            <?php echo ($membership && $membership['membership_name'] === $tier['name']) ? 'selected' : ''; ?>>
                                                            <?php echo e($tier['name']); ?> - $<?php echo number_format($tier['minimum_fee'], 2); ?> / <?php echo e($tier['duration_interval']); ?> <?php echo e($tier['duration_unit']); ?>(s)
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                        
                                        <!-- Option 2: Add One Month -->
                                        <label style="display: flex; align-items: center; gap: 8px; font-weight: normal; font-size: 0.85rem; cursor: pointer;">
                                            <input type="radio" name="duration_mode" value="1_month" onchange="toggleDurationOptions()">
                                            <span>Add One Month</span>
                                        </label>
                                        
                                        <!-- Option 3: Add One Year -->
                                        <label style="display: flex; align-items: center; gap: 8px; font-weight: normal; font-size: 0.85rem; cursor: pointer;">
                                            <input type="radio" name="duration_mode" value="1_year" onchange="toggleDurationOptions()">
                                            <span>Add One Year</span>
                                        </label>
                                        
                                        <!-- Option 4: Set Specific Expiration Date -->
                                        <div style="display: flex; flex-direction: column; gap: 5px;">
                                            <label style="display: flex; align-items: center; gap: 8px; font-weight: normal; font-size: 0.85rem; cursor: pointer;">
                                                <input type="radio" name="duration_mode" value="custom_date" onchange="toggleDurationOptions()">
                                                <span>Set Specific Expiration Date</span>
                                            </label>
                                            <div id="custom_date_wrapper" style="margin-left: 24px; margin-top: 5px; display: none;">
                                                <div style="font-size: 0.8rem; color: var(--color-text-secondary); margin-bottom: 5px;">
                                                    Current Expiration: <strong><?php echo $membership ? date('F j, Y', strtotime($membership['end_date'])) : 'None'; ?></strong>
                                                </div>
                                                <input type="date" id="custom_expiry_date" name="custom_expiry_date" style="width: 100%; padding: 8px 12px; background: rgba(0,0,0,0.2); border: 1px solid rgba(255,255,255,0.15); border-radius: 4px; color: #fff;">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="form-group mt-15">
                                    <label for="amount_received">Amount Received ($)</label>
                                    <input type="number" step="0.01" min="0" id="amount_received" name="amount_received" placeholder="Leave empty for level default or $0.00" style="width: 100%;">
                                </div>

                                <?php if ($membership): ?>
                                <div class="form-group mt-15" id="offline_level_change_group">
                                    <label style="display: block; margin-bottom: 5px; font-weight: bold; font-size: 0.9rem;">Level Change Option</label>
                                    <div style="display: flex; flex-direction: column; gap: 8px;">
                                         <label style="display: flex; align-items: flex-start; gap: 8px; font-weight: normal; font-size: 0.85rem; cursor: pointer;">
                                            <input type="radio" name="level_change_mode" value="extend_current" style="margin-top: 3px;" checked>
                                            <span>Add time without changing the current membership level (default)</span>
                                         </label>
                                         <label style="display: flex; align-items: flex-start; gap: 8px; font-weight: normal; font-size: 0.85rem; cursor: pointer;">
                                            <input type="radio" name="level_change_mode" value="change_level" style="margin-top: 3px;">
                                            <span>Change the current membership level to the new one</span>
                                         </label>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <button type="submit" class="btn btn-warning btn-block mt-20">Record Offline Renewal</button>
                            </form>
                        </div>
                    </div>
                    
                    <script>
                        function toggleDurationOptions() {
                            const modes = document.getElementsByName('duration_mode');
                            let selectedMode = 'standard';
                            for (const mode of modes) {
                                if (mode.checked) {
                                    selectedMode = mode.value;
                                    break;
                                }
                            }
                            
                            const tierSelectWrapper = document.getElementById('standard_tier_select_wrapper');
                            const dateWrapper = document.getElementById('custom_date_wrapper');
                            const levelChangeGroup = document.getElementById('offline_level_change_group');
                            const tierSelect = document.getElementById('offline_tier_id');
                            const dateInput = document.getElementById('custom_expiry_date');
                            
                            if (selectedMode === 'standard') {
                                tierSelectWrapper.style.display = 'block';
                                dateWrapper.style.display = 'none';
                                if (levelChangeGroup) levelChangeGroup.style.display = 'block';
                                if (tierSelect) tierSelect.setAttribute('required', 'required');
                                if (dateInput) dateInput.removeAttribute('required');
                            } else if (selectedMode === 'custom_date') {
                                tierSelectWrapper.style.display = 'none';
                                dateWrapper.style.display = 'block';
                                if (levelChangeGroup) levelChangeGroup.style.display = 'none';
                                if (tierSelect) tierSelect.removeAttribute('required');
                                if (dateInput) dateInput.setAttribute('required', 'required');
                            } else {
                                tierSelectWrapper.style.display = 'none';
                                dateWrapper.style.display = 'none';
                                if (levelChangeGroup) levelChangeGroup.style.display = 'none';
                                if (tierSelect) tierSelect.removeAttribute('required');
                                if (dateInput) dateInput.removeAttribute('required');
                            }
                        }
                        
                        function calculateNewExpiration() {
                            const todayStr = "<?php echo date('Y-m-d'); ?>";
                            const currentExpiryStr = "<?php echo $membership ? $membership['end_date'] : ''; ?>";
                            
                            let startDate = new Date(todayStr + 'T00:00:00');
                            if (currentExpiryStr) {
                                const today = new Date(todayStr + 'T00:00:00');
                                const currentExpiry = new Date(currentExpiryStr + 'T00:00:00');
                                if (currentExpiry >= today) {
                                    startDate = new Date(currentExpiry.getTime());
                                    startDate.setDate(startDate.getDate() + 1);
                                }
                            }
                            
                            const modes = document.getElementsByName('duration_mode');
                            let selectedMode = 'standard';
                            for (const mode of modes) {
                                if (mode.checked) {
                                    selectedMode = mode.value;
                                    break;
                                }
                            }
                            
                            let newExpiry = new Date(startDate.getTime());
                            let durationLabel = '';
                            let amountLabel = '';
                            
                            // Amount Received
                            const amountInput = document.getElementById('amount_received').value;
                            
                            if (selectedMode === '1_month') {
                                newExpiry.setMonth(newExpiry.getMonth() + 1);
                                durationLabel = "1 Month";
                                amountLabel = amountInput !== '' ? '$' + parseFloat(amountInput).toFixed(2) : '$0.00';
                            } else if (selectedMode === '1_year') {
                                newExpiry.setFullYear(newExpiry.getFullYear() + 1);
                                durationLabel = "1 Year";
                                amountLabel = amountInput !== '' ? '$' + parseFloat(amountInput).toFixed(2) : '$0.00';
                            } else if (selectedMode === 'custom_date') {
                                const dateInput = document.getElementById('custom_expiry_date').value;
                                if (dateInput) {
                                    newExpiry = new Date(dateInput + 'T00:00:00');
                                }
                                durationLabel = "Custom Expiration Date";
                                amountLabel = amountInput !== '' ? '$' + parseFloat(amountInput).toFixed(2) : '$0.00';
                            } else {
                                // standard plan
                                const tierSelect = document.getElementById('offline_tier_id');
                                const selectedOpt = tierSelect.options[tierSelect.selectedIndex];
                                if (selectedOpt && selectedOpt.value !== "") {
                                    const interval = parseInt(selectedOpt.getAttribute('data-interval'));
                                    const unit = selectedOpt.getAttribute('data-unit');
                                    const price = parseFloat(selectedOpt.getAttribute('data-price'));
                                    const name = selectedOpt.getAttribute('data-name');
                                    
                                    if (unit === 'day') {
                                         // Daily payment should never change the expiration date
                                         if (currentExpiryStr) {
                                             newExpiry = new Date(currentExpiryStr + 'T00:00:00');
                                         } else {
                                             newExpiry = new Date(todayStr + 'T00:00:00');
                                         }
                                     } else if (unit === 'month') {
                                        newExpiry.setMonth(newExpiry.getMonth() + interval);
                                    } else {
                                        newExpiry.setFullYear(newExpiry.getFullYear() + interval);
                                    }
                                    durationLabel = `${name} (${interval} ${unit}(s))`;
                                    amountLabel = amountInput !== '' ? '$' + parseFloat(amountInput).toFixed(2) : '$' + price.toFixed(2);
                                }
                            }
                            
                            return {
                                expiryDate: newExpiry,
                                expiryLabel: newExpiry.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }),
                                duration: durationLabel,
                                amount: amountLabel
                            };
                        }

                        function validateOfflineForm(event) {
                            const modes = document.getElementsByName('duration_mode');
                            let selectedMode = 'standard';
                            for (const mode of modes) {
                                if (mode.checked) {
                                    selectedMode = mode.value;
                                    break;
                                }
                            }
                            
                            // If standard mode, check that a tier is selected
                            if (selectedMode === 'standard') {
                                const tierSelect = document.getElementById('offline_tier_id');
                                if (!tierSelect.value) {
                                    alert("Please select a standard membership level.");
                                    event.preventDefault();
                                    return false;
                                }
                            } else if (selectedMode === 'custom_date') {
                                const dateInput = document.getElementById('custom_expiry_date');
                                if (!dateInput.value) {
                                    alert("Please select a specific expiration date.");
                                    event.preventDefault();
                                    return false;
                                }
                            }
                            
                            // Calculate new expiration details
                            const details = calculateNewExpiration();
                            
                            // Expiry warnings
                            const currentExpiryStr = "<?php echo $membership ? $membership['end_date'] : ''; ?>";
                            let warningPrefix = "";
                            if (selectedMode === 'custom_date' && currentExpiryStr) {
                                const dateInput = document.getElementById('custom_expiry_date').value;
                                if (dateInput) {
                                    const selectedDate = new Date(dateInput + 'T00:00:00');
                                    const currentExpiry = new Date(currentExpiryStr + 'T00:00:00');
                                    if (selectedDate < currentExpiry) {
                                        warningPrefix = "WARNING: The selected expiration date is prior to the current expiration date. This will shorten the member's active membership.\n\n";
                                    }
                                }
                            }
                            
                            // Always prompt for confirmation
                            const message = `${warningPrefix}Are you sure you want to record this offline payment?\n\n` +
                                            `- Selected Duration: ${details.duration}\n` +
                                            `- New Expiration Date: ${details.expiryLabel}\n` +
                                            `- Amount Received: ${details.amount}`;
                                            
                            const proceed = confirm(message);
                            if (!proceed) {
                                event.preventDefault();
                                return false;
                            }
                            
                            return true;
                        }

                        // Initialize on load
                        if (document.getElementsByName('duration_mode').length > 0) {
                            toggleDurationOptions();
                        }
                    </script>
                    
                    <a href="profile.php?id=<?php echo $contactId; ?>" class="btn btn-secondary btn-block mt-15" style="text-align: center; justify-content: center; align-items: center;">Back to Profile</a>

                <?php else: ?>
                    <!-- Standard Renewal Form for regular members -->
                    <form action="renew.php?contact_id=<?php echo $contactId; ?>" method="POST" class="auth-form mt-20">
                        <input type="hidden" name="csrf_token" value="<?php echo e(get_csrf_token()); ?>">
                        <input type="hidden" name="payment_flow" value="stripe">
                        
                        <div class="form-group">
                            <label for="tier_id">Select Renewal Level</label>
                            <select id="tier_id" name="tier_id" required>
                                <option value="" disabled selected>-- Select a Level --</option>
                                <?php foreach ($tiers as $tier): ?>
                                    <option value="<?php echo (int)$tier['id']; ?>" 
                                        <?php echo ($membership && $membership['membership_name'] === $tier['name']) ? 'selected' : ''; ?>>
                                        <?php echo e($tier['name']); ?> - $<?php echo number_format($tier['minimum_fee'], 2); ?> / <?php echo e($tier['duration_interval']); ?> <?php echo e($tier['duration_unit']); ?>(s)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="info-block mt-10">
                            <p><strong>Note:</strong> Pressing "Pay Renewal Dues" will redirect you to Stripe Checkout to securely process the credit card payment.</p>
                        </div>

                        <button type="submit" class="btn btn-primary btn-block">Pay Renewal Dues</button>
                        <a href="profile.php?id=<?php echo $contactId; ?>" class="btn btn-secondary btn-block mt-10" style="text-align: center; justify-content: center; align-items: center;">Back to Profile</a>
                    </form>
                <?php endif; ?>
            </div>
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
