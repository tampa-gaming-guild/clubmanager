<?php
/**
 * Member Renewal Page
 * Displays current membership details and directs members to Stripe for renewal payment.
 */
require_once (function() {
    $dir = dirname(__DIR__);
    if (file_exists($dir . '/.env') && $lines = @file($dir . '/.env')) {
        foreach ($lines as $line) {
            if (preg_match('/^\s*BOOTSTRAP_PATH\s*=\s*["\']?(.*?)["\']?\s*$/', $line, $m)) {
                return $m[1];
            }
        }
    }
    return $dir . '/config/bootstrap.php';
})();

use App\Database;
use App\MembershipCredits;
use App\MembershipService;
use App\StripeHelper;
use App\Auth;
use App\BillingHelper;

$errorMsg = null;
$successMsg = null;
$membership = null;
$tiers = [];
$hasCardOnFile = false;

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
            
            $successMsg = "Your renewal payment of $" . htmlspecialchars(number_format($session['amount_total'] / 100, 2), ENT_QUOTES, 'UTF-8') . " was processed successfully! Your membership status is updated.";
        } else {
            $errorMsg = "Payment verification is pending.";
        }
    } catch (Exception $e) {
        $errorMsg = safe_err("Verification error: ", $e);
    }
}

// 2. Handle Cancelled Redirect from Stripe
if (isset($_GET['status']) && $_GET['status'] === 'cancelled') {
    if (!Auth::check()) {
        // An in-app webview (mobile) has no PHP session at all -- there's
        // nothing to verify/process for a cancellation, so render a minimal
        // standalone page instead of falling through to the auth gate below,
        // mirroring the success block's own no-session fallback. Staying on
        // this same status=cancelled URL (rather than redirecting) is also
        // what the mobile webview watches for to know the flow is done.
        echo '<!DOCTYPE html><html><body><p>Renewal cancelled. No changes were made. You can close this window.</p></body></html>';
        exit;
    }
    $errorMsg = "Renewal process cancelled. No changes have been made.";
}

// 3. Auth Gate & Load Details
Auth::requireAuth();
$contactId = $_SESSION['user']['contact_id'];
$isAdmin = has_permission('edit checkins');
if ($isAdmin && isset($_GET['contact_id'])) {
    $contactId = (int)$_GET['contact_id'];
}
// Card on file / Cash are host-renewing-someone-else options only -- an
// edit-checkins holder renewing their OWN membership (no ?contact_id=
// override, or one that just points back at themselves) still only gets
// Stripe/Credits, same as any other member.
$isSelfRenewal = ((int)$contactId === (int)$_SESSION['user']['contact_id']);

$contactName = null;
$contactEmail = null;
try {
    $appDb = Database::getAppConnection();
    $nameStmt = $appDb->prepare("SELECT display_name, email FROM tgg_contacts WHERE id = :id LIMIT 1");
    $nameStmt->execute(['id' => $contactId]);
    $contactRow = $nameStmt->fetch();
    $contactDisplayName = $contactRow['display_name'] ?? null;
    $contactName = $contactDisplayName ?? "Member #{$contactId}";
    $contactEmail = $contactRow['email'] ?? null;

    $membership = BillingHelper::getMemberSubscriptionDetails($contactId);
    if (!$membership) {
        $membership = MembershipService::getMemberMembershipDetails($contactId);
    }
    // The Trial membership is a one-time, non-renewable offer, so it's never a valid renewal choice.
    $tiers = array_values(array_filter(BillingHelper::getSubscriptionPlans(true), function ($tier) {
        return !BillingHelper::isTrialPlan($tier);
    }));

    // Drives whether the host section offers "Charge Card on File" -- see
    // BillingHelper::processOfflineRenewal()'s 'card_on_file' payment method.
    $billingStmt = $appDb->prepare("SELECT stripe_customer_id, stripe_payment_method_id FROM tgg_subscriptions WHERE contact_id = :id LIMIT 1");
    $billingStmt->execute(['id' => $contactId]);
    $billingRow = $billingStmt->fetch();
    $hasCardOnFile = !empty($billingRow['stripe_customer_id']) && !empty($billingRow['stripe_payment_method_id']);
} catch (Exception $e) {
    $errorMsg = safe_err("Unable to fetch membership details: ", $e);
}

