<?php
/**
 * Member Check-In Portal
 * Simple check-in terminal for members to record their visit by email or Contact ID.
 * Supports standard page POSTs and AJAX requests.
 */
require_once dirname(dirname(__DIR__)) . '/config/bootstrap.php';

use App\Database;
use App\CiviCRMImporter;

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

// Determine if geolocation check is required for the current user
$isGeoEnabled = ($_ENV['GEOLOCATION_CHECK_ENABLED'] ?? 'false') === 'true' && !has_role('superadmin');

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
    $isAjax = isset($_POST['ajax']) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest');

    if (empty($identifier)) {
        $errorMsg = "Please enter your Email or Member ID.";
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

            if ($geoValid) {
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
                } else if (is_numeric($identifier)) {
                    // Resolved via Contact ID
                    $contactId = (int)$identifier;
                }

                if ($contactId <= 0) {
                    $errorMsg = "Member not found. Please check your Email or Member ID.";
                } else {
                    // 2. Fetch Contact Name and Expiry Date
                    $contactStmt = $appDb->prepare("SELECT display_name FROM tgg_contacts WHERE id = :id AND is_deleted = 0 LIMIT 1");
                    $contactStmt->execute(['id' => $contactId]);
                    $contactRow = $contactStmt->fetch();

                    if (!$contactRow) {
                        $errorMsg = "Member not found in database.";
                    } else {
                        $contactName = CiviCRMImporter::getFormattedName($contactId);

                        // 2b. Prevent double check-in on the same day
                        $dupCheckStmt = $appDb->prepare("SELECT COUNT(*) FROM tgg_checkins WHERE contact_id = :contact_id AND DATE(checked_in_at) = CURDATE()");
                        $dupCheckStmt->execute(['contact_id' => $contactId]);
                        $hasCheckedInToday = (int)$dupCheckStmt->fetchColumn() > 0;

                        if ($hasCheckedInToday) {
                            $errorMsg = "Check-in Denied: {$contactName} has already checked in today.";
                        } else {
                            // 3. Verify Active Membership
                            $membership = CiviCRMImporter::getMemberMembershipDetails($contactId);

                            if (!$membership || !$membership['is_active']) {
                                $errorMsg = "Check-in Denied: Membership is currently expired or inactive for {$contactName}.";
                            } else {
                                // 4. Log the check-in
                                $insertStmt = $appDb->prepare("INSERT INTO tgg_checkins (contact_id, checked_in_at, notes) VALUES (:contact_id, NOW(), :notes)");
                                $insertStmt->execute([
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
            $errorMsg = safe_err("Check-in system error: ", $e);
        }
    }

    // Return AJAX Response
    if ($isAjax) {
        if ($errorMsg) {
            json_response(['success' => false, 'error' => $errorMsg], 400);
        } else {
            json_response([
                'success' => true,
                'message' => $successMsg,
                'details' => $memberDetails
            ], 200);
        }
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
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="terminal-body">
    <div class="app-container">
        <header class="navbar">
            <div class="logo">TGG Members</div>
            <?php if (has_role('admin')): ?>
                <form action="<?php echo rtrim($_ENV['BASE_URL'] ?? 'http://localhost/member', '/') . '/admin/dashboard.php'; ?>" method="GET" class="navbar-search-form" style="margin: 0 20px; flex-grow: 1; max-width: 380px; position: relative;">
                    <input type="text" name="search" placeholder="Search members by name..." 
                        value="<?php echo isset($_GET['search']) ? e($_GET['search']) : ''; ?>"
                        style="width: 100%; padding: 8px 15px 8px 35px; background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 20px; color: #fff; font-size: 0.85rem; outline: none; transition: all 0.2s ease;">
                    <span style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: rgba(255, 255, 255, 0.4); font-size: 0.9rem;">🔍</span>
                </form>
            <?php endif; ?>
            <nav class="nav-links">
                <a href="index.php">Portal Hub</a>
                <a href="calendar.php">Calendar</a>
                <a href="volunteers.php">Volunteers</a>
                <a href="checkin.php" class="active">Check-In Portal</a>
                <?php if (has_permission('edit checkins')): ?>
                    <a href="admin/checkins.php">Check-In List</a>
                <?php endif; ?>
            </nav>
        </header>

        <main class="main-content centered-content">
            <div class="terminal-panel glass-panel">
                <div class="terminal-header">
                    <h2>Club Entry Check-In</h2>
                    <p class="subtitle">Please scan your barcode, or enter your Email or Member ID to check in.</p>
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
                    <div class="form-group large-input">
                        <label for="identifier">Email Address or Member ID</label>
                        <input type="text" id="identifier" name="identifier" autocomplete="off" autocorrect="off" autocapitalize="none" spellcheck="false" required placeholder="Enter Email or ID..." autofocus>
                    </div>
                    <button type="submit" class="btn btn-primary btn-large btn-block">Check-In</button>
                </form>

                <div class="terminal-footer">
                    <p>Issues checking in? Please ask for the host.</p>
                </div>
            </div>
        </main>

        <footer class="app-footer">
            <p>&copy; <?php echo date('Y'); ?> TGG Club Entry Terminal. Auto-refreshing.</p>
        </footer>
    </div>

    <!-- Checkin sound effects and autofocus scripts -->
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const inputField = document.getElementById('identifier');
            
            // Keep input focused at all times for quick barcode scanner entry
            document.addEventListener('click', () => {
                inputField.focus();
            });

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

            form.addEventListener('submit', (e) => {
                e.preventDefault();
                
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
            });

            function submitCheckin(lat = null, lon = null) {
                const data = new URLSearchParams();
                data.append('identifier', inputField.value);
                data.append('ajax', '1');
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
                    inputField.value = ''; // Clear for next member
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
