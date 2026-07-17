<?php
/**
 * Join / Renew Membership Page
 * Public registration & self-service renewal form, with dynamic CiviCRM tier
 * selection and Stripe payment redirect. No login or password is required to
 * become or remain a member; portal access is opt-in, set up later via an
 * emailed link.
 */
require_once dirname(dirname(__DIR__)) . '/config/bootstrap.php';

use App\Database;
use App\MembershipService;
use App\StripeHelper;
use App\Auth;
use App\BillingHelper;
use App\MailHelper;

function send_trial_verification_email($appDb, int $contactId, int $planId, string $email, string $displayName): void {
    $rawToken = bin2hex(random_bytes(32));
    $hashedToken = hash('sha256', $rawToken);
    $expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));

    $stmt = $appDb->prepare("
        INSERT INTO tgg_trial_verifications (contact_id, plan_id, token, expires_at)
        VALUES (:contact_id, :plan_id, :token, :expires_at)
        ON DUPLICATE KEY UPDATE plan_id = :plan_id2, token = :token2, expires_at = :expires_at2
    ");
    $stmt->execute([
        'contact_id' => $contactId,
        'plan_id' => $planId,
        'token' => $hashedToken,
        'expires_at' => $expiresAt,
        'plan_id2' => $planId,
        'token2' => $hashedToken,
        'expires_at2' => $expiresAt
    ]);

    $verifyLink = rtrim($_ENV['BASE_URL'] ?? 'http://localhost/member', '/') . '/verify-trial.php?token=' . $rawToken;
    MailHelper::sendTemplate($email, 'trial_verification', [
        'display_name' => $displayName,
        'verify_link' => $verifyLink,
        'expires_in' => '24 hours'
    ], $contactId, null);
}

/**
 * Look up a contact by email and, if found, their current membership plan.
 * Used both by the live AJAX email check and (re-derived, never trusted from
 * the client) during form submission to decide Join vs Renew.
 * @return array{exists:bool, contact_id:?int, display_name:?string, current_plan_id:?int, current_plan_name:?string, is_trial:bool}
 */
function lookup_member_by_email(\PDO $appDb, string $email, array $tiers): array {
    $result = [
        'exists' => false,
        'contact_id' => null,
        'display_name' => null,
        'current_plan_id' => null,
        'current_plan_name' => null,
        'is_trial' => false,
        'has_stored_card' => false
    ];

    $stmt = $appDb->prepare("SELECT id, display_name FROM tgg_contacts WHERE email = :email AND is_deleted = 0 LIMIT 1");
    $stmt->execute(['email' => $email]);
    $row = $stmt->fetch();
    if (!$row) {
        return $result;
    }

    $contactId = (int)$row['id'];
    $result['exists'] = true;
    $result['contact_id'] = $contactId;
    $result['display_name'] = $row['display_name'];

    // Auto-renew is switched on the moment a card is first captured for this
    // contact (see BillingHelper::processCheckoutSession), so a renewing member
    // with no card on file yet is about to hit that same first-capture moment.
    $cardStmt = $appDb->prepare("SELECT stripe_payment_method_id FROM tgg_subscriptions WHERE contact_id = :contact_id LIMIT 1");
    $cardStmt->execute(['contact_id' => $contactId]);
    $cardRow = $cardStmt->fetch();
    $result['has_stored_card'] = !empty($cardRow['stripe_payment_method_id'] ?? null);

    $membership = BillingHelper::getMemberSubscriptionDetails($contactId);
    if (!$membership) {
        $membership = MembershipService::getMemberMembershipDetails($contactId);
    }

    if ($membership && !empty($membership['membership_name'])) {
        $result['current_plan_name'] = $membership['membership_name'];
        $result['is_trial'] = BillingHelper::isTrialPlan(['name' => $membership['membership_name']]);
        $tierIndex = array_search($membership['membership_name'], array_column($tiers, 'name'));
        if ($tierIndex !== false) {
            $result['current_plan_id'] = (int)$tiers[$tierIndex]['id'];
        }
    }

    return $result;
}

$tiers = [];
$errorMsg = null;
$successMsg = null;
$confirmation = null;