// 4. Handle Renewal Request Form POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_GET['status'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errorMsg = "Invalid security token. Please try again.";
    } else {
        $paymentFlow = $_POST['payment_flow'] ?? 'stripe';

        if (($paymentFlow === 'card_on_file' || $paymentFlow === 'offline_cash') && $isAdmin && !$isSelfRenewal) {
            $paymentMethod = ($paymentFlow === 'card_on_file') ? 'card_on_file' : 'cash';
            $tierId = (int)($_POST['tier_id'] ?? 0);

            if (empty($tierId)) {
                $errorMsg = "Please select a membership level.";
            } else {
                try {
                    $tierIndex = array_search($tierId, array_column($tiers, 'id'));
                    if ($tierIndex === false) {
                        throw new Exception("Invalid membership tier selected.");
                    }
                    $tierName = $tiers[$tierIndex]['name'];

                    // 'change_level' (not 'extend_current') so the selected tier always takes
                    // effect -- same "whatever tier you pick is what you get" behavior as the
                    // Stripe flow below, now that there's no separate duration/level-change UI.
                    BillingHelper::processOfflineRenewal($contactId, $tierId, $paymentMethod, 'renew', 'change_level', 'standard');

                    $membership = BillingHelper::getMemberSubscriptionDetails($contactId);
                    if (!$membership) {
                        $membership = MembershipService::getMemberMembershipDetails($contactId);
                    }
                    $expiresLabel = $membership ? date('F j, Y', strtotime($membership['end_date'])) : '';

                    $methodLabel = ($paymentFlow === 'card_on_file') ? 'card on file' : 'cash';
                    $successMsg = "Renewed to " . htmlspecialchars($tierName) . " via {$methodLabel}, through " . htmlspecialchars($expiresLabel) . ".";
                    $successMsg .= ' <a href="profile.php?id=' . $contactId . '" class="btn btn-secondary btn-small" style="display: inline-block; margin-left: 15px; padding: 4px 10px; font-size: 0.8rem; vertical-align: middle; background: rgba(255, 255, 255, 0.15); border: 1px solid rgba(255, 255, 255, 0.25); color: #fff;">Back to Profile</a>';
                } catch (Exception $e) {
                    $errorMsg = safe_err("Failed to process renewal: ", $e);
                }
            }
        } elseif ($paymentFlow === 'credits') {
            $creditMonths = (int)($_POST['credit_months'] ?? 0);
            if ($creditMonths < 1) {
                $errorMsg = "Please select how many months of Membership Credits to use.";
            } else {
                try {
                    $result = BillingHelper::applyMembershipCreditsToMembership($contactId, $creditMonths);

                    $successMsg = "Membership extended by {$result['months_applied']} month(s) using Membership Credits, through " . date('F j, Y', strtotime($result['end_date'])) . ".";
                    $successMsg .= ' <a href="profile.php?id=' . $contactId . '" class="btn btn-secondary btn-small" style="display: inline-block; margin-left: 15px; padding: 4px 10px; font-size: 0.8rem; vertical-align: middle; background: rgba(255, 255, 255, 0.15); border: 1px solid rgba(255, 255, 255, 0.25); color: #fff;">Back to Profile</a>';

                    $membership = BillingHelper::getMemberSubscriptionDetails($contactId);
                    if (!$membership) {
                        $membership = MembershipService::getMemberMembershipDetails($contactId);
                    }
                } catch (Exception $e) {
                    $errorMsg = safe_err("Failed to apply Membership Credits: ", $e);
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

                    if (BillingHelper::isSessionPlan($tier)) {
                        // Session plans are never charged at join/renewal -- the charge only
                        // happens at check-in -- so this extends the membership immediately
                        // instead of redirecting to Stripe.
                        $activation = BillingHelper::activateSessionMembership($contactId, $tierId, 'renew');
                        $successMsg = "Your " . htmlspecialchars($tier['name']) . " membership has been renewed through " . date('F j, Y', strtotime($activation['end_date'])) . ".";
                        $membership = BillingHelper::getMemberSubscriptionDetails($contactId);
                        if (!$membership) {
                            $membership = MembershipService::getMemberMembershipDetails($contactId);
                        }
                    } else {
                        $fee = (float)$tier['price'];
                        if ($membership && (int)$membership['membership_id'] === (int)$tier['id']) {
                            $fee = (float)$membership['minimum_fee'];
                        }
                        $tierName = $tier['name'];
                        $civicrmTypeId = (int)$tier['civicrm_membership_type_id'];

                        // Create Checkout Session
                        $session = StripeHelper::createCheckoutSession($contactId, $tierId, $civicrmTypeId, $tierName, $fee, 'renew', $contactEmail, $contactDisplayName, 'renew.php');
                        header("Location: " . $session['url']);
                        exit;
                    }
                } catch (Exception $e) {
                    $errorMsg = safe_err("Failed to process renewal: ", $e);
                }
            }
        }
    }
}

