<?php
/**
 * Entrance Payment Page
 * Reached from checkin.php / host_checkin.php when a member owes a payment before their
 * check-in can complete: an Associate's per-visit entrance fee, or an expired member's
 * renewal. Offers Card (Stripe) or Cash; cash never checks the member in immediately --
 * that only happens once a host approves the pending request in person.
 */
require_once dirname(dirname(__DIR__)) . '/config/bootstrap.php';

use App\Database;
use App\CiviCRMImporter;
use App\StripeHelper;
use App\BillingHelper;

$validReturns = ['checkin.php', 'host_checkin.php'];
$validReasons = ['entrance_fee', 'renewal'];

function sanitize_return(string $value): string {
    global $validReturns;
    return in_array($value, $validReturns, true) ? $value : 'checkin.php';
}

function sanitize_reason(string $value): string {
    global $validReasons;
    return in_array($value, $validReasons, true) ? $value : 'entrance_fee';
}

/**
 * Load the contact, their current membership, and the underlying plan row needed for
 * Stripe (civicrm_membership_type_id). Returns null if the contact can't be resolved.
 */
function load_payment_context(int $contactId): ?array {
    $appDb = Database::getAppConnection();

    $contactStmt = $appDb->prepare("SELECT id, display_name, email FROM tgg_contacts WHERE id = :id AND is_deleted = 0 LIMIT 1");
    $contactStmt->execute(['id' => $contactId]);
    $contact = $contactStmt->fetch();
    if (!$contact) {
        return null;
    }

    $membership = CiviCRMImporter::getMemberMembershipDetails($contactId);
    if (!$membership) {
        return null;
    }

    $planId = (int)$membership['membership_id'];
    $planStmt = $appDb->prepare("SELECT civicrm_membership_type_id FROM tgg_subscription_plans WHERE id = :id LIMIT 1");
    $planStmt->execute(['id' => $planId]);
    $civicrmTypeId = (int)$planStmt->fetchColumn();

    return [
        'contact' => $contact,
        'plan_id' => $planId,
        'civicrm_type_id' => $civicrmTypeId,
        'amount' => (float)$membership['price'],
        'membership_name' => $membership['membership_name'],
    ];
}

/**
 * Load payment context for a member choosing a specific (non-Trial) membership level.
 * Used when the member's lapsed membership was the one-time Trial plan, which can't be
 * renewed as itself -- they must pick a real level instead. Returns null if the contact
 * or tier can't be resolved, or if the chosen tier is itself the Trial plan.
 */
function load_tier_payment_context(int $contactId, int $tierId): ?array {
    $appDb = Database::getAppConnection();

    $contactStmt = $appDb->prepare("SELECT id, display_name, email FROM tgg_contacts WHERE id = :id AND is_deleted = 0 LIMIT 1");
    $contactStmt->execute(['id' => $contactId]);
    $contact = $contactStmt->fetch();
    if (!$contact) {
        return null;
    }

    $planStmt = $appDb->prepare("SELECT id, name, price, civicrm_membership_type_id FROM tgg_subscription_plans WHERE id = :id AND active = 'active' LIMIT 1");
    $planStmt->execute(['id' => $tierId]);
    $plan = $planStmt->fetch();
    if (!$plan || BillingHelper::isTrialPlan($plan)) {
        return null;
    }

    return [
        'contact' => $contact,
        'plan_id' => (int)$plan['id'],
        'civicrm_type_id' => (int)$plan['civicrm_membership_type_id'],
        'amount' => (float)$plan['price'],
        'membership_name' => $plan['name'],
    ];
}

/**
 * Non-trial active plans a member may renew into, cheapest first -- the Trial plan is a
 * one-time offer and must never appear as a renewal choice.
 */
function get_renewable_tiers(): array {
    return array_values(array_filter(BillingHelper::getSubscriptionPlans(true), function ($tier) {
        return !BillingHelper::isTrialPlan($tier);
    }));
}

$errorMsg = null;
$cashPendingMsg = null;
$successDetails = null;

$contactId = (int)($_GET['contact_id'] ?? $_POST['contact_id'] ?? 0);
$reason = sanitize_reason($_GET['reason'] ?? $_POST['reason'] ?? '');
$returnPage = sanitize_return($_GET['return'] ?? $_POST['return'] ?? '');
$status = $_GET['status'] ?? null;