try {
    $tiers = BillingHelper::getSubscriptionPlans(true);
} catch (Exception $e) {
    $errorMsg = "System Connection Error: Unable to fetch membership tiers.";
}

$isAjax = isset($_POST['ajax']) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest');

// 0. Live Email Lookup (AJAX) -- toggles the form between Join and Renew mode client-side
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAjax) {
    $lookupEmail = trim(strtolower($_POST['email'] ?? ''));
    if (!filter_var($lookupEmail, FILTER_VALIDATE_EMAIL)) {
        json_response(['exists' => false]);
    }
    try {
        $appDb = Database::getAppConnection();
        json_response(lookup_member_by_email($appDb, $lookupEmail, $tiers));
    } catch (Exception $e) {
        json_response(['exists' => false]);
    }
}

// 1. Handle Successful Redirect from Stripe -- shown in-page below rather than redirected
//    away, so the confirmation can't be missed as a banner on another page (see $confirmation
//    rendering further down).
if (isset($_GET['status']) && $_GET['status'] === 'success' && isset($_GET['session_id'])) {
    $sessionId = $_GET['session_id'];
    try {
        $session = StripeHelper::retrieveCheckoutSession($sessionId);
        if ($session['payment_status'] === 'paid') {
            BillingHelper::processCheckoutSession($session);

            $action = $session['metadata']['action'] ?? 'join';
            $contactId = (int)($session['metadata']['contact_id'] ?? 0);
            $planId = (int)($session['metadata']['plan_id'] ?? 0);

            $appDb = Database::getAppConnection();
            $displayName = 'Member';
            if ($contactId) {
                $stmt = $appDb->prepare("SELECT display_name FROM tgg_contacts WHERE id = :id LIMIT 1");
                $stmt->execute(['id' => $contactId]);
                $displayName = $stmt->fetchColumn() ?: $displayName;
            }
            $planName = 'Membership';
            if ($planId) {
                $stmt = $appDb->prepare("SELECT name FROM tgg_subscription_plans WHERE id = :id LIMIT 1");
                $stmt->execute(['id' => $planId]);
                $planName = $stmt->fetchColumn() ?: $planName;
            }

            $confirmation = [
                'type' => 'success',
                'heading' => $action === 'renew' ? 'Renewal Confirmation' : 'Signup Confirmation',
                'action' => $action,
                'name' => $displayName,
                'plan' => $planName,
                'amount' => (float)(($session['amount_total'] ?? 0) / 100),
            ];
        } else {
            $confirmation = [
                'type' => 'pending',
                'heading' => 'Payment Pending',
                'message' => "Your payment is still processing. Once it completes, your membership will be active -- check your email for confirmation.",
            ];
        }
    } catch (Exception $e) {
        $confirmation = [
            'type' => 'error',
            'heading' => 'Payment Verification Issue',
            'message' => safe_err("Could not verify payment status: ", $e),
        ];
    }
}

// 2. Handle Cancelled Redirect from Stripe -- also shown in-page (see note above). No
//    session_id is available for the cancel URL, so join-vs-renew can't be distinguished here.
if (isset($_GET['status']) && $_GET['status'] === 'cancelled') {
    $confirmation = [
        'type' => 'cancelled',
        'heading' => 'Payment Cancelled',
        'message' => "Payment was cancelled. You have not been charged and your membership registration was not completed.",
    ];
}

