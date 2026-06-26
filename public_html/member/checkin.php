<?php
/**
 * Member Check-In Portal
 * Simple check-in terminal for members to record their visit by email or Contact ID.
 * Supports standard page POSTs and AJAX requests.
 */
require_once dirname(dirname(__DIR__)) . '/config/bootstrap.php';

use App\Database;
use App\CiviCRMImporter;
use App\BillingHelper;

/**
 * Calculate physical distance between two GPS coordinates using Haversine formula
 */
if (!function_exists('get_distance_meters')) {
    function get_distance_meters($lat1, $lon1, $lat2, $lon2) {
        $earthRadius = 6371000; // Earth's radius in meters
        $latFrom = deg2rad($lat1);
        $lonFrom = deg2rad($lon1);
        $latTo = deg2rad($lat2);
        $lonTo = deg2rad($lon2);

        $latDelta = $latTo - $latFrom;
        $lonDelta = $lonTo - $lonFrom;

        $angle = 2 * asin(sqrt(pow(sin($latDelta / 2), 2) +
            cos($latFrom) * cos($latTo) * pow(sin($lonDelta / 2), 2)));

        return $angle * $earthRadius;
    }
}

$errorMsg = null;
$successMsg = null;
$memberDetails = null;
$redirectUrl = null;
$isLoggedIn = \App\Auth::check();
$loggedInName = $isLoggedIn ? ($_SESSION['user']['display_name'] ?? 'Member') : '';
// Authenticated users always skip geolocation check
$isGeoEnabled = !$isLoggedIn && ($_ENV['GEOLOCATION_CHECK_ENABLED'] ?? 'false') === 'true';

/**
 * Resolve a contact ID from an email or numeric ID identifier, the same way the
 * check-in POST handler does.
 */
function resolve_checkin_contact_id(string $identifier): int {
    $appDb = Database::getAppConnection();
    if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
        $stmt = $appDb->prepare("SELECT id FROM tgg_contacts WHERE email = :email AND is_deleted = 0 LIMIT 1");
        $stmt->execute(['email' => strtolower($identifier)]);
        $row = $stmt->fetch();
        return $row ? (int)$row['id'] : 0;
    }
    if (is_numeric($identifier)) {
        return (int)$identifier;
    }
    $digits = normalize_phone($identifier);
    if ($digits !== '') {
        $stmt = $appDb->prepare("SELECT id FROM tgg_contacts WHERE phone = :phone AND is_deleted = 0");
        $stmt->execute(['phone' => $digits]);
        $rows = $stmt->fetchAll();
        if (count($rows) === 1) {
            return (int)$rows[0]['id'];
        }
    }
    return 0;
}

/**
 * Look up a contact's guest pass allowance/remaining for the current calendar month.
 * Returns allowance=0 when there's no active membership, so callers can hide the
 * guest UI entirely for plans/members that don't support guest passes.
 */
function lookup_guest_pass_info(int $contactId): array {
    $default = ['allowance' => 0, 'used' => 0, 'remaining' => 0];
    if ($contactId <= 0) {
        return $default;
    }
    try {
        $membership = CiviCRMImporter::getMemberMembershipDetails($contactId);
        if (!$membership || !$membership['is_active']) {
            return $default;
        }
        return BillingHelper::getGuestPassesRemaining($contactId, $membership);
    } catch (Exception $e) {
        return $default;
    }
}

// AJAX: dynamic guest-pass lookup as the (unauthenticated) member types their email/ID
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'guest_status') {
    $contactId = resolve_checkin_contact_id(trim($_GET['identifier'] ?? ''));
    json_response(lookup_guest_pass_info($contactId));
}

// For a logged-in member, we already know who they are -- compute their guest pass
// status up front so the page can render it immediately and decide whether to auto-submit.
$guestPassInfo = ['allowance' => 0, 'used' => 0, 'remaining' => 0];
if ($isLoggedIn) {
    $guestPassInfo = lookup_guest_pass_info((int)($_SESSION['user']['contact_id'] ?? 0));
}