// Basic Origin Validation, same approach as checkin.php -- this page can be reached by
// an unauthenticated member at the self-service kiosk, so there's no session-bound CSRF
// token to rely on for the POST below.
function origin_is_valid(): bool {
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if (empty($referer)) {
        return true;
    }
    $refererHost = explode(':', parse_url($referer, PHP_URL_HOST) ?? '')[0];
    $normalizedHost = explode(':', $host)[0];
    return $refererHost === $normalizedHost;
}

if ($contactId <= 0) {
    $errorMsg = "Invalid request: missing member.";
} elseif ($status === 'success' && isset($_GET['session_id'])) {
    try {
        $session = StripeHelper::retrieveCheckoutSession($_GET['session_id']);
        if (($session['payment_status'] ?? '') !== 'paid') {
            $errorMsg = "Payment verification is pending. Please see the Host if this persists.";
        } else {
            BillingHelper::processCheckoutSession($session);

            $appDb = Database::getAppConnection();
            $dupCheck = $appDb->prepare("SELECT COUNT(*) FROM tgg_checkins WHERE contact_id = :contact_id AND DATE(checked_in_at) = CURDATE()");
            $dupCheck->execute(['contact_id' => $contactId]);
            if ((int)$dupCheck->fetchColumn() === 0) {
                $notes = ($reason === 'entrance_fee') ? 'Entrance fee paid by card' : 'Renewed and checked in by card';
                $insertCheckin = $appDb->prepare("INSERT INTO tgg_checkins (contact_id, checked_in_at, notes) VALUES (:contact_id, NOW(), :notes)");
                $insertCheckin->execute(['contact_id' => $contactId, 'notes' => $notes]);
            }

            $nameStmt = $appDb->prepare("SELECT display_name FROM tgg_contacts WHERE id = :id LIMIT 1");
            $nameStmt->execute(['id' => $contactId]);
            $successDetails = [
                'name' => $nameStmt->fetchColumn() ?: "Member #{$contactId}",
                'amount' => (float)(($session['amount_total'] ?? 0) / 100),
            ];
        }
    } catch (Exception $e) {
        $errorMsg = safe_err("Payment verification error: ", $e);
    }
} elseif ($status === 'cancelled') {
    $errorMsg = "Payment was cancelled. No charge was made and you have not been checked in.";
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!origin_is_valid()) {
        http_response_code(403);
        die('Forbidden: Origin validation failed.');
    }

    if ($reason === 'renewal') {
        $tierId = (int)($_POST['tier_id'] ?? 0);
        $context = $tierId > 0 ? load_tier_payment_context($contactId, $tierId) : null;
        if (!$context) {
            $errorMsg = "Please select a membership level.";
        }
    } else {
        $context = load_payment_context($contactId);
        if (!$context) {
            $errorMsg = "Member or membership could not be found.";
        }
    }

    if ($context) {
        $action = $_POST['action'] ?? '';
        if ($action === 'card') {
            try {
                $session = StripeHelper::createCheckoutSession(
                    $contactId,
                    $context['plan_id'],
                    $context['civicrm_type_id'],
                    $context['membership_name'],
                    $context['amount'],
                    ($reason === 'entrance_fee') ? 'entrance_fee' : 'renew',
                    $context['contact']['email'],
                    $context['contact']['display_name'],
                    'pay-entrance.php',
                    ['reason' => $reason, 'return' => $returnPage]
                );
                header("Location: " . $session['url']);
                exit;
            } catch (Exception $e) {
                $errorMsg = safe_err("Failed to start card payment: ", $e);
            }
        } elseif ($action === 'cash') {
            try {
                $type = ($reason === 'entrance_fee') ? 'entrance_fee' : 'membership_renewal';
                $appDb = Database::getAppConnection();
                $dupStmt = $appDb->prepare("SELECT COUNT(*) FROM tgg_pending_payments WHERE contact_id = :contact_id AND status = 'pending'");
                $dupStmt->execute(['contact_id' => $contactId]);
                if ((int)$dupStmt->fetchColumn() > 0) {
                    $cashPendingMsg = "You already have a pending payment with the host. Please see the host to complete your check-in.";
                } else {
                    BillingHelper::createPendingPayment($contactId, $type, $context['plan_id'], $context['amount']);
                    $cashPendingMsg = sprintf(
                        "See the Host to pay $%s in cash. Your check-in will be completed once they confirm payment.",
                        number_format($context['amount'], 2)
                    );
                }
            } catch (Exception $e) {
                $errorMsg = safe_err("Failed to record cash payment request: ", $e);
            }
        } else {
            $errorMsg = "Please choose a payment method.";
        }
    }
}

