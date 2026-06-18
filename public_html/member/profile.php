<?php
/**
 * Member Profile Page
 * Handles public profile display and private details (email, phone, credentials, privacy preferences) for owners/admins.
 */
require_once dirname(dirname(__DIR__)) . '/config/bootstrap.php';

use App\Database;
use App\CiviCRMImporter;
use App\Auth;
use App\MailHelper;

$errorMsg = null;
$successMsg = null;

// 1. Get Target Profile ID
$profileId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($profileId <= 0 && Auth::check()) {
    $profileId = $_SESSION['user']['contact_id']; // Default to logged-in user
}

if ($profileId <= 0) {
    redirect('index.php');
}

$civiDb = null;
$appDb = null;
$contact = null;
$membership = null;
$settings = null;

try {
    $appDb = Database::getAppConnection();

    // A. Fetch Contact Info from local contacts
    $contactStmt = $appDb->prepare("
        SELECT id, display_name, first_name, last_name, email, phone
        FROM tgg_contacts
        WHERE id = :id AND is_deleted = 0 LIMIT 1
    ");
    $contactStmt->execute(['id' => $profileId]);
    $contact = $contactStmt->fetch();

        if ($contact) {
        // B. Fetch Membership Details
        $membership = CiviCRMImporter::getMemberMembershipDetails($profileId);

        // C. Fetch Local Settings / Privacy Info
        $settingsStmt = $appDb->prepare("SELECT role, is_profile_public, public_fields, custom_display_name FROM tgg_member_settings WHERE contact_id = :id LIMIT 1");
        $settingsStmt->execute(['id' => $profileId]);
        $settings = $settingsStmt->fetch();

        // D. Fetch all roles list
        $rolesList = $appDb->query("SELECT * FROM `tgg_roles` ORDER BY id ASC")->fetchAll();
    }
} catch (Exception $e) {
    $errorMsg = safe_err("Database Connection Error: ", $e);
}

if (!$contact) {
    die("Member Profile not found.");
}

// Default settings if they don't exist yet locally
if (!$settings) {
    $settings = [
        'role' => 'member',
        'is_profile_public' => 1,
        'public_fields' => json_encode(['display_name', 'membership_name', 'status_label']),
        'custom_display_name' => $contact['display_name']
    ];
}

// Fetch all roles assigned to this member
$memberRoles = [];
if ($contact) {
    try {
        $rolesStmt = $appDb->prepare("SELECT role_name FROM tgg_member_roles WHERE contact_id = :id");
        $rolesStmt->execute(['id' => $profileId]);
        $memberRoles = $rolesStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Exception $e) {
        // Fallback or ignore
    }
}
if (empty($memberRoles) && isset($settings['role'])) {
    $memberRoles = [$settings['role']];
}

$publicFields = json_decode($settings['public_fields'] ?? '[]', true) ?: [];

// 2. Check Viewer Relationship
$isOwner = Auth::check() && $_SESSION['user']['contact_id'] === $profileId;
$isAdmin = Auth::check() && (has_role('admin') || has_role('superadmin'));
$hasPrivateAccess = $isOwner || $isAdmin;

// 3. Privacy Gate
if (!$settings['is_profile_public'] && !$hasPrivateAccess) {
    // Hidden completely if profile is private and viewer is anonymous/different member
    $profileHidden = true;
} else {
    $profileHidden = false;
}

// Fetch Billing Transactions if Viewer has Private Access
$transactions = [];
if ($hasPrivateAccess && $appDb) {
    try {
        $transStmt = $appDb->prepare("
            SELECT l.created_at, l.amount, l.currency, l.action_type, l.payment_status, l.payment_intent_id as trxn_id, p.name as plan_name
            FROM tgg_billing_ledger l
            INNER JOIN tgg_subscription_plans p ON l.plan_id = p.id
            WHERE l.contact_id = :contact_id
            ORDER BY l.created_at DESC
        ");
        $transStmt->execute(['contact_id' => $profileId]);
        $transactions = $transStmt->fetchAll();
    } catch (Exception $e) {
        $errorMsg = safe_err(($errorMsg ? $errorMsg . " | " : "") . "Failed to load billing history: ", $e);
    }
}

// Fetch Volunteer Credits details if Viewer has Private Access
$expirationDays = 365.0; // Default
$volunteerShifts = [];
$totalEarned = 0.0;
$totalApplied = 0.0;
$totalExpired = 0.0;
$availableCredits = 0.0;
$pendingCredits = 0.0;
$nextExpirationDate = 'Never';
$nextExpirationCredits = 0.0;

if ($hasPrivateAccess && $appDb) {
    try {
        // Fetch expiration days setting
        $configsStmt = $appDb->query("SELECT credit_key, credits FROM tgg_volunteer_credits");
        $creditsMap = $configsStmt->fetchAll(PDO::FETCH_KEY_PAIR);
        $expirationDays = (float)($creditsMap['credit_expiration_days'] ?? 365.0);

        // 1. Fetch completed shifts from volunteer signups (past events)
        $stmtShifts = $appDb->prepare("
            SELECT s.event_id, s.role, e.title as event_title, e.start_time, t.id as processed_id
            FROM tgg_volunteer_signups s
            INNER JOIN tgg_events e ON s.event_id = e.id
            LEFT JOIN tgg_volunteer_credit_transactions t ON t.event_id = s.event_id AND t.contact_id = s.contact_id AND t.shift = s.role
            WHERE s.contact_id = :contact_id AND e.start_time < :now
            ORDER BY e.start_time DESC
        ");
        $stmtShifts->execute([
            'contact_id' => $profileId,
            'now' => date('Y-m-d H:i:s')
        ]);
        $completedShifts = $stmtShifts->fetchAll();
        
        foreach ($completedShifts as $shift) {
            $role = $shift['role'];
            $isSunday = (date('w', strtotime($shift['start_time'])) == 0);
            
            if ($role === 'Open') {
                $key = $isSunday ? 'sunday_open' : 'weekday_open';
            } elseif ($role === 'Close') {
                $key = $isSunday ? 'sunday_close' : 'weekday_close';
            } elseif ($role === 'Greeter') {
                $key = $isSunday ? 'sunday_greeter' : 'weekday_greeter';
            } else {
                $key = 'weekday_open';
            }
            
            $creditsVal = (float)($creditsMap[$key] ?? 0.0);
            
            if (!$shift['processed_id']) {
                $pendingCredits += $creditsVal;
            }
            
            $volunteerShifts[] = [
                'date' => date('Y-m-d', strtotime($shift['start_time'])),
                'event_title' => $shift['event_title'],
                'shift' => $role,
                'credits' => $creditsVal,
                'status' => $shift['processed_id'] ? 'Processed' : 'Pending'
            ];
        }

        // 2. Fetch all logged transactions (for FIFO calculation of processed totals and expirations)
        $stmtTx = $appDb->prepare("
            SELECT id, volunteer_date, shift, credits_earned, credits_applied
            FROM tgg_volunteer_credit_transactions
            WHERE contact_id = :contact_id
            ORDER BY volunteer_date ASC, id ASC
        ");
        $stmtTx->execute(['contact_id' => $profileId]);
        $allTx = $stmtTx->fetchAll();
        
        $earned = [];
        $applied = [];
        
        foreach ($allTx as $tx) {
            $valEarned = (float)$tx['credits_earned'];
            $valApplied = (float)$tx['credits_applied'];
            if ($valEarned > 0.0) {
                $earned[] = [
                    'id' => $tx['id'],
                    'date' => $tx['volunteer_date'],
                    'amount' => $valEarned,
                    'remaining' => $valEarned
                ];
                $totalEarned += $valEarned;
            }
            if ($valApplied > 0.0) {
                $applied[] = [
                    'id' => $tx['id'],
                    'date' => $tx['volunteer_date'],
                    'amount' => $valApplied
                ];
                $totalApplied += $valApplied;
            }
        }
        
        if ($expirationDays > 0.0) {
            // FIFO simulation to match applications to earned credits
            foreach ($applied as $appTx) {
                $appDate = $appTx['date'];
                $appAmount = $appTx['amount'];
                
                foreach ($earned as &$earnTx) {
                    if ($earnTx['remaining'] <= 0.0) {
                        continue;
                    }
                    
                    $earnDate = $earnTx['date'];
                    $expireDate = date('Y-m-d', strtotime($earnDate . " + " . (int)$expirationDays . " days"));
                    if ($expireDate < $appDate) {
                        continue; // Expired before applied
                    }
                    
                    if ($appAmount >= $earnTx['remaining']) {
                        $appAmount -= $earnTx['remaining'];
                        $earnTx['remaining'] = 0.0;
                    } else {
                        $earnTx['remaining'] -= $appAmount;
                        $appAmount = 0.0;
                        break;
                    }
                }
                unset($earnTx);
            }
            
            $today = date('Y-m-d');
            $expiringCandidates = [];
            
            foreach ($earned as $earnTx) {
                if ($earnTx['remaining'] > 0.0) {
                    $earnDate = $earnTx['date'];
                    $expireDate = date('Y-m-d', strtotime($earnDate . " + " . (int)$expirationDays . " days"));
                    if ($expireDate < $today) {
                        $totalExpired += $earnTx['remaining'];
                    } else {
                        $expiringCandidates[] = [
                            'expire_date' => $expireDate,
                            'amount' => $earnTx['remaining']
                        ];
                    }
                }
            }
            
            // Find next expiration date and amount
            if (!empty($expiringCandidates)) {
                usort($expiringCandidates, function($a, $b) {
                    return strcmp($a['expire_date'], $b['expire_date']);
                });
                
                $nextExpirationDate = $expiringCandidates[0]['expire_date'];
                $nextExpirationCredits = 0.0;
                foreach ($expiringCandidates as $cand) {
                    if ($cand['expire_date'] === $nextExpirationDate) {
                        $nextExpirationCredits += $cand['amount'];
                    }
                }
            }
        }
        
        $availableCredits = max(0.0, $totalEarned - $totalApplied - $totalExpired);
        
        // Dynamically update db settings to avoid drift
        $stmtSelect = $appDb->prepare("SELECT contact_id FROM tgg_member_settings WHERE contact_id = :contact_id LIMIT 1");
        $stmtSelect->execute(['contact_id' => $profileId]);
        if ($stmtSelect->fetch()) {
            $stmtUpdate = $appDb->prepare("
                UPDATE tgg_member_settings 
                SET credits_earned = :earned, credits_applied = :applied, expired_credits = :expired 
                WHERE contact_id = :contact_id
            ");
            $stmtUpdate->execute([
                'earned' => $totalEarned,
                'applied' => $totalApplied,
                'expired' => $totalExpired,
                'contact_id' => $profileId
            ]);
        }
    } catch (Exception $e) {
        $errorMsg = safe_err(($errorMsg ? $errorMsg . " | " : "") . "Failed to load volunteer credits: ", $e);
    }
}

// 4. Handle Settings Updates (Only owner or admin)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $hasPrivateAccess) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errorMsg = "Invalid security token. Please try again.";
    } else {
        // A. Handle Privacy Toggles
        if (isset($_POST['privacy_update'])) {
            $isPublic = isset($_POST['is_profile_public']) ? 1 : 0;
            $allowedFields = $_POST['public_fields'] ?? [];
            $customDisplayName = trim($_POST['custom_display_name'] ?? '');
            
            try {
                if (empty($customDisplayName)) {
                    throw new Exception("Display name is required and cannot be left blank.");
                }
                
                // Keep allowed fields clean
                $allowedFields = array_diff($allowedFields, ['display_name', 'display_name_initials']);
                $allowedFieldsJSON = json_encode(array_values($allowedFields));

                // Ensure row exists
                $check = $appDb->prepare("SELECT contact_id FROM tgg_member_settings WHERE contact_id = :id");
                $check->execute(['id' => $profileId]);
                
                if ($check->fetch()) {
                    $update = $appDb->prepare("UPDATE tgg_member_settings SET is_profile_public = :is_public, public_fields = :fields, custom_display_name = :custom_name WHERE contact_id = :id");
                    $update->execute(['is_public' => $isPublic, 'fields' => $allowedFieldsJSON, 'custom_name' => $customDisplayName, 'id' => $profileId]);
                } else {
                    $insert = $appDb->prepare("INSERT INTO tgg_member_settings (contact_id, password_hash, role, is_profile_public, public_fields, custom_display_name) VALUES (:id, :hash, 'member', :is_public, :fields, :custom_name)");
                    // Default temporary secure random password
                    $randomToken = bin2hex(random_bytes(32));
                    $insert->execute([
                        'id' => $profileId,
                        'hash' => password_hash($randomToken, PASSWORD_DEFAULT),
                        'is_public' => $isPublic,
                        'fields' => $allowedFieldsJSON,
                        'custom_name' => $customDisplayName
                    ]);
                }

                $successMsg = "Privacy preferences saved successfully.";
                // Refresh settings array
                $settings['is_profile_public'] = $isPublic;
                $settings['public_fields'] = $allowedFieldsJSON;
                $settings['custom_display_name'] = $customDisplayName;
                $publicFields = $allowedFields;

                // Update session display name if the owner updated their settings
                if ($isOwner) {
                    $_SESSION['user']['display_name'] = $customDisplayName;
                }

            } catch (Exception $e) {
                $errorMsg = safe_err("Failed to save settings: ", $e);
            }
        }

        // A1. Handle Role Updates (Only Admin/Superadmin)
        if (isset($_POST['role_update']) && $isAdmin) {
            $newRoles = $_POST['roles'] ?? [];
            if (!is_array($newRoles)) {
                $newRoles = [];
            }
            // Sanitize input roles
            $newRoles = array_map('trim', $newRoles);
            
            try {
                $viewerIsSuperadmin = has_role('superadmin');
                $targetHasSuperadmin = in_array('superadmin', $memberRoles, true);
                
                // 1. Standard admin cannot modify roles for a superadmin user
                if ($targetHasSuperadmin && !$viewerIsSuperadmin) {
                    throw new Exception("Standard admins cannot modify roles for a superadmin user.");
                }
                
                // 2. Only superadmins can assign or remove the superadmin role
                $newHasSuperadmin = in_array('superadmin', $newRoles, true);
                if ($newHasSuperadmin !== $targetHasSuperadmin && !$viewerIsSuperadmin) {
                    throw new Exception("Only superadmins can add or delete the superadmin role.");
                }
                
                // 3. Verify all selected roles exist in tgg_roles
                if (!empty($newRoles)) {
                    $placeholders = implode(',', array_fill(0, count($newRoles), '?'));
                    $checkStmt = $appDb->prepare("SELECT COUNT(*) FROM `tgg_roles` WHERE name IN ($placeholders)");
                    $checkStmt->execute($newRoles);
                    $foundCount = (int)$checkStmt->fetchColumn();
                    if ($foundCount !== count(array_unique($newRoles))) {
                        throw new Exception("One or more selected roles do not exist.");
                    }
                } else {
                    // Users must have at least one role. Default to 'member'.
                    $newRoles = ['member'];
                }
                
                // 4. Update the database
                $appDb->beginTransaction();
                
                // Delete existing roles
                $deleteStmt = $appDb->prepare("DELETE FROM tgg_member_roles WHERE contact_id = :id");
                $deleteStmt->execute(['id' => $profileId]);
                
                // Insert new roles
                $insertStmt = $appDb->prepare("INSERT INTO tgg_member_roles (contact_id, role_name) VALUES (:id, :role)");
                foreach ($newRoles as $roleName) {
                    $insertStmt->execute(['id' => $profileId, 'role' => $roleName]);
                }
                
                // Update legacy column in tgg_member_settings
                $primaryRole = $newRoles[0] ?? 'member';
                $updateSettingsStmt = $appDb->prepare("UPDATE tgg_member_settings SET role = :role WHERE contact_id = :id");
                $updateSettingsStmt->execute(['role' => $primaryRole, 'id' => $profileId]);
                
                $appDb->commit();
                
                $successMsg = "Member roles updated successfully.";
                $memberRoles = $newRoles;
                $settings['role'] = $primaryRole;
                
                // Refresh session if self
                if ($profileId === (int)$_SESSION['user']['contact_id']) {
                    Auth::refreshPermissions();
                }
            } catch (Exception $e) {
                if ($appDb->inTransaction()) {
                    $appDb->rollBack();
                }
                $errorMsg = safe_err("Failed to update role: ", $e);
            }
        }

        // A2. Start Impersonating User (Only Superadmin)
        if (isset($_POST['impersonate_user']) && $isAdmin) {
            try {
                Auth::impersonate($profileId);
                redirect('index.php');
            } catch (Exception $e) {
                $errorMsg = safe_err("Impersonation failed: ", $e);
            }
        }

        // B. Handle Trigger Password Reset (Only owner or user with password resets permission)
        if (isset($_POST['trigger_password_reset'])) {
            try {
                if (!$isOwner && !has_permission('password resets')) {
                    throw new Exception("You do not have permission to trigger password resets for other members.");
                }
                $email = trim(strtolower($contact['email'] ?? ''));
                if (empty($email)) {
                    throw new Exception("This member does not have a registered email address.");
                }

                // 1. Generate secure token
                $rawToken = bin2hex(random_bytes(32));
                $hashedToken = hash('sha256', $rawToken);
                $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));

                // 2. Save to password resets table
                $stmtReset = $appDb->prepare("
                    INSERT INTO tgg_password_resets (email, token, expires_at)
                    VALUES (:email, :token, :expires_at)
                    ON DUPLICATE KEY UPDATE token = :token2, expires_at = :expires_at2
                ");
                $stmtReset->execute([
                    'email' => $email,
                    'token' => $hashedToken,
                    'expires_at' => $expiresAt,
                    'token2' => $hashedToken,
                    'expires_at2' => $expiresAt
                ]);

                // 3. Send Email using Template
                $resetLink = rtrim($_ENV['BASE_URL'] ?? 'http://localhost/member', '/') . '/reset-password.php?token=' . $rawToken;
                $displayName = !empty(trim($settings['custom_display_name'] ?? '')) ? trim($settings['custom_display_name']) : $contact['display_name'];
                $placeholders = [
                    'display_name' => $displayName,
                    'reset_link' => $resetLink,
                    'reset_code' => $rawToken,
                    'expires_in' => '1 hour'
                ];

                MailHelper::sendTemplate($email, 'password_reset_link', $placeholders, $profileId, $_SESSION['user']['contact_id'] ?? null);

                // Redirect to code entry page
                redirect('enter-code.php?sent=1');

            } catch (Exception $e) {
                $errorMsg = safe_err("Failed to send password reset: ", $e);
            }
        }
    }
}

// Resolve the custom display name to show
$displayNameToPublic = !empty(trim($settings['custom_display_name'] ?? '')) ? trim($settings['custom_display_name']) : $contact['display_name'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($displayNameToPublic); ?> - Member Profile</title>
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <link rel="icon" type="image/png" href="favicon.png">
    <link rel="apple-touch-icon" href="favicon.png">
    <link rel="manifest" href="manifest.json">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
    /* Tabs Navigation */
    .profile-tabs {
        display: flex;
        gap: 10px;
        margin-bottom: 25px;
        border-bottom: 1px solid var(--border-glass);
        padding-bottom: 10px;
    }
    .tab-button {
        background: transparent;
        border: none;
        color: var(--color-text-secondary);
        font-size: 0.95rem;
        font-weight: 600;
        padding: 10px 20px;
        cursor: pointer;
        border-radius: 6px;
        transition: all 0.2s ease;
        outline: none;
    }
    .tab-button:hover {
        color: #fff;
        background: rgba(255, 255, 255, 0.05);
    }
    .tab-button.active {
        color: #fff;
        background: rgba(34, 197, 94, 0.15); /* success tint */
        border: 1px solid rgba(34, 197, 94, 0.3);
    }

    /* Tab Contents */
    .tab-content {
        display: none;
    }
    .tab-content.active {
        display: block;
    }

    /* Expanded UI layouts */
    .full-width-section {
        width: 100% !important;
    }
    </style>
</head>
<body>
    <div class="app-container">
        <?php $navActive = $isOwner ? 'dashboard' : ''; $navGuestCheckin = false; include __DIR__ . '/partials/navbar.php'; ?>

        <main class="main-content centered-content">
            <?php if ($errorMsg): ?>
                <div class="alert alert-danger" style="max-width: 650px; margin: 10px auto;"><?php echo e($errorMsg); ?></div>
            <?php endif; ?>

            <?php if ($successMsg): ?>
                <div class="alert alert-success" style="max-width: 650px; margin: 10px auto;"><?php echo e($successMsg); ?></div>
            <?php endif; ?>

            <?php if ($profileHidden): ?>
                <div class="auth-panel glass-panel">
                    <h2>Private Profile</h2>
                    <p class="description-text">The owner of this profile has marked it as private.</p>
                    <a href="index.php" class="btn btn-primary mt-10">Back to Dashboard</a>
                </div>
            <?php else: ?>
                <div class="profile-container glass-panel">
                    <div class="profile-header-section">
                        <div class="avatar-placeholder">
                            <?php 
                            $initials = strtoupper(substr($contact['first_name'] ?? 'M', 0, 1) . substr($contact['last_name'] ?? '', 0, 1));
                            echo e($initials);
                            ?>
                        </div>
                        <div class="profile-title">
                            <h2><?php echo e($displayNameToPublic); ?> <span style="font-size: 1.1rem; color: var(--color-text-secondary); font-weight: normal; margin-left: 8px;">(Member ID: <?php echo $profileId; ?>)</span></h2>
                            <span class="badge badge-role"><?php 
                                $roleHierarchy = ['superadmin', 'admin', 'host', 'member', 'guest'];
                                usort($memberRoles, function($a, $b) use ($roleHierarchy) {
                                    $posA = array_search($a, $roleHierarchy, true);
                                    $posB = array_search($b, $roleHierarchy, true);
                                    $posA = ($posA === false) ? 999 : $posA;
                                    $posB = ($posB === false) ? 999 : $posB;
                                    return $posA <=> $posB;
                                });
                                $capitalizedRoles = array_map(function($r) { return ucfirst($r); }, $memberRoles);
                                echo e(implode(' | ', $capitalizedRoles)); 
                            ?></span>
                        </div>
                    </div>

                    <?php if ($hasPrivateAccess): ?>
                        <div class="profile-tabs">
                            <button class="tab-button active" onclick="switchTab('profile')">Profile</button>
                            <button class="tab-button" onclick="switchTab('volunteering')">Volunteering</button>
                            <button class="tab-button" onclick="switchTab('billing')">Billing History</button>
                        </div>
                    <?php endif; ?>

                    <?php if ($hasPrivateAccess): ?>
                    <div id="tab-profile" class="tab-content active">
                    <?php endif; ?>
                    <div class="profile-body-grid">
                        <!-- Left Panel: Profile Details -->
                        <div class="profile-details-column">
                            
                            <!-- PUBLIC SECTION -->
                            <div class="detail-section">
                                <h3 class="section-title">Public Status</h3>
                                <table class="profile-data-table">
                                    <tr>
                                        <td><strong>Name:</strong></td>
                                        <td><?php echo e($displayNameToPublic); ?></td>
                                    </tr>
                                    
                                    <?php if ($membership): ?>
                                        <?php if ($hasPrivateAccess || in_array('membership_name', $publicFields)): ?>
                                        <tr>
                                            <td><strong>Membership Level:</strong></td>
                                            <td><?php echo e($membership['membership_name']); ?></td>
                                        </tr>
                                        <?php endif; ?>

                                        <?php if ($hasPrivateAccess || in_array('status_label', $publicFields)): ?>
                                        <tr>
                                            <td><strong>Status:</strong></td>
                                            <td>
                                                <span class="badge badge-status <?php echo $membership['is_active'] ? 'badge-active' : 'badge-expired'; ?>">
                                                    <?php echo e($membership['status_label']); ?>
                                                </span>
                                            </td>
                                        </tr>
                                        <?php endif; ?>

                                        <?php if ($hasPrivateAccess): ?>
                                        <tr>
                                            <td></td>
                                            <td>
                                                <a href="renew.php?contact_id=<?php echo $profileId; ?>" class="btn btn-success btn-small" style="margin-top: 5px; display: inline-block;">Renew/Extend Membership</a>
                                            </td>
                                        </tr>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td><strong>Membership:</strong></td>
                                            <td>No active membership records.</td>
                                        </tr>
                                        <?php if ($hasPrivateAccess): ?>
                                        <tr>
                                            <td></td>
                                            <td>
                                                <a href="renew.php?contact_id=<?php echo $profileId; ?>" class="btn btn-primary btn-small" style="margin-top: 5px; display: inline-block;">Purchase Membership</a>
                                            </td>
                                        </tr>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </table>
                            </div>

                            <!-- PRIVATE SECTION -->
                            <div class="detail-section private-detail-section">
                                <div class="section-header">
                                    <h3 class="section-title">Private Details</h3>
                                    <span class="private-badge">🔒 Owner & Admins Only</span>
                                </div>
                                
                                <?php if ($hasPrivateAccess): ?>
                                    <table class="profile-data-table">
                                        <tr>
                                            <td><strong>Email:</strong></td>
                                            <td><a href="mailto:<?php echo e($contact['email']); ?>"><?php echo e($contact['email']); ?></a></td>
                                        </tr>
                                        <tr>
                                            <td><strong>Phone:</strong></td>
                                            <td><?php echo e($contact['phone'] ?: 'None registered'); ?></td>
                                        </tr>
                                        <?php if ($membership): ?>
                                            <tr>
                                                <td><strong>Join Date:</strong></td>
                                                <td><?php echo date('F j, Y', strtotime($membership['join_date'])); ?></td>
                                            </tr>
                                            <tr>
                                                <td><strong>Expiration Date:</strong></td>
                                                <td><?php echo date('F j, Y', strtotime($membership['end_date'])); ?></td>
                                            </tr>
                                        <?php endif; ?>
                                    </table>
                                <?php else: ?>
                                    <p class="private-locked-msg">You do not have permission to view private details (Email, Phone, Dates).</p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Right Panel: Management (Only for Owner or Admin) -->
                        <?php if ($hasPrivateAccess): ?>
                            <div class="profile-actions-column">
                                <!-- Privacy Settings Panel -->
                                <div class="management-card">
                                    <h4>Profile Privacy Preferences</h4>
                                    <form action="profile.php?id=<?php echo $profileId; ?>" method="POST" class="settings-form">
                                        <input type="hidden" name="csrf_token" value="<?php echo e(get_csrf_token()); ?>">
                                        
                                        <div class="form-group checkbox-group">
                                            <input type="checkbox" id="is_profile_public" name="is_profile_public" value="1" 
                                                <?php echo $settings['is_profile_public'] ? 'checked' : ''; ?>>
                                            <label for="is_profile_public">Allow public users to find my profile</label>
                                        </div>

                                        <div class="form-group" style="margin-top: 15px; margin-bottom: 15px;">
                                            <label for="custom_display_name" style="display: block; font-size: 0.9rem; font-weight: 500; margin-bottom: 5px; color: #fff;">Preferred Display Name (Required)</label>
                                            <input type="text" id="custom_display_name" name="custom_display_name" value="<?php echo e($settings['custom_display_name'] ?? $contact['display_name']); ?>" required>
                                        </div>

                                        <p class="settings-instruction mt-15">Other public fields:</p>
                                        <div class="form-group checkbox-group">
                                            <input type="checkbox" id="field_tier" name="public_fields[]" value="membership_name" 
                                                <?php echo in_array('membership_name', $publicFields) ? 'checked' : ''; ?>>
                                            <label for="field_tier">Show Membership Tier</label>
                                        </div>
                                        <div class="form-group checkbox-group">
                                            <input type="checkbox" id="field_status" name="public_fields[]" value="status_label" 
                                                <?php echo in_array('status_label', $publicFields) ? 'checked' : ''; ?>>
                                            <label for="field_status">Show Membership Status</label>
                                        </div>

                                        <button type="submit" name="privacy_update" class="btn btn-success btn-block mt-15">Save Privacy settings</button>
                                    </form>
                                </div>

                                <!-- Password Reset Panel -->
                                <div class="management-card mt-20">
                                    <h4>Portal Password Reset</h4>
                                    <p style="font-size: 0.85rem; color: rgba(255, 255, 255, 0.75); margin-bottom: 15px; line-height: 1.4;">
                                        To change your portal password, click the button below to receive a secure password reset link at your registered email address (<strong><?php echo e($contact['email']); ?></strong>).
                                    </p>
                                    <form action="profile.php?id=<?php echo $profileId; ?>" method="POST">
                                        <input type="hidden" name="csrf_token" value="<?php echo e(get_csrf_token()); ?>">
                                        <button type="submit" name="trigger_password_reset" class="btn btn-warning btn-block">Send Password Reset Email</button>
                                    </form>
                                </div>

                                <!-- Admin Role Assignment Card -->
                                <?php if ($isAdmin): ?>
                                <div class="management-card mt-20">
                                    <h4>Portal Role Assignment</h4>
                                    <form action="profile.php?id=<?php echo $profileId; ?>" method="POST" class="settings-form">
                                        <input type="hidden" name="csrf_token" value="<?php echo e(get_csrf_token()); ?>">
                                        <div class="form-group">
                                            <label style="display: block; font-size: 0.85rem; margin-bottom: 8px; color: rgba(255,255,255,0.85);">Assign Roles</label>
                                            <div style="display: flex; flex-direction: column; gap: 10px; margin-top: 5px;">
                                                <?php foreach ($rolesList as $roleOption): 
                                                    $isSuperadminRole = ($roleOption['name'] === 'superadmin');
                                                    $targetHasSuperadmin = in_array('superadmin', $memberRoles, true);
                                                    $viewerIsSuperadmin = has_role('superadmin');
                                                    
                                                    $disabled = '';
                                                    if ($isSuperadminRole && !$viewerIsSuperadmin) {
                                                        $disabled = 'disabled';
                                                    } elseif ($targetHasSuperadmin && !$viewerIsSuperadmin) {
                                                        $disabled = 'disabled';
                                                    }
                                                    
                                                    $checked = in_array($roleOption['name'], $memberRoles, true) ? 'checked' : '';
                                                ?>
                                                    <label style="display: inline-flex; align-items: center; gap: 8px; color: #fff; cursor: <?php echo $disabled ? 'not-allowed' : 'pointer'; ?>; opacity: <?php echo $disabled ? '0.5' : '1'; ?>;">
                                                        <input type="checkbox" name="roles[]" value="<?php echo e($roleOption['name']); ?>" <?php echo $checked; ?> <?php echo $disabled; ?> style="width: auto;">
                                                        <?php echo e(ucfirst($roleOption['name'])); ?>
                                                    </label>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                        <button type="submit" name="role_update" class="btn btn-primary btn-block mt-15">Update Roles</button>
                                        <?php 
                                        $originalRoles = $_SESSION['impersonator']['roles'] ?? $_SESSION['user']['roles'] ?? [];
                                        $originalRole = $_SESSION['impersonator']['role'] ?? $_SESSION['user']['role'] ?? '';
                                        $isOriginalSuperadmin = in_array('superadmin', $originalRoles, true) || $originalRole === 'superadmin';
                                        if ($isOriginalSuperadmin && $profileId !== (int)$_SESSION['user']['contact_id']): 
                                        ?>
                                            <button type="submit" name="impersonate_user" class="btn btn-warning btn-block mt-10">Login As User</button>
                                        <?php endif; ?>
                                    </form>
                                </div>
                                <?php endif; ?>

                                <!-- Portal Roles Info Card -->
                                <div class="management-card mt-20">
                                    <h4>Available Portal Roles</h4>
                                    <div style="font-size: 0.8rem; line-height: 1.4; color: rgba(255,255,255,0.75);">
                                        <ul style="list-style-type: none; padding-left: 0; display: flex; flex-direction: column; gap: 8px; margin: 0;">
                                            <?php foreach ($rolesList as $roleItem): ?>
                                                <li>
                                                    <strong style="color: var(--color-primary);"><?php echo e(ucfirst($roleItem['name'])); ?></strong>: 
                                                    <span><?php echo e($roleItem['description']); ?></span>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php if ($hasPrivateAccess): ?>
                    </div>
                    <?php endif; ?>

                    <?php if ($hasPrivateAccess): ?>
                    <div id="tab-volunteering" class="tab-content">
                        <!-- VOLUNTEERING & CREDITS SECTION -->
                        <div class="detail-section private-detail-section full-width-section">
                            <div class="section-header">
                                <h3 class="section-title">Volunteering & Credits</h3>
                                <span class="private-badge">🔒 Owner & Admins Only</span>
                            </div>
                            
                            <div class="volunteer-summary-grid" style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px; margin-bottom: 20px; background: rgba(255, 255, 255, 0.02); padding: 15px; border-radius: 8px; border: 1px solid var(--border-glass);">
                                <div>
                                    <p style="margin: 0; font-size: 0.85rem; color: var(--color-text-secondary);">Lifetime Earned:</p>
                                    <h4 style="margin: 5px 0 0 0; color: #fff; font-size: 1.2rem;">
                                        <?php echo number_format($totalEarned, 1); ?>
                                        <?php if ($pendingCredits > 0.0): ?>
                                            <span style="font-size: 0.8rem; color: var(--color-text-secondary); font-weight: normal;">
                                                (+<?php echo number_format($pendingCredits, 1); ?> pending)
                                            </span>
                                        <?php endif; ?>
                                    </h4>
                                </div>
                                <div>
                                    <p style="margin: 0; font-size: 0.85rem; color: var(--color-text-secondary);">Lifetime Applied:</p>
                                    <h4 style="margin: 5px 0 0 0; color: #fff; font-size: 1.2rem;"><?php echo number_format($totalApplied, 1); ?></h4>
                                </div>
                                <div>
                                    <p style="margin: 0; font-size: 0.85rem; color: var(--color-text-secondary);">Outstanding Balance:</p>
                                    <h4 style="margin: 5px 0 0 0; color: var(--color-success); font-size: 1.2rem;">
                                        <?php echo number_format($availableCredits, 1); ?>
                                        <?php if ($pendingCredits > 0.0): ?>
                                            <span style="font-size: 0.8rem; color: var(--color-text-secondary); font-weight: normal;">
                                                (+<?php echo number_format($pendingCredits, 1); ?> pending)
                                            </span>
                                        <?php endif; ?>
                                    </h4>
                                </div>
                                <div>
                                    <p style="margin: 0; font-size: 0.85rem; color: var(--color-text-secondary);">Expired Credits:</p>
                                    <h4 style="margin: 5px 0 0 0; color: var(--color-danger); font-size: 1.2rem;"><?php echo number_format($totalExpired, 1); ?></h4>
                                </div>
                            </div>

                            <div style="margin-bottom: 20px; background: rgba(255, 255, 255, 0.02); padding: 12px 15px; border-radius: 8px; border: 1px solid var(--border-glass);">
                                <p style="margin: 0 0 5px 0; font-size: 0.85rem; color: var(--color-text-secondary);">Next Expiration:</p>
                                <strong style="color: #fff;">
                                    <?php if ($nextExpirationDate === 'Never'): ?>
                                        Never
                                    <?php else: ?>
                                        <?php echo date('F j, Y', strtotime($nextExpirationDate)); ?> 
                                        <span style="color: var(--color-danger); font-weight: normal; font-size: 0.85rem; margin-left: 10px;">
                                            (<?php echo number_format($nextExpirationCredits, 1); ?> credit<?php echo $nextExpirationCredits > 1 ? 's' : ''; ?> will expire)
                                        </span>
                                    <?php endif; ?>
                                </strong>
                            </div>

                            <h4 style="margin: 20px 0 10px 0; color: #fff; font-size: 0.95rem;">Completed Shifts</h4>
                            <?php if (empty($volunteerShifts)): ?>
                                <p class="private-locked-msg">No completed shifts found.</p>
                            <?php else: ?>
                                <div class="admin-table-container">
                                    <table class="admin-table" style="font-size: 0.85rem; width: 100%;">
                                        <thead>
                                            <tr>
                                                <th style="padding: 8px 10px;">Date</th>
                                                <th style="padding: 8px 10px;">Event</th>
                                                <th style="padding: 8px 10px;">Shift</th>
                                                <th style="padding: 8px 10px; text-align: center;">Credits</th>
                                                <th style="padding: 8px 10px; text-align: center;">Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($volunteerShifts as $tx): ?>
                                                <tr>
                                                    <td style="padding: 8px 10px;"><span class="table-datetime"><?php echo date('Y-m-d', strtotime($tx['date'])); ?></span></td>
                                                    <td style="padding: 8px 10px; font-weight: bold; color: #fff;"><?php echo e($tx['event_title'] ?: 'Volunteer Event'); ?></td>
                                                    <td style="padding: 8px 10px;"><?php echo e($tx['shift']); ?></td>
                                                    <td style="padding: 8px 10px; text-align: center; font-weight: bold; color: var(--color-primary);">+<?php echo (float)$tx['credits']; ?></td>
                                                    <td style="padding: 8px 10px; text-align: center;">
                                                        <?php if ($tx['status'] === 'Processed'): ?>
                                                            <span class="badge badge-active" style="font-size: 0.75rem; padding: 2px 6px; display: inline-block;">Processed</span>
                                                        <?php else: ?>
                                                            <span class="badge badge-expired" style="font-size: 0.75rem; padding: 2px 6px; display: inline-block; background: rgba(234, 179, 8, 0.15); color: #eab308; border: 1px solid rgba(234, 179, 8, 0.3);">Pending</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($hasPrivateAccess): ?>
                    <div id="tab-billing" class="tab-content">
                        <!-- BILLING HISTORY SECTION -->
                        <div class="detail-section private-detail-section full-width-section">
                            <div class="section-header">
                                <h3 class="section-title">Billing History</h3>
                                <span class="private-badge">🔒 Owner & Admins Only</span>
                            </div>
                            
                            <?php if (empty($transactions)): ?>
                                <p class="private-locked-msg">No billing transactions found.</p>
                            <?php else: ?>
                                <div class="admin-table-container">
                                    <table class="admin-table" style="font-size: 0.85rem; width: 100%;">
                                        <thead>
                                            <tr>
                                                <th style="padding: 8px 10px;">Date</th>
                                                <th style="padding: 8px 10px;">Plan</th>
                                                <th style="padding: 8px 10px;">Amount</th>
                                                <th style="padding: 8px 10px;">Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($transactions as $tx): ?>
                                                 <?php
                                                 $badgeClass = 'badge-expired';
                                                 $badgeLabel = ucfirst($tx['payment_status']);
                                                 if ($tx['payment_status'] === 'paid') {
                                                     $trxnId = $tx['trxn_id'] ?? '';
                                                     if (strpos($trxnId, 'offline_volunteer_credit_') === 0) {
                                                         $badgeClass = 'badge-volunteer';
                                                         $badgeLabel = 'Volunteer';
                                                     } elseif (strpos($trxnId, 'offline_complimentary_') === 0) {
                                                         $badgeClass = 'badge-free';
                                                         $badgeLabel = 'Free';
                                                     } elseif (strpos($trxnId, 'offline_cash_') === 0) {
                                                         $badgeClass = 'badge-active';
                                                         $badgeLabel = 'Paid (Cash)';
                                                     } elseif (strpos($trxnId, 'offline_check_') === 0) {
                                                         $badgeClass = 'badge-active';
                                                         $badgeLabel = 'Paid (Check)';
                                                     } else {
                                                         $badgeClass = 'badge-active';
                                                         $badgeLabel = 'Paid (Credit Card)';
                                                     }
                                                 }
                                                 ?>
                                                 <tr>
                                                     <td style="padding: 8px 10px;"><span class="table-datetime"><?php echo date('Y-m-d', strtotime($tx['created_at'])); ?></span></td>
                                                     <td style="padding: 8px 10px;"><strong><?php echo e($tx['plan_name']); ?></strong></td>
                                                     <td style="padding: 8px 10px;">$<?php echo number_format($tx['amount'], 2); ?></td>
                                                     <td style="padding: 8px 10px;">
                                                         <span class="badge <?php echo $badgeClass; ?>" style="font-size: 0.75rem; padding: 2px 6px; display: inline-block;">
                                                             <?php echo e($badgeLabel); ?>
                                                         </span>
                                                     </td>
                                                 </tr>
                                             <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </main>

        <?php include __DIR__ . '/partials/footer.php'; ?>

    <script>
    function switchTab(tabId) {
        document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
        document.querySelectorAll('.tab-button').forEach(el => el.classList.remove('active'));
        
        const content = document.getElementById('tab-' + tabId);
        if (content) content.classList.add('active');
        
        const btn = document.querySelector(`.tab-button[onclick*="'${tabId}'"]`);
        if (btn) btn.classList.add('active');
    }

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
