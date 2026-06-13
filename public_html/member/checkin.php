<?php
/**
 * Member Check-In Portal
 * Simple check-in terminal for members to record their visit by email or Contact ID.
 * Supports standard page POSTs and AJAX requests.
 */
require_once dirname(dirname(__DIR__)) . '/config/bootstrap.php';

use App\Database;
use App\CiviCRMImporter;

$errorMsg = null;
$successMsg = null;
$memberDetails = null;

// Handle Check-In POST (Standard & AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim($_POST['identifier'] ?? '');
    $notes = trim($_POST['notes'] ?? 'Regular Visit');
    $isAjax = isset($_POST['ajax']) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest');

    if (empty($identifier)) {
        $errorMsg = "Please enter your Email or Member ID.";
    } else {
        try {
            $civiDb = Database::getCiviConnection();
            $appDb = Database::getAppConnection();
            $contactId = 0;
            $contactName = '';

            // 1. Resolve Contact ID
            if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
                // Resolved via Email
                $stmt = $civiDb->prepare("SELECT contact_id FROM civicrm_email WHERE email = :email AND is_primary = 1 LIMIT 1");
                $stmt->execute(['email' => strtolower($identifier)]);
                $row = $stmt->fetch();
                if ($row) {
                    $contactId = (int)$row['contact_id'];
                }
            } else if (is_numeric($identifier)) {
                // Resolved via Contact ID
                $contactId = (int)$identifier;
            }

            if ($contactId <= 0) {
                $errorMsg = "Member not found. Please check your Email or Member ID.";
            } else {
                // 2. Fetch Contact Name and Expiry Date
                $contactStmt = $civiDb->prepare("SELECT display_name FROM civicrm_contact WHERE id = :id AND is_deleted = 0 LIMIT 1");
                $contactStmt->execute(['id' => $contactId]);
                $contactRow = $contactStmt->fetch();

                if (!$contactRow) {
                    $errorMsg = "Member not found in database.";
                } else {
                    $contactName = CiviCRMImporter::getFormattedName($contactId);

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
        } catch (Exception $e) {
            $errorMsg = "Check-in system error: " . $e->getMessage();
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

                <form id="checkin-form" action="checkin.php" method="POST" class="terminal-form">
                    <div class="form-group large-input">
                        <label for="identifier">Email Address or Member ID</label>
                        <input type="text" id="identifier" name="identifier" autocomplete="off" required placeholder="Enter Email or ID..." autofocus>
                    </div>
                    <button type="submit" class="btn btn-primary btn-large btn-block">Submit Check-In</button>
                </form>

                <div class="terminal-footer">
                    <p>Issues checking in? Please see the desk administrator.</p>
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

            // AJAX Handler for faster checkins (desk tablet mode)
            const form = document.getElementById('checkin-form');
            const feedbackArea = document.getElementById('feedback-area');

            form.addEventListener('submit', (e) => {
                e.preventDefault();
                
                const data = new URLSearchParams();
                data.append('identifier', inputField.value);
                data.append('ajax', '1');

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
                    if (res.status === 200 && res.body.success) {
                        playAudio(true);
                        renderFeedback(true, res.body.message, res.body.details);
                    } else {
                        playAudio(false);
                        renderFeedback(false, res.body.error || 'Failed to check in.');
                    }
                })
                .catch(err => {
                    playAudio(false);
                    renderFeedback(false, 'Connection error. Please try again.');
                });
            });

            function renderFeedback(success, message, details = null) {
                feedbackArea.innerHTML = '';
                const alertDiv = document.createElement('div');
                alertDiv.className = `alert alert-${success ? 'success' : 'danger'} terminal-alert animate-pop`;
                
                let detailString = '';
                if (details) {
                    detailString = `<span class="subtext">Membership Type: ${details.membership} | Expiration: ${details.expires}</span>`;
                }

                alertDiv.innerHTML = `
                    <span class="alert-icon">${success ? '✔️' : '❌'}</span>
                    <div class="alert-text">
                        <strong>${success ? 'Welcome!' : 'Access Denied'}</strong>
                        <p>${message}</p>
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