// Load context for the initial GET screen. Entrance fees show a flat amount due +
// Pay Card / Pay Cash. Renewals always show a membership level selector (defaulting to
// the member's current level) instead of silently re-billing whatever plan they were
// last on -- that plan may not be what they want to renew into, and if it was the
// one-time Trial plan, it must never be offered at all.
$context = null;
$needsTierSelection = false;
$tiers = [];
$currentTierId = 0;
$priorPlanWasTrial = false;
if (!$errorMsg && !$cashPendingMsg && !$successDetails && $status === null) {
    $appDb = Database::getAppConnection();
    $dupStmt = $appDb->prepare("SELECT COUNT(*) FROM tgg_pending_payments WHERE contact_id = :contact_id AND status = 'pending'");
    $dupStmt->execute(['contact_id' => $contactId]);
    if ((int)$dupStmt->fetchColumn() > 0) {
        $cashPendingMsg = "You already have a pending payment with the host. Please see the host to complete your check-in.";
    } elseif ($reason === 'renewal') {
        $needsTierSelection = true;
        $contactStmt = $appDb->prepare("SELECT id, display_name, email FROM tgg_contacts WHERE id = :id AND is_deleted = 0 LIMIT 1");
        $contactStmt->execute(['id' => $contactId]);
        $contactRow = $contactStmt->fetch();
        if (!$contactRow) {
            $errorMsg = "Member or membership could not be found.";
        } else {
            $context = ['contact' => $contactRow];
            $tiers = get_renewable_tiers();

            $currentMembership = CiviCRMImporter::getMemberMembershipDetails($contactId);
            if ($currentMembership) {
                if (BillingHelper::isTrialPlan(['name' => $currentMembership['membership_name']])) {
                    $priorPlanWasTrial = true;
                } else {
                    $currentTierId = (int)$currentMembership['membership_id'];
                }
            }
        }
    } else {
        $context = load_payment_context($contactId);
        if (!$context) {
            $errorMsg = "Member or membership could not be found.";
        }
    }
}

$reasonLabel = ($reason === 'entrance_fee') ? 'Entrance Fee' : 'Membership Renewal';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Required - Club Entry</title>
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <link rel="icon" type="image/png" href="favicon.png">
    <link rel="stylesheet" href="assets/css/style.css<?php echo asset_version('assets/css/style.css'); ?>">
