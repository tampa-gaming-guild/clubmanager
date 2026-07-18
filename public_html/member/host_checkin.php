<?php
/**
 * Host Check-In Page
 * Allows hosts and admins to search by name and check in other members.
 */
require_once dirname(dirname(__DIR__)) . '/config/bootstrap.php';

use App\Auth;
use App\Database;
use App\MembershipService;
use App\Event;
use App\BillingHelper;

// Enforce permission
Auth::requirePermission('edit checkins');

// Handle AJAX Search by Name
if (isset($_GET['action']) && $_GET['action'] === 'search') {
    $q = trim($_GET['q'] ?? '');
    if (strlen($q) >= 2) {
        try {
            $appDb = Database::getAppConnection();
            $qPhone = normalize_phone($q);
            $sql = "
                SELECT id, display_name, email, phone
                FROM tgg_contacts
                WHERE (display_name LIKE :q1 OR email LIKE :q2" . ($qPhone !== '' ? " OR REGEXP_REPLACE(phone, '[^0-9]', '') LIKE :q3" : "") . ")
                  AND is_deleted = 0
                LIMIT 15
            ";
            $stmt = $appDb->prepare($sql);
            $params = ['q1' => '%' . $q . '%', 'q2' => '%' . $q . '%'];
            if ($qPhone !== '') $params['q3'] = '%' . $qPhone . '%';
            $stmt->execute($params);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            json_response($results);
        } catch (Exception $e) {
            json_response(['error' => safe_err('Search failed: ', $e)], 500);
        }
    } else {
        json_response([]);
    }
}

// Handle AJAX guest-pass lookup for the selected member, shown in the guest confirmation panel
if (isset($_GET['action']) && $_GET['action'] === 'guest_status') {
    $contactId = (int)($_GET['contact_id'] ?? 0);
    $result = ['allowance' => 0, 'used' => 0, 'remaining' => 0];
    if ($contactId > 0) {
        try {
            $membership = MembershipService::getMemberMembershipDetails($contactId);
            if ($membership && $membership['is_active']) {
                $result = BillingHelper::getGuestPassesRemaining($contactId, $membership);
            }
        } catch (Exception $e) {
            // Fall through with the default zeroed result.
        }
    }
    json_response($result);
}

$errorMsg = null;
$successMsg = null;
$memberDetails = null;
$redirectUrl = null;
$needsTrialConfirmation = false;

// Host check-in is always authenticated; geolocation check is not required
$isGeoEnabled = false;

// Distance helper
if (!function_exists('get_distance_meters')) {
    function get_distance_meters($lat1, $lon1, $lat2, $lon2) {
        $earthRadius = 6371000;
        $latFrom = deg2rad($lat1);
        $lonFrom = deg2rad($lon1);
        $latTo = deg2rad($lat2);
        $lonTo = deg2rad($lon2);
        $latDelta = $latTo - $latFrom;
        $lonDelta = $lonTo - $lonFrom;
        $angle = 2 * asin(sqrt(pow(sin($latDelta / 2), 2) + cos($latFrom) * cos($latTo) * pow(sin($lonDelta / 2), 2)));
        return $angle * $earthRadius;
    }
}