$redeemableMonths = 0;
try {
    $redeemableMonths = MembershipCredits::getRedeemableMonths($contactId);
} catch (Exception $e) {
    // Leave at 0 -- the Membership Credits section just won't show.
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
                <?php if ($isAdmin && !$isSelfRenewal): ?>
                    <div class="renewal-sections-container" style="display: flex; flex-direction: column; gap: 25px; margin-top: 20px;">
                        
                        <!-- SECTION 1: RENEW MEMBERSHIP (Card on File / Stripe Checkout / Cash) -->
                        <div class="renewal-section-card" style="background: rgba(255, 255, 255, 0.02); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 12px; padding: 20px; text-align: left;">
                            <h3 style="margin-top: 0; margin-bottom: 15px; color: var(--color-primary); display: flex; align-items: center; gap: 8px; font-size: 1.1rem;">
                                <span>💳</span> Renew Membership
                            </h3>
                            <form action="renew.php?contact_id=<?php echo $contactId; ?>" method="POST" class="auth-form" data-confirm="Process this renewal for the selected level?" style="display: flex; flex-direction: column; gap: 12px;">
                                <input type="hidden" name="csrf_token" value="<?php echo e(get_csrf_token()); ?>">

                                <div class="form-group">
                                    <label for="stripe_tier_id">Select Renewal Level</label>
                                    <select id="stripe_tier_id" name="tier_id" required>
                                        <option value="" disabled selected>-- Select a Level --</option>
                                        <?php foreach ($tiers as $tier):
                                            $dispFee = $tier['minimum_fee'];
                                            $dispInterval = $tier['duration_interval'];
                                            $dispUnit = $tier['duration_unit'];
                                            if ($membership && (int)$membership['membership_id'] === (int)$tier['id']) {
                                                $dispFee = $membership['minimum_fee'];
                                                $dispInterval = $membership['duration_interval'];
                                                $dispUnit = $membership['duration_unit'];
                                            }
                                            $unitText = strtolower($dispUnit);
                                            if ($unitText === 'year') $unitText = 'annual';
                                            elseif ($unitText === 'month') $unitText = 'monthly';
                                            elseif ($unitText === 'day') $unitText = 'daily';
                                        ?>
                                            <option value="<?php echo (int)$tier['id']; ?>"
                                                <?php echo ($membership && $membership['membership_name'] === $tier['name']) ? 'selected' : ''; ?>>
                                                <?php echo e($tier['name']); ?> - $<?php echo number_format($dispFee, 2); ?> / <?php echo e($unitText); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <?php if ($hasCardOnFile): ?>
                                    <button type="submit" name="payment_flow" value="card_on_file" class="btn btn-primary btn-block">Charge Card on File</button>
                                <?php endif; ?>
                                <button type="submit" name="payment_flow" value="stripe" class="btn btn-secondary btn-block">Pay with Card via Checkout</button>
                                <button type="submit" name="payment_flow" value="offline_cash" class="btn btn-warning btn-block">Pay Cash</button>
                            </form>
                        </div>

                        <?php if ($redeemableMonths >= 1): ?>
                        <!-- SECTION 2: USE MEMBERSHIP CREDITS -->
                        <div class="renewal-section-card" style="background: rgba(255, 255, 255, 0.02); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 12px; padding: 20px; text-align: left;">
                            <h3 style="margin-top: 0; margin-bottom: 15px; color: var(--color-success); display: flex; align-items: center; gap: 8px; font-size: 1.1rem;">
                                <span>🏅</span> Use Membership Credits
                            </h3>
                            <form action="renew.php?contact_id=<?php echo $contactId; ?>" method="POST" class="auth-form" data-confirm="Use Membership Credits to extend this membership? This does not charge a card.">
                                <input type="hidden" name="csrf_token" value="<?php echo e(get_csrf_token()); ?>">
                                <input type="hidden" name="payment_flow" value="credits">

                                <div class="form-group">
                                    <label for="credit_months">Months to Redeem (<?php echo $redeemableMonths; ?> available)</label>
                                    <select id="credit_months" name="credit_months" required>
                                        <?php for ($m = 1; $m <= $redeemableMonths; $m++): ?>
                                            <option value="<?php echo $m; ?>" <?php echo $m === $redeemableMonths ? 'selected' : ''; ?>><?php echo $m; ?> month<?php echo $m > 1 ? 's' : ''; ?></option>
                                        <?php endfor; ?>
                                    </select>
                                </div>

                                <div class="info-block mt-10" style="margin-bottom: 15px;">
                                    <p><strong>Note:</strong> Redeeming Membership Credits extends the membership for free -- no card is charged.</p>
                                </div>

                                <button type="submit" class="btn btn-success btn-block">Use Membership Credits</button>
                            </form>
                        </div>
                        <?php endif; ?>
                    </div>

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
                                <?php foreach ($tiers as $tier): 
                                    $dispFee = $tier['minimum_fee'];
                                    $dispInterval = $tier['duration_interval'];
                                    $dispUnit = $tier['duration_unit'];
                                    if ($membership && (int)$membership['membership_id'] === (int)$tier['id']) {
                                        $dispFee = $membership['minimum_fee'];
                                        $dispInterval = $membership['duration_interval'];
                                        $dispUnit = $membership['duration_unit'];
                                    }
                                    $unitText = strtolower($dispUnit);
                                    if ($unitText === 'year') $unitText = 'annual';
                                    elseif ($unitText === 'month') $unitText = 'monthly';
                                    elseif ($unitText === 'day') $unitText = 'daily';
                                ?>
                                    <option value="<?php echo (int)$tier['id']; ?>" 
                                        <?php echo ($membership && $membership['membership_name'] === $tier['name']) ? 'selected' : ''; ?>>
                                        <?php echo e($tier['name']); ?> - $<?php echo number_format($dispFee, 2); ?> / <?php echo e($unitText); ?>
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

                    <?php if ($redeemableMonths >= 1): ?>
                    <div class="renewal-section-card mt-20" style="background: rgba(255, 255, 255, 0.02); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 12px; padding: 20px; text-align: left;">
                        <h3 style="margin-top: 0; margin-bottom: 15px; color: var(--color-success); display: flex; align-items: center; gap: 8px; font-size: 1.1rem;">
                            <span>🏅</span> Use Membership Credits
                        </h3>
                        <form action="renew.php?contact_id=<?php echo $contactId; ?>" method="POST" class="auth-form" data-confirm="Use Membership Credits to extend this membership? This does not charge a card.">
                            <input type="hidden" name="csrf_token" value="<?php echo e(get_csrf_token()); ?>">
                            <input type="hidden" name="payment_flow" value="credits">

                            <div class="form-group">
                                <label for="credit_months_self">Months to Redeem (<?php echo $redeemableMonths; ?> available)</label>
                                <select id="credit_months_self" name="credit_months" required>
                                    <?php for ($m = 1; $m <= $redeemableMonths; $m++): ?>
                                        <option value="<?php echo $m; ?>" <?php echo $m === $redeemableMonths ? 'selected' : ''; ?>><?php echo $m; ?> month<?php echo $m > 1 ? 's' : ''; ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>

                            <div class="info-block mt-10" style="margin-bottom: 15px;">
                                <p><strong>Note:</strong> Redeeming Membership Credits extends the membership for free -- no card is charged.</p>
                            </div>

                            <button type="submit" class="btn btn-success btn-block">Use Membership Credits</button>
                        </form>
                    </div>
                    <?php endif; ?>
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