</head>
<body class="terminal-body">
    <div class="app-container">
        <?php $navKiosk = true; include __DIR__ . '/partials/navbar.php'; ?>

        <main class="main-content centered-content">
            <div class="terminal-panel glass-panel">
                <div class="terminal-header">
                    <h2>Payment Required</h2>
                    <p class="subtitle"><?php echo e($reasonLabel); ?></p>
                </div>

                <?php if ($errorMsg): ?>
                    <div class="alert alert-danger terminal-alert">
                        <span class="alert-icon">❌</span>
                        <div class="alert-text">
                            <strong>Payment Issue</strong>
                            <p><?php echo e($errorMsg); ?></p>
                        </div>
                    </div>
                    <div class="terminal-footer" style="margin-top: 20px;">
                        <a href="<?php echo e($returnPage); ?>" class="btn btn-secondary btn-block">Back to Check-In</a>
                    </div>

                <?php elseif ($successDetails): ?>
                    <div class="alert alert-success terminal-alert">
                        <span class="alert-icon">✔️</span>
                        <div class="alert-text">
                            <strong>Check-In Complete!</strong>
                            <p>Welcome, <?php echo e($successDetails['name']); ?>. Your payment of $<?php echo number_format($successDetails['amount'], 2); ?> was processed successfully.</p>
                        </div>
                    </div>
                    <div class="terminal-footer" style="margin-top: 20px;">
                        <a href="<?php echo e($returnPage); ?>" class="btn btn-primary btn-block">Continue</a>
                    </div>

                <?php elseif ($cashPendingMsg): ?>
                    <div class="alert alert-warning terminal-alert">
                        <span class="alert-icon">⚠️</span>
                        <div class="alert-text">
                            <strong>See the Host</strong>
                            <p style="font-size: 1.1rem;"><?php echo e($cashPendingMsg); ?></p>
                        </div>
                    </div>
                    <div class="terminal-footer" style="margin-top: 20px;">
                        <a href="<?php echo e($returnPage); ?>" class="btn btn-secondary btn-block">Back to Check-In</a>
                    </div>

                <?php elseif ($needsTierSelection && $context): ?>
                    <div style="text-align: center; margin-bottom: 25px;">
                        <p style="font-size: 1.15rem; color: var(--color-text-secondary); margin-bottom: 5px;"><?php echo e($context['contact']['display_name']); ?></p>
                        <p style="color: var(--color-text-secondary); margin-top: 5px;">
                            <?php if ($priorPlanWasTrial): ?>
                                Your Trial membership has ended. The Trial is a one-time offer and can't be renewed &mdash; please choose a membership level to continue.
                            <?php else: ?>
                                Your membership has expired. Select a level to renew &mdash; defaults to your current plan, or choose a different one to switch.
                            <?php endif; ?>
                        </p>
                    </div>

                    <?php if (empty($tiers)): ?>
                        <div class="alert alert-danger terminal-alert">
                            <span class="alert-icon">❌</span>
                            <div class="alert-text"><p>No membership levels are currently available. Please see the Host.</p></div>
                        </div>
                    <?php else: ?>
                        <form method="POST" action="pay-entrance.php" class="terminal-form" style="display: flex; flex-direction: column; gap: 12px;">
                            <input type="hidden" name="contact_id" value="<?php echo (int)$contactId; ?>">
                            <input type="hidden" name="reason" value="<?php echo e($reason); ?>">
                            <input type="hidden" name="return" value="<?php echo e($returnPage); ?>">
                            <div class="form-group">
                                <label for="tier_id">Select Membership Level</label>
                                <select id="tier_id" name="tier_id" required>
                                    <option value="" disabled<?php echo $currentTierId === 0 ? ' selected' : ''; ?>>-- Select a Level --</option>
                                    <?php foreach ($tiers as $tier):
                                        $unitText = strtolower($tier['duration_unit']);
                                        if ($unitText === 'year') $unitText = 'annual';
                                        elseif ($unitText === 'month') $unitText = 'monthly';
                                        elseif ($unitText === 'day') $unitText = 'daily';
                                    ?>
                                        <option value="<?php echo (int)$tier['id']; ?>" <?php echo ((int)$tier['id'] === $currentTierId) ? 'selected' : ''; ?>>
                                            <?php echo e($tier['name']); ?> - $<?php echo number_format($tier['price'], 2); ?> / <?php echo e($unitText); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" name="action" value="card" class="btn btn-primary btn-large btn-block">Pay with Card</button>
                            <button type="submit" name="action" value="cash" class="btn btn-secondary btn-large btn-block">Pay Cash</button>
                        </form>
                    <?php endif; ?>

                    <div class="terminal-footer" style="margin-top: 20px;">
                        <a href="<?php echo e($returnPage); ?>" class="card-link">Cancel &amp; Go Back</a>
                    </div>

                <?php elseif ($context): ?>
                    <div style="text-align: center; margin-bottom: 25px;">
                        <p style="font-size: 1.15rem; color: var(--color-text-secondary); margin-bottom: 5px;"><?php echo e($context['contact']['display_name']); ?></p>
                        <p style="font-size: 2rem; font-weight: 700; color: #fff; margin: 0; font-family: var(--font-heading);">$<?php echo number_format($context['amount'], 2); ?></p>
                        <p style="color: var(--color-text-secondary); margin-top: 5px;">
                            <?php echo $reason === 'entrance_fee'
                                ? 'Associate members pay an entrance fee on every visit after their first following a dues payment.'
                                : 'Your membership has expired. Renew now to check in.'; ?>
                        </p>
                    </div>

                    <form method="POST" action="pay-entrance.php" class="terminal-form" style="display: flex; flex-direction: column; gap: 12px;">
                        <input type="hidden" name="contact_id" value="<?php echo (int)$contactId; ?>">
                        <input type="hidden" name="reason" value="<?php echo e($reason); ?>">
                        <input type="hidden" name="return" value="<?php echo e($returnPage); ?>">
                        <button type="submit" name="action" value="card" class="btn btn-primary btn-large btn-block">Pay with Card</button>
                        <button type="submit" name="action" value="cash" class="btn btn-secondary btn-large btn-block">Pay Cash</button>
                    </form>

                    <div class="terminal-footer" style="margin-top: 20px;">
                        <a href="<?php echo e($returnPage); ?>" class="card-link">Cancel &amp; Go Back</a>
                    </div>
                <?php endif; ?>
            </div>
        </main>

        <?php include __DIR__ . '/partials/footer.php'; ?>
    </div>
</body>
</html>