// Handle Check-In POST (Standard & AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Basic Origin Validation to prevent CSRF in the absence of a token (kiosk use-case)
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if (!empty($referer)) {
        $refererHost = parse_url($referer, PHP_URL_HOST);
        $normalizedHost = explode(':', $host)[0];
        $normalizedRefererHost = explode(':', $refererHost)[0];
        if ($normalizedRefererHost !== $normalizedHost) {
            http_response_code(403);
            if (isset($_POST['ajax']) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest')) {
                json_response(['error' => 'Forbidden: Origin validation failed.']);
            } else {
                die('Forbidden: Origin validation failed.');
            }
        }
    }

    $identifier = trim($_POST['identifier'] ?? '');
    $notes = trim($_POST['notes'] ?? 'Regular Visit');
    $guestNames = [];
    if (isset($_POST['guest_names']) && is_array($_POST['guest_names'])) {
        foreach ($_POST['guest_names'] as $guestName) {
            $guestName = trim(mb_substr((string)$guestName, 0, 100));
            if ($guestName !== '') {
                $guestNames[] = $guestName;
            }
            if (count($guestNames) >= 10) {
                break;
            }
        }
    }
    $isAjax = isset($_POST['ajax']) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest');

    if (empty($identifier)) {
        $errorMsg = "Please enter your Email, Phone Number, or Member ID.";
    } else {
        try {
            $appDb = Database::getAppConnection();
            
            // Geolocation Validation
            $geoValid = true;
            
            if ($isGeoEnabled) {
                $latitude = isset($_POST['latitude']) && is_numeric($_POST['latitude']) ? (float)$_POST['latitude'] : null;
                $longitude = isset($_POST['longitude']) && is_numeric($_POST['longitude']) ? (float)$_POST['longitude'] : null;
                
                if ($latitude === null || $longitude === null) {
                    $errorMsg = "Location access is required to check in. Please enable location permissions.";
                    $geoValid = false;
                } else {
                    $clubLat = (float)($_ENV['CLUB_LATITUDE'] ?? 27.9506);
                    $clubLon = (float)($_ENV['CLUB_LONGITUDE'] ?? -82.4572);
                    $maxDistance = (float)($_ENV['CLUB_MAX_CHECKIN_DISTANCE_METERS'] ?? 100);
                    
                    $distance = get_distance_meters($latitude, $longitude, $clubLat, $clubLon);
                    if ($distance > $maxDistance) {
                        $errorMsg = "Location check failed. You must be at the club's physical location to check in (Distance: " . round($distance) . " meters away).";
                        $geoValid = false;
                    }
                }
            }

            // Session/Activity Validation: check-in is only allowed when a session is
            // scheduled today, opening 1 hour before its start through its end time.
            $sessionOpen = true;
            if ($geoValid) {
                $sessionStmt = $appDb->prepare("
                    SELECT COUNT(*) FROM tgg_events
                    WHERE DATE(start_time) = CURDATE()
                      AND NOW() >= DATE_SUB(start_time, INTERVAL 1 HOUR)
                      AND NOW() <= end_time
                ");
                $sessionStmt->execute();
                $sessionOpen = (int)$sessionStmt->fetchColumn() > 0;

                if (!$sessionOpen) {
                    $errorMsg = "Check-in Denied: There is no session open for check-in right now. Check-in opens 1 hour before a scheduled session begins.";
                }
            }

            if ($geoValid && $sessionOpen) {
                $contactId = 0;
                $contactName = '';

                // 1. Resolve Contact ID
                if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
                    // Resolved via Email
                    $stmt = $appDb->prepare("SELECT id FROM tgg_contacts WHERE email = :email AND is_deleted = 0 LIMIT 1");
                    $stmt->execute(['email' => strtolower($identifier)]);
                    $row = $stmt->fetch();
                    if ($row) {
                        $contactId = (int)$row['id'];
                    }
                } elseif (is_numeric($identifier)) {
                    // Resolved via Contact ID
                    $contactId = (int)$identifier;
                } else {
                    // Resolved via Phone
                    $digits = normalize_phone($identifier);
                    if ($digits !== '') {
                        $phoneStmt = $appDb->prepare("SELECT id FROM tgg_contacts WHERE phone = :phone AND is_deleted = 0");
                        $phoneStmt->execute(['phone' => $digits]);
                        $phoneRows = $phoneStmt->fetchAll();
                        if (count($phoneRows) === 1) {
                            $contactId = (int)$phoneRows[0]['id'];
                        } elseif (count($phoneRows) > 1) {
                            $errorMsg = "Multiple accounts share that phone number. Please use your email address or member ID.";
                        }
                    }
                }

                if ($contactId <= 0) {
                    if (empty($errorMsg)) {
                        $errorMsg = "Member not found. Please check your email, phone number, or member ID.";
                    }
                } else {
                    // 2. Fetch Contact Name and Expiry Date
                    $contactStmt = $appDb->prepare("SELECT display_name FROM tgg_contacts WHERE id = :id AND is_deleted = 0 LIMIT 1");
                    $contactStmt->execute(['id' => $contactId]);
                    $contactRow = $contactStmt->fetch();

                    if (!$contactRow) {
                        $errorMsg = "Member not found in database.";
                    } else {
                        $contactName = CiviCRMImporter::getFormattedName($contactId);

                        // 2b. Prevent double check-in on the same day (guest visits don't count toward this)
                        $dupCheckStmt = $appDb->prepare("SELECT COUNT(*) FROM tgg_checkins WHERE contact_id = :contact_id AND guest_name IS NULL AND DATE(checked_in_at) = CURDATE()");
                        $dupCheckStmt->execute(['contact_id' => $contactId]);
                        $hasCheckedInToday = (int)$dupCheckStmt->fetchColumn() > 0;

                        if ($hasCheckedInToday && empty($guestNames)) {
                            $errorMsg = "Check-in Denied: {$contactName} has already checked in today.";
                        } else {
                            // 3. Verify Active Membership
                            $membership = CiviCRMImporter::getMemberMembershipDetails($contactId);

                            if (!$membership || !$membership['is_active']) {
                                // Expired/inactive membership: send them to renew (Card or Cash) instead of a flat denial.
                                $redirectUrl = 'pay-entrance.php?contact_id=' . $contactId . '&reason=renewal&return=checkin.php';
                            } elseif (BillingHelper::entranceFeeOwed($contactId, $membership)) {
                                // Associate member's 2nd+ check-in since their last dues payment: pay the entrance fee first.
                                $redirectUrl = 'pay-entrance.php?contact_id=' . $contactId . '&reason=entrance_fee&return=checkin.php';
                            } else {
                                // 3b. Enforce the monthly guest pass allowance before logging anything
                                $guestLimitExceeded = false;
                                if (!empty($guestNames)) {
                                    $passes = BillingHelper::getGuestPassesRemaining($contactId, $membership);
                                    if (count($guestNames) > $passes['remaining']) {
                                        $guestLimitExceeded = true;
                                        $errorMsg = "Only {$passes['remaining']} guest pass(es) remaining this month for {$contactName}.";
                                    }
                                }

                                if (!$guestLimitExceeded) {
                                    // 4. Log the check-in (and any guests)
                                    if (!$hasCheckedInToday) {
                                        $insertStmt = $appDb->prepare("INSERT INTO tgg_checkins (contact_id, checked_in_at, notes) VALUES (:contact_id, NOW(), :notes)");
                                        $insertStmt->execute([
                                            'contact_id' => $contactId,
                                            'notes' => $notes
                                        ]);
                                    }

                                    if (!empty($guestNames)) {
                                        $insertGuestStmt = $appDb->prepare("INSERT INTO tgg_checkins (contact_id, checked_in_at, guest_name) VALUES (:contact_id, NOW(), :guest_name)");
                                        foreach ($guestNames as $guestName) {
                                            $insertGuestStmt->execute([
                                                'contact_id' => $contactId,
                                                'guest_name' => $guestName
                                            ]);
                                        }
                                    }

                                    $successMsg = "Check-In Successful! Welcome, {$contactName}.";
                                    if (!empty($guestNames)) {
                                        $successMsg .= " Checked in with " . count($guestNames) . " guest(s).";
                                    }
                                    $memberDetails = [
                                        'name' => $contactName,
                                        'membership' => $membership['membership_name'],
                                        'expires' => date('M d, Y', strtotime($membership['end_date']))
                                    ];
                                }
                            }
                        }
                    }
                }
            }
        } catch (Exception $e) {
            $errorMsg = safe_err("Check-in system error: ", $e);
        }
    }

    // Return AJAX Response
    if ($isAjax) {
        if ($redirectUrl) {
            json_response(['success' => false, 'redirect' => $redirectUrl], 200);
        } elseif ($errorMsg) {
            json_response(['success' => false, 'error' => $errorMsg], 400);
        } else {
            json_response([
                'success' => true,
                'message' => $successMsg,
                'details' => $memberDetails
            ], 200);
        }
    } elseif ($redirectUrl) {
        header("Location: {$redirectUrl}");
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Member Check-In - Club Entry</title>
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <link rel="icon" type="image/png" href="favicon.png">
    <link rel="apple-touch-icon" href="favicon.png">
    <link rel="manifest" href="manifest.json">
    <link rel="stylesheet" href="assets/css/style.css<?php echo asset_version('assets/css/style.css'); ?>">
</head>
<body class="terminal-body">
    <div class="app-container">
        <?php $navKiosk = true; $navActive = 'checkin'; include __DIR__ . '/partials/navbar.php'; ?>

        <main class="main-content centered-content">
            <div class="terminal-panel glass-panel">
                <div class="terminal-header">
                    <h2>Club Entry Check-In</h2>
                    <?php if ($isLoggedIn): ?>
                        <p class="subtitle">Please verify your location to complete check-in.</p>
                    <?php else: ?>
                        <p class="subtitle">Please scan your barcode, or enter your Email or Member ID to check in.</p>
                    <?php endif; ?>
                </div>

                <div id="feedback-area">
                    <?php if ($errorMsg): ?>
                        <div class="alert alert-danger terminal-alert">
                            <span class="alert-icon">❌</span>
                            <div class="alert-text">
                                <strong>Access Denied</strong>
                                <p><?php echo e($errorMsg); ?></p>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($successMsg && $memberDetails): ?>
                        <div class="alert alert-success terminal-alert">
                            <span class="alert-icon">✔️</span>
                            <div class="alert-text">
                                <strong>Welcome, <?php echo e($memberDetails['name']); ?>!</strong>
                                <p><?php echo e($successMsg); ?></p>
                                <span class="subtext">Membership Type: <?php echo e($memberDetails['membership']); ?> | Expiration: <?php echo e($memberDetails['expires']); ?></span>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <form id="checkin-form" action="checkin.php" method="POST" class="terminal-form" autocomplete="off">
                    <?php if ($isLoggedIn): ?>
                        <div style="text-align: center; margin-bottom: 25px;">
                            <p style="font-size: 1.15rem; color: var(--color-text-secondary); margin-bottom: 5px;">Logged in as:</p>
                            <p style="font-size: 1.4rem; font-weight: 700; color: #fff; margin: 0; font-family: var(--font-heading);"><?php echo e($loggedInName); ?></p>
                        </div>
                        <input type="hidden" id="identifier" name="identifier" value="<?php echo e($_SESSION['user']['contact_id']); ?>">
                    <?php else: ?>
                        <div class="form-group large-input">
                            <label for="identifier">Email Address, Phone Number, or Member ID</label>
                            <input type="text" id="identifier" name="identifier" autocomplete="off" autocorrect="off" autocapitalize="none" spellcheck="false" data-1p-ignore data-lpignore="true" data-bwignore data-form-type="other" required placeholder="Enter email, phone, or ID…" autofocus>
                        </div>
                    <?php endif; ?>

                    <div class="form-group" id="guest-pass-area" style="margin-bottom: 15px; display: none;">
                        <p id="guest-pass-status" class="subtext" style="margin: 0 0 8px 0; text-align: center;"></p>
                        <button type="button" id="guest-toggle-btn" class="btn btn-secondary btn-block">Bring a guest?</button>
                        <div id="guest-section" style="display: none; margin-top: 12px;">
                            <div id="guest-name-fields"></div>
                            <button type="button" id="add-guest-btn" class="btn btn-secondary btn-small" style="margin-top: 8px;">+ Add another guest</button>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary btn-large btn-block">Check-In</button>
                </form>

                <div class="terminal-footer">
                    <p>Issues checking in? Please ask for the host.</p>
                </div>
            </div>
        </main>

        <?php $footerText = 'TGG Club Entry Terminal. Auto-refreshing.'; include __DIR__ . '/partials/footer.php'; ?>

    <!-- Checkin sound effects and autofocus scripts -->
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const inputField = document.getElementById('identifier');
            const guestPassArea = document.getElementById('guest-pass-area');

            // Keep input focused at all times for quick barcode scanner entry (only if text input),
            // but not while the user is interacting with the guest pass controls.
            if (inputField && inputField.type !== 'hidden') {
                document.addEventListener('click', (e) => {
                    if (e.target.closest('#guest-pass-area')) {
                        return;
                    }
                    inputField.focus();
                });
            }

            const isGeoEnabled = <?php echo $isGeoEnabled ? 'true' : 'false'; ?>;

            // AJAX Handler for faster checkins (desk tablet mode)
            const form = document.getElementById('checkin-form');
            const feedbackArea = document.getElementById('feedback-area');
            const submitBtn = form.querySelector('button[type="submit"]');

            function setButtonChecking() {
                submitBtn.disabled = true;
                submitBtn.textContent = 'Checking your current location...';
                submitBtn.style.backgroundColor = '#ffcc00';
                submitBtn.style.color = '#000';
            }

            function resetButtonState() {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Check-In';
                submitBtn.style.backgroundColor = '';
                submitBtn.style.color = '';
            }

            function proceedWithCheckin() {
                if (isGeoEnabled) {
                    setButtonChecking();

                    if (!navigator.geolocation) {
                        renderFeedback(false, 'Your browser does not support geolocation, which is required to check in.');
                        resetButtonState();
                        return;
                    }

                    navigator.geolocation.getCurrentPosition(
                        (position) => {
                            const lat = position.coords.latitude;
                            const lon = position.coords.longitude;
                            submitCheckin(lat, lon);
                        },
                        (error) => {
                            let msg = 'Failed to acquire location.';
                            if (error.code === error.PERMISSION_DENIED) {
                                msg = 'Location access denied. You must allow location access to check in.';
                            } else if (error.code === error.POSITION_UNAVAILABLE) {
                                msg = 'Location details unavailable. Please enable GPS/Location services.';
                            } else if (error.code === error.TIMEOUT) {
                                msg = 'Location request timed out. Please try again.';
                            }
                            renderFeedback(false, msg);
                            resetButtonState();
                        },
                        {
                            enableHighAccuracy: true,
                            timeout: 10000,
                            maximumAge: 0
                        }
                    );
                } else {
                    submitBtn.disabled = true;
                    submitBtn.textContent = 'Check-In';
                    submitCheckin();
                }
            }

            form.addEventListener('submit', (e) => {
                e.preventDefault();

                // Unauthenticated kiosk flow: someone who types fast and taps Check-In right away
                // could otherwise outrun the guest-pass lookup entirely. Make sure we know their
                // guest-pass status (using a fresh lookup if needed) before ever submitting, and if
                // they have a pass available and haven't already opened "Bring a guest?", pause once
                // so they get a real chance to use it instead of silently skipping it.
                if (!isLoggedIn && !guestPromptAcknowledged) {
                    const identifier = inputField.value.trim();
                    guestPromptAcknowledged = true;

                    if (identifier === lastCheckedIdentifier) {
                        if (currentGuestRemaining > 0 && guestSection.style.display !== 'block') {
                            submitBtn.textContent = 'Tap Check-In again to continue without a guest';
                            return;
                        }
                        proceedWithCheckin();
                        return;
                    }

                    lastCheckedIdentifier = identifier;
                    fetch(`checkin.php?action=guest_status&identifier=${encodeURIComponent(identifier)}`)
                        .then(res => res.json())
                        .then(data => {
                            applyGuestPassInfo(data.allowance || 0, data.remaining || 0);
                            if ((data.remaining || 0) > 0 && guestSection.style.display !== 'block') {
                                submitBtn.textContent = 'Tap Check-In again to continue without a guest';
                            } else {
                                proceedWithCheckin();
                            }
                        })
                        .catch(() => proceedWithCheckin());
                    return;
                }

                proceedWithCheckin();
            });

            // Guest pass section: lets a member add one or more guest names before checking in.
            const guestPassStatus = document.getElementById('guest-pass-status');
            const guestToggleBtn = document.getElementById('guest-toggle-btn');
            const guestSection = document.getElementById('guest-section');
            const guestNameFields = document.getElementById('guest-name-fields');
            const addGuestBtn = document.getElementById('add-guest-btn');

            const isLoggedIn = <?php echo $isLoggedIn ? 'true' : 'false'; ?>;
            let autoSubmitCancelled = false;
            let currentGuestRemaining = 0;
            let lastCheckedIdentifier = null;
            let guestPromptAcknowledged = false;

            function addGuestField() {
                const input = document.createElement('input');
                input.type = 'text';
                input.className = 'guest-name-input';
                input.placeholder = "Guest's name";
                input.autocomplete = 'off';
                input.style.marginBottom = '8px';
                guestNameFields.appendChild(input);
                return input;
            }

            // The "+ Add another guest" button can't add more fields than there are passes left.
            function updateAddGuestBtnVisibility() {
                addGuestBtn.style.display = guestNameFields.children.length < currentGuestRemaining ? '' : 'none';
            }

            // Shows/hides the guest pass UI based on the member's allowance for their plan.
            // allowance === 0 means guest passes don't apply to this member at all -- hide everything,
            // rather than telling them "0 available" for a plan that was never meant to have any.
            function applyGuestPassInfo(allowance, remaining) {
                currentGuestRemaining = remaining > 0 ? remaining : 0;

                if (allowance <= 0) {
                    guestPassArea.style.display = 'none';
                    guestSection.style.display = 'none';
                    guestNameFields.innerHTML = '';
                    return;
                }
                guestPassArea.style.display = 'block';
                if (remaining > 0) {
                    guestPassStatus.textContent = remaining + ' of ' + allowance + ' guest pass' + (allowance === 1 ? '' : 'es') + ' available this month';
                    guestToggleBtn.style.display = '';
                    // If a fresh lookup lowered the cap below what's already been entered, trim it.
                    while (guestNameFields.children.length > currentGuestRemaining) {
                        guestNameFields.removeChild(guestNameFields.lastElementChild);
                    }
                    updateAddGuestBtnVisibility();
                } else {
                    guestPassStatus.textContent = 'No guest passes remaining this month.';
                    guestToggleBtn.style.display = 'none';
                    guestSection.style.display = 'none';
                    guestNameFields.innerHTML = '';
                }
            }

            guestToggleBtn.addEventListener('click', () => {
                const opening = guestSection.style.display === 'none';
                guestSection.style.display = opening ? 'block' : 'none';
                if (opening) {
                    autoSubmitCancelled = true;
                    submitBtn.textContent = 'Check-In';
                    if (guestNameFields.children.length === 0) {
                        addGuestField();
                    }
                    updateAddGuestBtnVisibility();
                }
            });

            addGuestBtn.addEventListener('click', () => {
                if (guestNameFields.children.length >= currentGuestRemaining) {
                    return;
                }
                addGuestField();
                updateAddGuestBtnVisibility();
            });

            if (isLoggedIn) {
                // We already know who's logged in, so render their guest pass status immediately.
                const initialAllowance = <?php echo (int)$guestPassInfo['allowance']; ?>;
                const initialRemaining = <?php echo (int)$guestPassInfo['remaining']; ?>;
                applyGuestPassInfo(initialAllowance, initialRemaining);

                // Don't auto-submit if they have a guest pass available this visit -- let them
                // choose whether to bring a guest. Otherwise auto-submit quickly as before, since
                // there's no decision to make.
                if (initialRemaining > 0) {
                    autoSubmitCancelled = true;
                } else {
                    setTimeout(() => {
                        if (!autoSubmitCancelled) {
                            submitBtn.click();
                        }
                    }, 300);
                }
            } else {
                // Unauthenticated kiosk flow: look up guest pass status as they type, so it's ready
                // to display well before they tap Check-In (the submit handler double-checks this
                // anyway, in case they type and tap faster than this lookup can keep up).
                let guestStatusTimeout = null;
                inputField.addEventListener('input', () => {
                    clearTimeout(guestStatusTimeout);
                    guestPromptAcknowledged = false;
                    submitBtn.textContent = 'Check-In';
                    const identifier = inputField.value.trim();
                    if (identifier.length < 3) {
                        applyGuestPassInfo(0, 0);
                        lastCheckedIdentifier = null;
                        return;
                    }
                    guestStatusTimeout = setTimeout(() => {
                        lastCheckedIdentifier = identifier;
                        fetch(`checkin.php?action=guest_status&identifier=${encodeURIComponent(identifier)}`)
                            .then(res => res.json())
                            .then(data => applyGuestPassInfo(data.allowance || 0, data.remaining || 0))
                            .catch(() => applyGuestPassInfo(0, 0));
                    }, 400);
                });
            }

            function getGuestNames() {
                return Array.from(document.querySelectorAll('.guest-name-input'))
                    .map(el => el.value.trim())
                    .filter(name => name !== '');
            }

            function submitCheckin(lat = null, lon = null) {
                const data = new URLSearchParams();
                data.append('identifier', inputField.value);
                data.append('ajax', '1');
                getGuestNames().forEach(name => data.append('guest_names[]', name));
                if (lat !== null && lon !== null) {
                    data.append('latitude', lat);
                    data.append('longitude', lon);
                }

                fetch('checkin.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: data
                })
                .then(response => response.json().then(json => ({ status: response.status, body: json })))
                .then(res => {
                    if (res.body.redirect) {
                        window.location.href = res.body.redirect;
                        return;
                    }
                    if (!isLoggedIn) {
                        inputField.value = ''; // Clear for next member
                        applyGuestPassInfo(0, 0); // Hide guest pass status until the next member types in
                        lastCheckedIdentifier = null;
                        guestPromptAcknowledged = false;
                    }
                    // Reset guest section for the next check-in
                    guestNameFields.innerHTML = '';
                    guestSection.style.display = 'none';
                    autoSubmitCancelled = false;
                    resetButtonState();
                    if (res.status === 200 && res.body.success) {
                        playAudio(true);
                        renderFeedback(true, res.body.message, res.body.details);
                    } else {
                        playAudio(false);
                        renderFeedback(false, res.body.error || 'Failed to check in.');
                    }
                })
                .catch(err => {
                    resetButtonState();
                    playAudio(false);
                    renderFeedback(false, 'Connection error. Please try again.');
                });
            }

            function escapeHtml(str) {
                if (!str) return '';
                return String(str)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            }

            function renderFeedback(success, message, details = null) {
                feedbackArea.innerHTML = '';
                const alertDiv = document.createElement('div');
                alertDiv.className = `alert alert-${success ? 'success' : 'danger'} terminal-alert animate-pop`;
                
                let detailString = '';
                if (details) {
                    detailString = `<span class="subtext">Membership Type: ${escapeHtml(details.membership)} | Expiration: ${escapeHtml(details.expires)}</span>`;
                }

                alertDiv.innerHTML = `
                    <span class="alert-icon">${success ? '✔️' : '❌'}</span>
                    <div class="alert-text">
                        <strong>${success ? 'Welcome!' : 'Access Denied'}</strong>
                        <p>${escapeHtml(message)}</p>
                        ${detailString}
                    </div>
                `;
                feedbackArea.appendChild(alertDiv);

                // Auto clear check-in result after 5 seconds to reset terminal
                setTimeout(() => {
                    alertDiv.style.opacity = '0';
                    alertDiv.style.transform = 'translateY(-10px)';
                    alertDiv.style.transition = 'all 0.5s ease';
                    setTimeout(() => alertDiv.remove(), 500);
                }, 5000);
            }

            // Web Audio API Synthesizer for checkin chimes (Green success beep / Red fail beep)
            function playAudio(success) {
                try {
                    const ctx = new (window.AudioContext || window.webkitAudioContext)();
                    const osc = ctx.createOscillator();
                    const gain = ctx.createGain();
                    
                    osc.connect(gain);
                    gain.connect(ctx.destination);
                    
                    if (success) {
                        // Success chime: high double-beep
                        osc.frequency.setValueAtTime(660, ctx.currentTime); // Mi
                        gain.gain.setValueAtTime(0.1, ctx.currentTime);
                        osc.start();
                        
                        setTimeout(() => {
                            osc.frequency.setValueAtTime(880, ctx.currentTime); // La
                        }, 100);
                        
                        setTimeout(() => {
                            osc.stop();
                        }, 250);
                    } else {
                        // Failure chime: low single buzz
                        osc.type = 'sawtooth';
                        osc.frequency.setValueAtTime(150, ctx.currentTime);
                        gain.gain.setValueAtTime(0.1, ctx.currentTime);
                        osc.start();
                        
                        setTimeout(() => {
                            osc.stop();
                        }, 400);
                    }
                } catch (e) {
                    console.log("Audio not supported or blocked by browser gesture.");
                }
            }
        });
    </script>

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