// Handle Check-In Request (Standard POST or AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $contactId = (int)($_POST['contact_id'] ?? 0);
    $notes = trim($_POST['notes'] ?? 'Checked in by Host');
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

    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errorMsg = "Invalid security token. Please refresh the page and try again.";
        if ($isAjax) {
            json_response(['success' => false, 'error' => $errorMsg], 403);
        }
    } else if ($contactId <= 0) {
        $errorMsg = "Invalid member selected.";
    } else {
        try {
            $appDb = Database::getAppConnection();
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
            
            if ($geoValid) {
                // Session Check (same rule as the standard member checkin.php)
                $activeSession = Event::getActiveSession();
                $sessionOpen = $activeSession !== null;
                
                if (!$sessionOpen) {
                    $errorMsg = "Check-in Denied: There is no session open for check-in right now.";
                } else {
                    // Load contact details
                    $contactStmt = $appDb->prepare("SELECT display_name FROM tgg_contacts WHERE id = :id AND is_deleted = 0 LIMIT 1");
                    $contactStmt->execute(['id' => $contactId]);
                    $contactName = $contactStmt->fetchColumn();
                    
                    if (!$contactName) {
                        $errorMsg = "Member not found.";
                    } else {
                        // Double check-in prevention (guest visits don't count toward this)
                        $dupCheck = $appDb->prepare("SELECT COUNT(*) FROM tgg_checkins WHERE contact_id = :contact_id AND guest_name IS NULL AND DATE(checked_in_at) = CURDATE()");
                        $dupCheck->execute(['contact_id' => $contactId]);
                        $hasCheckedInToday = (int)$dupCheck->fetchColumn() > 0;

                        if ($hasCheckedInToday && empty($guestNames)) {
                            $errorMsg = "Check-in Denied: {$contactName} has already checked in today.";
                        } else {
                            // Active membership verification
                            $membership = MembershipService::getMemberMembershipDetails($contactId);
                            $trialActivationNote = '';
                            $needsTrialConfirmation = false;

                            if (!$membership || !$membership['is_active']) {
                                $pendingTrialPlanId = BillingHelper::getPendingTrialPlanId($contactId);

                                if ($pendingTrialPlanId && !empty($_POST['confirm_trial_activation'])) {
                                    // Host explicitly confirmed activating this member's pending online
                                    // Trial registration in person -- checking them in now satisfies the
                                    // verification an emailed link would have, so activate instead of
                                    // diverting to payment (pay-entrance.php's renewal picker doesn't even
                                    // offer the Trial plan, since it's a one-time non-renewable offer).
                                    BillingHelper::activatePendingTrialInPerson($contactId, $_SESSION['user']['contact_id'] ?? null);
                                    $trialActivationNote = ' Their Trial membership was activated.';
                                    $membership = MembershipService::getMemberMembershipDetails($contactId);
                                } elseif ($pendingTrialPlanId) {
                                    // Ask the host to explicitly confirm before activating anything.
                                    $needsTrialConfirmation = true;
                                }
                            }

                            if ($needsTrialConfirmation) {
                                // handled in the AJAX/redirect response section below
                            } elseif (!$membership || !$membership['is_active']) {
                                // Expired/inactive membership: send to renew (Card or Cash) instead of a flat denial.
                                $redirectUrl = 'pay-entrance.php?contact_id=' . $contactId . '&reason=renewal&return=host_checkin.php';
                            } elseif (BillingHelper::entranceFeeOwed($membership)) {
                                // Session-plan member's 2nd+ check-in since their last dues payment: pay the entrance fee first.
                                $redirectUrl = 'pay-entrance.php?contact_id=' . $contactId . '&reason=entrance_fee&return=host_checkin.php';
                            } else {
                                // Enforce the monthly guest pass allowance before logging anything
                                $guestLimitExceeded = false;
                                if (!empty($guestNames)) {
                                    $passes = BillingHelper::getGuestPassesRemaining($contactId, $membership);
                                    if (count($guestNames) > $passes['remaining']) {
                                        $guestLimitExceeded = true;
                                        $errorMsg = "Only {$passes['remaining']} guest pass(es) remaining this month for {$contactName}.";
                                    }
                                }

                                if (!$guestLimitExceeded) {
                                    // Log the check-in (and any guests)
                                    if (!$hasCheckedInToday) {
                                        $insert = $appDb->prepare("INSERT INTO tgg_checkins (contact_id, checked_in_at, notes) VALUES (:contact_id, NOW(), :notes)");
                                        $insert->execute([
                                            'contact_id' => $contactId,
                                            'notes' => $notes
                                        ]);
                                    }

                                    if (!empty($guestNames)) {
                                        $insertGuest = $appDb->prepare("INSERT INTO tgg_checkins (contact_id, checked_in_at, guest_name) VALUES (:contact_id, NOW(), :guest_name)");
                                        foreach ($guestNames as $guestName) {
                                            $insertGuest->execute([
                                                'contact_id' => $contactId,
                                                'guest_name' => $guestName
                                            ]);
                                        }
                                    }

                                    $successMsg = "Check-In Successful! Welcome, {$contactName}." . $trialActivationNote;
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
            $errorMsg = safe_err("Check-in error: ", $e);
        }
    }
    
    if ($isAjax) {
        if ($redirectUrl) {
            json_response(['success' => false, 'redirect' => $redirectUrl], 200);
        } elseif ($needsTrialConfirmation) {
            json_response(['success' => false, 'needs_trial_confirmation' => true, 'contact_name' => $contactName], 200);
        } elseif ($errorMsg) {
            json_response(['success' => false, 'error' => $errorMsg], 400);
        } else {
            json_response(['success' => true, 'message' => $successMsg, 'details' => $memberDetails], 200);
        }
    } elseif ($redirectUrl) {
        header("Location: {$redirectUrl}");
        exit;
    } elseif ($needsTrialConfirmation) {
        // host_checkin.php's UI is entirely JS/AJAX-driven (no plain <form> submits this
        // page), so this is a defensive fallback only.
        $errorMsg = "{$contactName}'s Trial registration is awaiting email verification. Use the Check-In panel to confirm activating it in person.";
    }
}

// Check if a specific contact is requested via GET
$preloadedContact = null;
if (isset($_GET['contact_id'])) {
    try {
        $appDb = Database::getAppConnection();
        $stmt = $appDb->prepare("SELECT id, display_name, email, phone FROM tgg_contacts WHERE id = :id AND is_deleted = 0 LIMIT 1");
        $stmt->execute(['id' => $_GET['contact_id']]);
        $preloadedContact = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // Silent fail
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Host Check-In Terminal - Club Entry</title>
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <link rel="icon" type="image/png" href="favicon.png">
    <link rel="stylesheet" href="assets/css/style.css<?php echo asset_version('assets/css/style.css'); ?>">
    <style>
        .search-results-container {
            margin-top: 20px;
            max-height: 250px;
            overflow-y: auto;
            border-radius: 6px;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        .search-result-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 15px;
            background: rgba(255, 255, 255, 0.03);
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            transition: background 0.2s ease;
        }
        .search-result-item:last-child {
            border-bottom: none;
        }
        .search-result-item:hover {
            background: rgba(255, 255, 255, 0.07);
        }
        .search-result-info {
            display: flex;
            flex-direction: column;
            gap: 3px;
        }
        .search-result-name {
            font-weight: 600;
            color: #fff;
        }
        .search-result-meta {
            font-size: 0.8rem;
            color: var(--color-text-secondary);
        }
    </style>
</head>
<body>
    <div class="app-container">
        <?php $navActive = 'dashboard'; include __DIR__ . '/partials/navbar.php'; ?>

        <main class="main-content centered-content">
            <div class="terminal-panel glass-panel" style="max-width: 600px; width: 100%;">
                <div class="terminal-header">
                    <h2>Host Check-In Terminal</h2>
                    <p class="subtitle">Search for a member by name to check them in.</p>
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

                <div class="terminal-form">
                    <div class="form-group large-input">
                        <label for="search-input">Search Member</label>
                        <input type="text" id="search-input" autocomplete="off"
                               placeholder="Type name, email, or phone…"
                               value="<?php echo $preloadedContact ? e($preloadedContact['display_name']) : ''; ?>"
                               autofocus>
                    </div>

                    <div id="search-results-list" class="search-results-container" style="display: none;">
                        <!-- Live Search Results Go Here -->
                    </div>

                    <div id="guest-confirm-panel" class="glass-panel" style="display: none; margin-top: 15px; padding: 15px;">
                        <p style="margin: 0 0 10px 0;">Checking in <strong id="guest-confirm-name"></strong></p>
                        <p id="guest-confirm-status" class="subtext" style="margin: 0 0 8px 0; display: none;"></p>
                        <p id="guest-confirm-trial-notice" class="subtext" style="margin: 0 0 8px 0; display: none; color: var(--color-warning, #e0a030);"></p>
                        <button type="button" id="guest-confirm-toggle-btn" class="btn btn-secondary btn-block" style="display: none;">Add guest(s)?</button>
                        <div id="guest-confirm-section" style="display: none; margin-top: 12px;">
                            <div id="guest-confirm-fields"></div>
                            <button type="button" id="guest-confirm-add-btn" class="btn btn-secondary btn-small" style="margin-top: 8px;">+ Add another guest</button>
                        </div>
                        <div style="display: flex; gap: 10px; margin-top: 15px;">
                            <button type="button" id="guest-confirm-submit-btn" class="btn btn-primary" style="flex: 1;">Confirm Check-In</button>
                            <button type="button" id="guest-confirm-cancel-btn" class="btn btn-secondary">Cancel</button>
                        </div>
                    </div>
                </div>

                <div class="terminal-footer" style="margin-top: 30px;">
                    <a href="index.php" style="color: var(--color-primary); text-decoration: none; font-weight: 600;">← Back to Dashboard</a>
                </div>
            </div>
        </main>

        <?php include __DIR__ . '/partials/footer.php'; ?>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const searchInput = document.getElementById('search-input');
            const resultsList = document.getElementById('search-results-list');
            const isGeoEnabled = <?php echo $isGeoEnabled ? 'true' : 'false'; ?>;
            const csrfToken = <?php echo json_encode(get_csrf_token()); ?>;
            const feedbackArea = document.getElementById('feedback-area');
            
            let searchTimeout = null;
            
            // Check if there is preloaded contact
            const preloadedId = <?php echo $preloadedContact ? (int)$preloadedContact['id'] : 'null'; ?>;
            const preloadedName = <?php echo $preloadedContact ? json_encode($preloadedContact['display_name']) : 'null'; ?>;
            const preloadedEmail = <?php echo $preloadedContact ? json_encode($preloadedContact['email']) : 'null'; ?>;
            const preloadedPhone = <?php echo $preloadedContact ? json_encode($preloadedContact['phone'] ?? '') : 'null'; ?>;

            if (preloadedId && preloadedName) {
                renderResults([{ id: preloadedId, display_name: preloadedName, email: preloadedEmail, phone: preloadedPhone }]);
            }

            // Live Search input handler
            searchInput.addEventListener('input', () => {
                clearTimeout(searchTimeout);
                const query = searchInput.value.trim();
                
                if (query.length < 2) {
                    resultsList.style.display = 'none';
                    resultsList.innerHTML = '';
                    return;
                }
                
                searchTimeout = setTimeout(() => {
                    fetch(`host_checkin.php?action=search&q=${encodeURIComponent(query)}`)
                        .then(res => res.json())
                        .then(data => {
                            renderResults(data);
                        })
                        .catch(err => {
                            console.error('Search error:', err);
                        });
                }, 250);
            });

            function renderResults(members) {
                resultsList.innerHTML = '';
                if (!members || members.length === 0) {
                    resultsList.innerHTML = '<div style="padding: 15px; text-align: center; color: var(--color-text-secondary); font-size: 0.9rem;">No members found.</div>';
                    resultsList.style.display = 'block';
                    return;
                }
                
                members.forEach(member => {
                    const item = document.createElement('div');
                    item.className = 'search-result-item';
                    item.innerHTML = `
                        <div class="search-result-info">
                            <span class="search-result-name">${escapeHtml(member.display_name)}</span>
                            <span class="search-result-meta">ID: ${member.id} | ${escapeHtml(member.email)}${member.phone ? ' | ' + formatPhone(member.phone) : ''}</span>
                        </div>
                        <div style="display: flex; gap: 6px;">
                            <button type="button" class="btn btn-primary btn-small checkin-btn" data-id="${member.id}" style="padding: 8px 14px; font-size: 0.85rem; border-radius: 4px; font-weight: 600; cursor: pointer;">Check In</button>
                            <a href="profile.php?id=${encodeURIComponent(member.id)}" class="btn btn-secondary btn-small" style="padding: 8px 14px; font-size: 0.85rem; border-radius: 4px; font-weight: 600; text-decoration: none;">Manage</a>
                        </div>
                    `;
                    resultsList.appendChild(item);
                });
                resultsList.style.display = 'block';
                
                // Add click handlers for check in buttons: open the guest confirmation panel
                // instead of checking in immediately, so a guest can be added first.
                resultsList.querySelectorAll('.checkin-btn').forEach(btn => {
                    btn.addEventListener('click', (e) => {
                        const memberId = e.target.getAttribute('data-id');
                        const member = members.find(m => String(m.id) === String(memberId));
                        openGuestConfirmPanel(memberId, member ? member.display_name : '');
                    });
                });
            }

            // Guest confirmation panel: shown after selecting a member, before the check-in is submitted.
            const guestConfirmPanel = document.getElementById('guest-confirm-panel');
            const guestConfirmName = document.getElementById('guest-confirm-name');
            const guestConfirmStatus = document.getElementById('guest-confirm-status');
            const guestConfirmTrialNotice = document.getElementById('guest-confirm-trial-notice');
            const guestConfirmToggleBtn = document.getElementById('guest-confirm-toggle-btn');
            const guestConfirmSection = document.getElementById('guest-confirm-section');
            const guestConfirmFields = document.getElementById('guest-confirm-fields');
            const guestConfirmAddBtn = document.getElementById('guest-confirm-add-btn');
            const guestConfirmSubmitBtn = document.getElementById('guest-confirm-submit-btn');
            const guestConfirmCancelBtn = document.getElementById('guest-confirm-cancel-btn');
            let selectedMemberId = null;
            let currentGuestRemaining = 0;
            let guestPromptAcknowledged = false;
            let guestStatusPromise = Promise.resolve({ allowance: 0, remaining: 0 });
            // Set once the server reports this member has a pending online Trial registration
            // awaiting email verification -- the host must tap the (relabeled) submit button a
            // second time to explicitly confirm activating it in person.
            let pendingTrialConfirm = false;

            function addGuestConfirmField() {
                const input = document.createElement('input');
                input.type = 'text';
                input.className = 'guest-confirm-name-input';
                input.placeholder = "Guest's name";
                input.autocomplete = 'off';
                input.style.marginBottom = '8px';
                guestConfirmFields.appendChild(input);
                return input;
            }

            // "+ Add another guest" can't add more fields than there are passes left.
            function updateGuestConfirmAddBtnVisibility() {
                guestConfirmAddBtn.style.display = guestConfirmFields.children.length < currentGuestRemaining ? '' : 'none';
            }

            // Shows/hides the guest pass UI based on this member's allowance for their plan.
            // allowance === 0 means guest passes don't apply to them at all -- hide everything.
            function applyGuestConfirmPassInfo(allowance, remaining) {
                currentGuestRemaining = remaining > 0 ? remaining : 0;

                if (allowance <= 0) {
                    guestConfirmStatus.style.display = 'none';
                    guestConfirmToggleBtn.style.display = 'none';
                    guestConfirmSection.style.display = 'none';
                    guestConfirmFields.innerHTML = '';
                    return;
                }
                guestConfirmStatus.style.display = 'block';
                if (remaining > 0) {
                    guestConfirmStatus.textContent = remaining + ' of ' + allowance + ' guest pass' + (allowance === 1 ? '' : 'es') + ' available this month';
                    guestConfirmToggleBtn.style.display = '';
                    while (guestConfirmFields.children.length > currentGuestRemaining) {
                        guestConfirmFields.removeChild(guestConfirmFields.lastElementChild);
                    }
                    updateGuestConfirmAddBtnVisibility();
                } else {
                    guestConfirmStatus.textContent = 'No guest passes remaining this month.';
                    guestConfirmToggleBtn.style.display = 'none';
                    guestConfirmSection.style.display = 'none';
                    guestConfirmFields.innerHTML = '';
                }
            }

            function openGuestConfirmPanel(memberId, memberName) {
                selectedMemberId = memberId;
                guestPromptAcknowledged = false;
                pendingTrialConfirm = false;
                guestConfirmSubmitBtn.textContent = 'Confirm Check-In';
                guestConfirmName.textContent = memberName;
                guestConfirmFields.innerHTML = '';
                guestConfirmSection.style.display = 'none';
                guestConfirmStatus.style.display = 'none';
                guestConfirmTrialNotice.style.display = 'none';
                guestConfirmToggleBtn.style.display = 'none';
                guestConfirmPanel.style.display = 'block';
                resultsList.style.display = 'none';

                // Look up this member's guest-pass status as soon as the panel opens. The submit
                // button awaits this same promise, so even tapping Confirm immediately can't outrun it.
                guestStatusPromise = fetch(`host_checkin.php?action=guest_status&contact_id=${encodeURIComponent(memberId)}`)
                    .then(res => res.json())
                    .then(data => {
                        const info = { allowance: data.allowance || 0, remaining: data.remaining || 0 };
                        applyGuestConfirmPassInfo(info.allowance, info.remaining);
                        return info;
                    })
                    .catch(() => {
                        applyGuestConfirmPassInfo(0, 0);
                        return { allowance: 0, remaining: 0 };
                    });
            }

            guestConfirmToggleBtn.addEventListener('click', () => {
                const opening = guestConfirmSection.style.display === 'none';
                guestConfirmSection.style.display = opening ? 'block' : 'none';
                if (opening) {
                    guestConfirmSubmitBtn.textContent = 'Confirm Check-In';
                    if (guestConfirmFields.children.length === 0) {
                        addGuestConfirmField();
                    }
                    updateGuestConfirmAddBtnVisibility();
                }
            });

            guestConfirmAddBtn.addEventListener('click', () => {
                if (guestConfirmFields.children.length >= currentGuestRemaining) {
                    return;
                }
                addGuestConfirmField();
                updateGuestConfirmAddBtnVisibility();
            });

            guestConfirmCancelBtn.addEventListener('click', () => {
                selectedMemberId = null;
                pendingTrialConfirm = false;
                guestConfirmPanel.style.display = 'none';
            });

            guestConfirmSubmitBtn.addEventListener('click', () => {
                if (!selectedMemberId) {
                    return;
                }

                // First tap: make sure we know this member's guest-pass status (waiting on the
                // lookup if it's still in flight) before ever submitting. If they have a pass
                // available and haven't already opened "Add guest(s)?", pause once instead of
                // silently checking them in without the option.
                if (!guestPromptAcknowledged) {
                    guestPromptAcknowledged = true;
                    guestStatusPromise.then(info => {
                        if (info.remaining > 0 && guestConfirmSection.style.display !== 'block') {
                            guestConfirmSubmitBtn.textContent = 'Tap again to continue without a guest';
                        } else {
                            triggerCheckin(selectedMemberId, guestConfirmSubmitBtn);
                        }
                    });
                    return;
                }

                triggerCheckin(selectedMemberId, guestConfirmSubmitBtn);
            });

            function getGuestConfirmNames() {
                return Array.from(document.querySelectorAll('.guest-confirm-name-input'))
                    .map(el => el.value.trim())
                    .filter(name => name !== '');
            }

            function triggerCheckin(memberId, buttonElement) {
                const originalText = buttonElement.textContent;
                buttonElement.disabled = true;
                buttonElement.textContent = 'Processing...';

                if (isGeoEnabled) {
                    buttonElement.textContent = 'Locating...';
                    if (!navigator.geolocation) {
                        renderFeedback(false, 'Your browser does not support geolocation.');
                        buttonElement.disabled = false;
                        buttonElement.textContent = originalText;
                        return;
                    }

                    navigator.geolocation.getCurrentPosition(
                        (position) => {
                            const lat = position.coords.latitude;
                            const lon = position.coords.longitude;
                            submitCheckin(memberId, lat, lon, buttonElement, originalText);
                        },
                        (error) => {
                            let msg = 'Failed to acquire location.';
                            if (error.code === error.PERMISSION_DENIED) {
                                msg = 'Location access denied. You must allow location access to check in.';
                            }
                            renderFeedback(false, msg);
                            buttonElement.disabled = false;
                            buttonElement.textContent = originalText;
                        },
                        {
                            enableHighAccuracy: true,
                            timeout: 10000,
                            maximumAge: 0
                        }
                    );
                } else {
                    submitCheckin(memberId, null, null, buttonElement, originalText);
                }
            }

            function submitCheckin(memberId, lat, lon, buttonElement, originalText) {
                const data = new URLSearchParams();
                data.append('contact_id', memberId);
                data.append('ajax', '1');
                data.append('csrf_token', csrfToken);
                data.append('notes', 'Checked in by Host Override');
                if (pendingTrialConfirm) {
                    data.append('confirm_trial_activation', '1');
                }
                getGuestConfirmNames().forEach(name => data.append('guest_names[]', name));
                if (lat !== null && lon !== null) {
                    data.append('latitude', lat);
                    data.append('longitude', lon);
                }

                fetch('host_checkin.php', {
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

                    if (res.body.needs_trial_confirmation) {
                        // Don't close the panel or treat this as a failure -- ask the host to
                        // explicitly confirm activating the pending Trial before trying again.
                        pendingTrialConfirm = true;
                        buttonElement.disabled = false;
                        buttonElement.textContent = 'Activate Trial & Check In';
                        guestConfirmTrialNotice.textContent = "This member registered for a Trial online but hasn't verified their email yet. Tap again to activate their Trial and check them in.";
                        guestConfirmTrialNotice.style.display = 'block';
                        return;
                    }

                    buttonElement.disabled = false;
                    buttonElement.textContent = originalText;

                    // Clear search and guest confirmation panel
                    searchInput.value = '';
                    resultsList.style.display = 'none';
                    resultsList.innerHTML = '';
                    guestConfirmPanel.style.display = 'none';
                    selectedMemberId = null;

                    if (res.status === 200 && res.body.success) {
                        renderFeedback(true, res.body.message, res.body.details);
                    } else {
                        renderFeedback(false, res.body.error || 'Failed to check in.');
                    }
                })
                .catch(err => {
                    buttonElement.disabled = false;
                    buttonElement.textContent = originalText;
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

            function formatPhone(digits) {
                if (digits && digits.length === 10) {
                    return '(' + digits.slice(0, 3) + ') ' + digits.slice(3, 6) + '-' + digits.slice(6);
                }
                return digits || '';
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
                
                setTimeout(() => {
                    alertDiv.style.opacity = '0';
                    alertDiv.style.transform = 'translateY(-10px)';
                    alertDiv.style.transition = 'all 0.5s ease';
                    setTimeout(() => alertDiv.remove(), 500);
                }, 8000);
            }
        });
    </script>
</body>
</html>