// 3. Handle Form Submission (Join or Renew)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_GET['status']) && !$isAjax) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errorMsg = "Invalid security token. Please reload the page.";
    } else {
        $email = trim(strtolower($_POST['email'] ?? ''));
        $tierId = (int)($_POST['tier_id'] ?? 0);

        if (empty($email) || empty($tierId)) {
            $errorMsg = "Email and Membership Level are required.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errorMsg = "Please enter a valid email address.";
        } else {
            try {
                $appDb = Database::getAppConnection();

                $tierIndex = array_search($tierId, array_column($tiers, 'id'));
                if ($tierIndex === false) {
                    throw new Exception("Invalid membership tier selected.");
                }
                $tier = $tiers[$tierIndex];
                $fee = (float)$tier['price'];
                $tierName = $tier['name'];
                $civicrmTypeId = (int)$tier['civicrm_membership_type_id'];
                $isTrial = BillingHelper::isTrialPlan($tier);

                // Re-derive Join vs Renew server-side -- the client-side detection is only a UI hint.
                $existing = lookup_member_by_email($appDb, $email, $tiers);

                if ($existing['exists']) {
                    // RENEW: reuse the existing contact, never create a duplicate.
                    if ($isTrial) {
                        throw new Exception("Trial membership is a one-time offer and is not available as a renewal.");
                    }

                    $contactId = $existing['contact_id'];
                    $displayName = $existing['display_name'] ?? 'Member';

                    $session = StripeHelper::createCheckoutSession($contactId, $tierId, $civicrmTypeId, $tierName, $fee, 'renew', $email, $displayName);
                    header("Location: " . $session['url']);
                    exit;
                }

                // JOIN: brand-new contact.
                $firstName = trim($_POST['first_name'] ?? '');
                $lastName = trim($_POST['last_name'] ?? '');
                $phone = normalize_phone(trim($_POST['phone'] ?? ''));

                if (empty($firstName) || empty($lastName)) {
                    throw new Exception("First and last name are required.");
                }

                if ($isTrial && BillingHelper::hasUsedOrPendingTrial($email)) {
                    throw new Exception("This email address has already used its one-time Trial membership and is not eligible for another.");
                }

                $appDb->beginTransaction();

                try {
                    // A. Create Local Contact
                    $displayName = "{$firstName} {$lastName}";
                    $insertContact = $appDb->prepare("INSERT INTO tgg_contacts (contact_type, display_name, first_name, last_name, email, phone, is_deleted)
                                                       VALUES ('Individual', :display_name, :first_name, :last_name, :email, :phone, 0)");
                    $insertContact->execute([
                        'display_name' => $displayName,
                        'first_name' => $firstName,
                        'last_name' => $lastName,
                        'email' => $email,
                        'phone' => !empty($phone) ? $phone : null
                    ]);
                    $contactId = (int)$appDb->lastInsertId();

                    // B. Create Local Member Settings with a random, discarded password hash --
                    // members don't need a portal password to be a member. They get a "set up
                    // your password" link by email once payment/activation completes, if they
                    // ever want to log in.
                    $randomPasswordHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
                    $insertSettings = $appDb->prepare("INSERT INTO tgg_member_settings (contact_id, password_hash, role)
                                                       VALUES (:contact_id, :password_hash, 'member')");
                    $insertSettings->execute([
                        'contact_id' => $contactId,
                        'password_hash' => $randomPasswordHash
                    ]);

                    $appDb->commit();

                    if ($isTrial) {
                        // Trial membership is free and skips Stripe entirely; it doesn't
                        // activate until the user clicks the emailed verification link.
                        send_trial_verification_email($appDb, $contactId, $tierId, $email, $displayName);
                        $successMsg = "Thanks for registering! We've sent a verification link to {$email}. Click it to activate your one-time 30-day Trial membership.";
                    } else {
                        // D. Create Stripe Session and Redirect
                        $session = StripeHelper::createCheckoutSession($contactId, $tierId, $civicrmTypeId, $tierName, $fee, 'join', $email, $displayName);

                        header("Location: " . $session['url']);
                        exit;
                    }

                } catch (Exception $txException) {
                    if ($appDb->inTransaction()) $appDb->rollBack();
                    throw $txException;
                }
            } catch (Exception $e) {
                $errorMsg = safe_err("Registration failed: ", $e);
            }
        }
    }
}

// Determine initial render mode: only relevant when redisplaying the form after a
// server-side validation error, so the Renew UI doesn't flicker back to Join mode.
// Also handles GET ?email= links (e.g. from renewal reminder emails) so the member
// lookup fires immediately on arrival without requiring a form submission.
$prefillExisting = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['email']) && !isset($successMsg)) {
    try {
        $appDb = $appDb ?? Database::getAppConnection();
        $prefillEmail = trim(strtolower($_POST['email']));
        if (filter_var($prefillEmail, FILTER_VALIDATE_EMAIL)) {
            $prefillExisting = lookup_member_by_email($appDb, $prefillEmail, $tiers);
        }
    } catch (Exception $e) {
        $prefillExisting = null;
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($_GET['email']) && !isset($successMsg)) {
    try {
        $appDb = $appDb ?? Database::getAppConnection();
        $prefillEmail = trim(strtolower($_GET['email']));
        if (filter_var($prefillEmail, FILTER_VALIDATE_EMAIL)) {
            $prefillExisting = lookup_member_by_email($appDb, $prefillEmail, $tiers);
        }
    } catch (Exception $e) {
        $prefillExisting = null;
    }
}
$isRenewMode = $prefillExisting && $prefillExisting['exists'];
$displayTiers = $isRenewMode
    ? array_values(array_filter($tiers, function ($tier) { return !BillingHelper::isTrialPlan($tier); }))
    : $tiers;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Join / Renew - Membership</title>
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <link rel="icon" type="image/png" href="favicon.png">
    <link rel="apple-touch-icon" href="favicon.png">
    <link rel="manifest" href="manifest.json">
    <link rel="stylesheet" href="assets/css/style.css<?php echo asset_version('assets/css/style.css'); ?>">
</head>
<body>
    <div class="app-container">
        <?php $navActive = 'join'; $navGuestCheckin = false; include __DIR__ . '/partials/navbar.php'; ?>

        <main class="main-content centered-content">
            <div class="auth-panel glass-panel">
                <?php if ($confirmation): ?>
                    <h2 id="join_heading"><?php echo e($confirmation['heading']); ?></h2>
                <?php else: ?>
                    <h2 id="join_heading"><?php echo $isRenewMode ? 'Renew Your Membership' : 'Become a Member'; ?></h2>
                    <p class="subtitle" id="join_subtitle">
                        <?php echo $isRenewMode
                            ? 'Welcome back! Pick a level below to renew your membership.'
                            : 'Complete the form below to register for a 30 day trial or pay your membership dues securely.'; ?>
                    </p>
                <?php endif; ?>

                <?php if ($errorMsg): ?>
                    <div class="alert alert-danger"><?php echo e($errorMsg); ?></div>
                <?php endif; ?>

                <?php if ($confirmation && $confirmation['type'] === 'success'): ?>
                    <!-- terminal-alert opts this block out of main.js's auto-toast/auto-dismiss
                         behavior (see main.js "Alert Auto-Disposal") -- a confirmation this
                         important shouldn't fade away and take its CTA button with it. -->
                    <div class="alert alert-success terminal-alert">
                        <p>
                            Thank you, <?php echo e($confirmation['name']); ?>! Your <?php echo $confirmation['action'] === 'renew' ? 'renewal' : 'signup'; ?> payment of
                            $<?php echo number_format($confirmation['amount'], 2); ?> for <strong><?php echo e($confirmation['plan']); ?></strong>
                            was successful and your membership is active. Check your email for a receipt<?php echo $confirmation['action'] === 'join' ? ' and a link to set up your portal login' : ''; ?>.
                        </p>
                        <br>
                        <a href="index.php?action=login" class="btn btn-primary">Go to Login</a>
                    </div>
                <?php elseif ($confirmation): ?>
                    <div class="alert <?php echo $confirmation['type'] === 'pending' ? 'alert-warning' : 'alert-danger'; ?> terminal-alert">
                        <p><?php echo e($confirmation['message']); ?></p>
                        <br>
                        <a href="join.php" class="btn btn-primary">Back to Join / Renew</a>
                    </div>
                <?php elseif ($successMsg): ?>
                    <div class="alert alert-success">
                        <p><?php echo e($successMsg); ?></p>
                        <br>
                        <a href="join.php" class="btn btn-primary">Back to Join / Renew</a>
                    </div>
                <?php else: ?>
                    <form action="join.php" method="POST" class="auth-form" id="join_form">
                        <input type="hidden" name="csrf_token" value="<?php echo e(get_csrf_token()); ?>">
                        <input type="hidden" name="existing_contact_id" id="existing_contact_id" value="<?php echo $isRenewMode ? (int)$prefillExisting['contact_id'] : ''; ?>">
                        <input type="hidden" id="has_stored_card" value="<?php echo ($isRenewMode && !empty($prefillExisting['has_stored_card'])) ? '1' : ''; ?>">

                        <div class="form-group">
                            <label for="email">Email Address</label>
                            <input type="email" id="email" name="email" required autocomplete="email" value="<?php echo e($_POST['email'] ?? $_GET['email'] ?? ''); ?>">
                            <small id="email_lookup_status" class="field-hint" style="display:none;">Checking...</small>
                        </div>

                        <div class="form-row" id="name_fields_row" style="<?php echo $isRenewMode ? 'display:none;' : ''; ?>">
                            <div class="form-group">
                                <label for="first_name">First Name</label>
                                <input type="text" id="first_name" name="first_name" <?php echo $isRenewMode ? '' : 'required'; ?> value="<?php echo e($_POST['first_name'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label for="last_name">Last Name</label>
                                <input type="text" id="last_name" name="last_name" <?php echo $isRenewMode ? '' : 'required'; ?> value="<?php echo e($_POST['last_name'] ?? ''); ?>">
                            </div>
                        </div>

                        <div class="form-group" id="phone_field_group" style="<?php echo $isRenewMode ? 'display:none;' : ''; ?>">
                            <label for="phone">Phone Number (Optional)</label>
                            <input type="tel" id="phone" name="phone" value="<?php echo e($_POST['phone'] ?? ''); ?>">
                        </div>

                        <div class="form-group">
                            <label for="tier_id">Select Membership Level</label>
                            <select id="tier_id" name="tier_id" required onchange="updateJoinCallToAction()">
                                <option value="" disabled <?php echo $isRenewMode ? '' : 'selected'; ?>>-- Select a Level --</option>
                                <?php foreach ($displayTiers as $tier): ?>
                                    <?php
                                        $optionSelected = $isRenewMode
                                            ? ((int)$tier['id'] === (int)($prefillExisting['current_plan_id'] ?? 0))
                                            : (isset($_POST['tier_id']) && $_POST['tier_id'] == $tier['id']);
                                    ?>
                                    <option value="<?php echo (int)$tier['id']; ?>" data-trial="<?php echo BillingHelper::isTrialPlan($tier) ? '1' : '0'; ?>" data-duration-interval="<?php echo (int)$tier['duration_interval']; ?>" data-duration-unit="<?php echo e(strtolower($tier['duration_unit'])); ?>" <?php echo $optionSelected ? 'selected' : ''; ?>>
                                        <?php echo e($tier['name']); ?> - $<?php echo number_format($tier['minimum_fee'], 2); ?> / <?php echo e($tier['duration_interval']); ?> <?php echo e($tier['duration_unit']); ?>(s)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div id="join_legal_info" style="display:none;"></div>

                        <div class="info-block payment-warning" id="join_cta_info">
                            <p><strong>Secure Checkout:</strong> Clicking the button below will redirect you to Stripe to pay your membership dues securely.</p>
                        </div>

                        <button type="submit" class="btn btn-primary btn-block" id="join_cta_button">Proceed to Payment</button>
                    </form>

                    <script>
                        const tierSelect = document.getElementById('tier_id');
                        const existingContactInput = document.getElementById('existing_contact_id');
                        const hasStoredCardInput = document.getElementById('has_stored_card');
                        const emailInput = document.getElementById('email');
                        const headingEl = document.getElementById('join_heading');
                        const subtitleEl = document.getElementById('join_subtitle');
                        const nameRow = document.getElementById('name_fields_row');
                        const phoneGroup = document.getElementById('phone_field_group');
                        const firstNameInput = document.getElementById('first_name');
                        const lastNameInput = document.getElementById('last_name');
                        const lookupStatusEl = document.getElementById('email_lookup_status');

                        function formatBillingPeriod(selectedOpt) {
                            const interval = parseInt(selectedOpt.getAttribute('data-duration-interval') || '1', 10);
                            const unit = selectedOpt.getAttribute('data-duration-unit') || 'year';
                            return interval > 1 ? `${interval} ${unit}s` : unit;
                        }

                        function updateJoinCallToAction() {
                            const infoBlock = document.getElementById('join_cta_info');
                            const legalBlock = document.getElementById('join_legal_info');
                            const button = document.getElementById('join_cta_button');
                            const selectedOpt = tierSelect.options[tierSelect.selectedIndex];
                            const isTrial = selectedOpt && selectedOpt.getAttribute('data-trial') === '1';
                            const isRenew = !!existingContactInput.value;
                            const hasStoredCard = hasStoredCardInput.value === '1';

                            let legalHtml = '';
                            if (!isRenew) {
                                legalHtml += '<div class="info-block">By becoming a member of the Tampa Gaming Guild, Inc social club you agree to follow the rules, regulations and code of conduct as published on <a href="https://tampagamingguild.org" target="_blank" rel="noopener">tampagamingguild.org</a>.</div>';
                            }
                            if (!isTrial && !hasStoredCard && selectedOpt && selectedOpt.value) {
                                const period = formatBillingPeriod(selectedOpt);
                                legalHtml += `<div class="info-block">By providing your card, you authorize Tampa Gaming Guild, Inc. to automatically charge it each ${period} to renew your membership. You can cancel this auto-renewal at any time from your profile.</div>`;
                            }
                            legalBlock.innerHTML = legalHtml;
                            legalBlock.style.display = legalHtml ? '' : 'none';

                            if (isTrial) {
                                infoBlock.innerHTML = '<p><strong>Verify Your Email:</strong> No payment is required. After you register, we\'ll email you a verification link &mdash; click it to activate your one-time 30-day Trial membership.</p>';
                                button.textContent = 'Send Verification Email';
                            } else if (isRenew) {
                                infoBlock.innerHTML = '<p><strong>Secure Checkout:</strong> Clicking the button below will redirect you to Stripe to pay your renewal dues securely.</p>';
                                button.textContent = 'Pay Renewal Dues';
                            } else {
                                infoBlock.innerHTML = '<p><strong>Secure Checkout:</strong> Clicking the button below will redirect you to Stripe to pay your membership dues securely.</p>';
                                button.textContent = 'Proceed to Payment';
                            }
                        }

                        function applyLookupResult(result) {
                            const isRenew = !!(result && result.exists);
                            existingContactInput.value = isRenew ? result.contact_id : '';
                            hasStoredCardInput.value = (isRenew && result.has_stored_card) ? '1' : '';

                            headingEl.textContent = isRenew ? 'Renew Your Membership' : 'Become a Member';
                            subtitleEl.textContent = isRenew
                                ? 'Welcome back! Pick a level below to renew your membership.'
                                : 'Complete the form below to register for a 30 day trial or pay your membership dues securely.';

                            nameRow.style.display = isRenew ? 'none' : '';
                            phoneGroup.style.display = isRenew ? 'none' : '';
                            firstNameInput.required = !isRenew;
                            lastNameInput.required = !isRenew;

                            let hasSelection = false;
                            for (const opt of tierSelect.options) {
                                if (!opt.value) continue;
                                const isTrialOption = opt.getAttribute('data-trial') === '1';
                                opt.hidden = isRenew && isTrialOption;
                                opt.disabled = isRenew && isTrialOption;
                                if (isRenew && result.current_plan_id && Number(opt.value) === Number(result.current_plan_id) && !isTrialOption) {
                                    opt.selected = true;
                                    hasSelection = true;
                                }
                            }
                            if (isRenew && !hasSelection) {
                                tierSelect.value = '';
                            }

                            updateJoinCallToAction();
                        }

                        function checkEmail() {
                            const email = emailInput.value.trim();
                            if (!email || !email.includes('@')) {
                                applyLookupResult(null);
                                return;
                            }
                            lookupStatusEl.style.display = 'block';

                            const data = new URLSearchParams();
                            data.append('ajax', '1');
                            data.append('email', email);

                            fetch('join.php', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/x-www-form-urlencoded',
                                    'X-Requested-With': 'XMLHttpRequest'
                                },
                                body: data.toString()
                            })
                                .then(res => res.json())
                                .then(result => {
                                    lookupStatusEl.style.display = 'none';
                                    applyLookupResult(result);
                                })
                                .catch(() => {
                                    lookupStatusEl.style.display = 'none';
                                });
                        }

                        let lookupTimer = null;
                        emailInput.addEventListener('blur', checkEmail);
                        emailInput.addEventListener('input', () => {
                            clearTimeout(lookupTimer);
                            lookupTimer = setTimeout(checkEmail, 600);
                        });

                        updateJoinCallToAction();
                    </script>
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
