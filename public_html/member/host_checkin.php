<?php
/**
 * Host Check-In Page
 * Allows hosts and admins to search by name and check in other members.
 */
require_once dirname(dirname(__DIR__)) . '/config/bootstrap.php';

use App\Auth;
use App\Database;
use App\CiviCRMImporter;

// Enforce permission
Auth::requirePermission('edit checkins');

// Handle AJAX Search by Name
if (isset($_GET['action']) && $_GET['action'] === 'search') {
    $q = trim($_GET['q'] ?? '');
    if (strlen($q) >= 2) {
        try {
            $appDb = Database::getAppConnection();
            $stmt = $appDb->prepare("
                SELECT id, display_name, email 
                FROM tgg_contacts 
                WHERE (display_name LIKE :q1 OR email LIKE :q2) 
                  AND is_deleted = 0 
                LIMIT 15
            ");
            $stmt->execute([
                'q1' => '%' . $q . '%',
                'q2' => '%' . $q . '%'
            ]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            json_response($results);
        } catch (Exception $e) {
            json_response(['error' => $e->getMessage()], 500);
        }
    } else {
        json_response([]);
    }
}

$errorMsg = null;
$successMsg = null;
$memberDetails = null;

// Determine if geolocation check is required
$isGeoEnabled = ($_ENV['GEOLOCATION_CHECK_ENABLED'] ?? 'false') === 'true' && !has_role('superadmin');

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
    $isAjax = isset($_POST['ajax']) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest');
    
    if ($contactId <= 0) {
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
                // Session Check
                $sessionStmt = $appDb->prepare("
                    SELECT COUNT(*) FROM tgg_events
                    WHERE DATE(start_time) = CURDATE()
                      AND NOW() >= DATE_SUB(start_time, INTERVAL 1 HOUR)
                      AND NOW() <= end_time
                ");
                $sessionStmt->execute();
                $sessionOpen = (int)$sessionStmt->fetchColumn() > 0 || has_role('superadmin');
                
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
                        // Double check-in prevention
                        $dupCheck = $appDb->prepare("SELECT COUNT(*) FROM tgg_checkins WHERE contact_id = :contact_id AND DATE(checked_in_at) = CURDATE()");
                        $dupCheck->execute(['contact_id' => $contactId]);
                        if ((int)$dupCheck->fetchColumn() > 0) {
                            $errorMsg = "Check-in Denied: {$contactName} has already checked in today.";
                        } else {
                            // Active membership verification
                            $membership = CiviCRMImporter::getMemberMembershipDetails($contactId);
                            if (!$membership || !$membership['is_active']) {
                                $errorMsg = "Check-in Denied: Membership is currently expired or inactive for {$contactName}.";
                            } else {
                                // Log the check-in
                                $insert = $appDb->prepare("INSERT INTO tgg_checkins (contact_id, checked_in_at, notes) VALUES (:contact_id, NOW(), :notes)");
                                $insert->execute([
                                    'contact_id' => $contactId,
                                    'notes' => $notes
                                ]);
                                
                                $successMsg = "Check-In Successful! Welcome, {$contactName}.";
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
        } catch (Exception $e) {
            $errorMsg = safe_err("Check-in error: ", $e);
        }
    }
    
    if ($isAjax) {
        if ($errorMsg) {
            json_response(['success' => false, 'error' => $errorMsg], 400);
        } else {
            json_response(['success' => true, 'message' => $successMsg, 'details' => $memberDetails], 200);
        }
    }
}

// Check if a specific contact is requested via GET
$preloadedContact = null;
if (isset($_GET['contact_id'])) {
    try {
        $appDb = Database::getAppConnection();
        $stmt = $appDb->prepare("SELECT id, display_name, email FROM tgg_contacts WHERE id = :id AND is_deleted = 0 LIMIT 1");
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
        <?php $navActive = 'host'; include __DIR__ . '/partials/navbar.php'; ?>

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
                        <label for="search-input">Search Member Name</label>
                        <input type="text" id="search-input" autocomplete="off" 
                               placeholder="Type member's name..." 
                               value="<?php echo $preloadedContact ? e($preloadedContact['display_name']) : ''; ?>"
                               autofocus>
                    </div>

                    <div id="search-results-list" class="search-results-container" style="display: none;">
                        <!-- Live Search Results Go Here -->
                    </div>
                </div>

                <div class="terminal-footer" style="margin-top: 30px;">
                    <a href="host.php" style="color: var(--color-primary); text-decoration: none; font-weight: 600;">← Back to Host Portal</a>
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
            const feedbackArea = document.getElementById('feedback-area');
            
            let searchTimeout = null;
            
            // Check if there is preloaded contact
            const preloadedId = <?php echo $preloadedContact ? (int)$preloadedContact['id'] : 'null'; ?>;
            const preloadedName = <?php echo $preloadedContact ? json_encode($preloadedContact['display_name']) : 'null'; ?>;
            const preloadedEmail = <?php echo $preloadedContact ? json_encode($preloadedContact['email']) : 'null'; ?>;

            if (preloadedId && preloadedName) {
                renderResults([{ id: preloadedId, display_name: preloadedName, email: preloadedEmail }]);
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
                            <span class="search-result-meta">ID: ${member.id} | ${escapeHtml(member.email)}</span>
                        </div>
                        <button type="button" class="btn btn-primary btn-small checkin-btn" data-id="${member.id}" style="padding: 8px 14px; font-size: 0.85rem; border-radius: 4px; font-weight: 600; cursor: pointer;">Check In</button>
                    `;
                    resultsList.appendChild(item);
                });
                resultsList.style.display = 'block';
                
                // Add click handlers for check in buttons
                resultsList.querySelectorAll('.checkin-btn').forEach(btn => {
                    btn.addEventListener('click', (e) => {
                        const memberId = e.target.getAttribute('data-id');
                        triggerCheckin(memberId, e.target);
                    });
                });
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
                data.append('notes', 'Checked in by Host Override');
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
                    buttonElement.disabled = false;
                    buttonElement.textContent = originalText;
                    
                    // Clear search
                    searchInput.value = '';
                    resultsList.style.display = 'none';
                    resultsList.innerHTML = '';
                    
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
