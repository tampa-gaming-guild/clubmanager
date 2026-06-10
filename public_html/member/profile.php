<?php
/**
 * Member Profile Page
 * Handles public profile display and private details (email, phone, credentials, privacy preferences) for owners/admins.
 */
require_once dirname(dirname(__DIR__)) . '/config/bootstrap.php';

use App\Database;
use App\CiviCRMImporter;
use App\Auth;

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
    $civiDb = Database::getCiviConnection();
    $appDb = Database::getAppConnection();

    // A. Fetch Contact Info from CiviCRM
    $contactStmt = $civiDb->prepare("
        SELECT c.id, c.display_name, c.first_name, c.last_name, e.email, p.phone
        FROM civicrm_contact c
        LEFT JOIN civicrm_email e ON e.contact_id = c.id AND e.is_primary = 1
        LEFT JOIN civicrm_phone p ON p.contact_id = c.id AND p.is_primary = 1
        WHERE c.id = :id AND c.is_deleted = 0 LIMIT 1
    ");
    $contactStmt->execute(['id' => $profileId]);
    $contact = $contactStmt->fetch();

    if ($contact) {
        // B. Fetch Membership Details
        $membership = CiviCRMImporter::getMemberMembershipDetails($profileId);

        // C. Fetch Local Settings / Privacy Info
        $settingsStmt = $appDb->prepare("SELECT role, is_profile_public, public_fields FROM tgg_member_settings WHERE contact_id = :id LIMIT 1");
        $settingsStmt->execute(['id' => $profileId]);
        $settings = $settingsStmt->fetch();
    }
} catch (Exception $e) {
    $errorMsg = "Database Connection Error: " . $e->getMessage();
}

if (!$contact) {
    die("Member Profile not found.");
}

// Default settings if they don't exist yet locally
if (!$settings) {
    $settings = [
        'role' => 'member',
        'is_profile_public' => 1,
        'public_fields' => json_encode(['display_name', 'membership_name', 'status_label'])
    ];
}

$publicFields = json_decode($settings['public_fields'] ?? '[]', true) ?: [];

// 2. Check Viewer Relationship
$isOwner = Auth::check() && $_SESSION['user']['contact_id'] === $profileId;
$isAdmin = Auth::check() && $_SESSION['user']['role'] === 'admin';
$hasPrivateAccess = $isOwner || $isAdmin;

// 3. Privacy Gate
if (!$settings['is_profile_public'] && !$hasPrivateAccess) {
    // Hidden completely if profile is private and viewer is anonymous/different member
    $profileHidden = true;
} else {
    $profileHidden = false;
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
            
            // Re-render display fields
            $allowedFieldsJSON = json_encode($allowedFields);

            try {
                // Ensure row exists
                $check = $appDb->prepare("SELECT contact_id FROM tgg_member_settings WHERE contact_id = :id");
                $check->execute(['id' => $profileId]);
                
                if ($check->fetch()) {
                    $update = $appDb->prepare("UPDATE tgg_member_settings SET is_profile_public = :is_public, public_fields = :fields WHERE contact_id = :id");
                    $update->execute(['is_public' => $isPublic, 'fields' => $allowedFieldsJSON, 'id' => $profileId]);
                } else {
                    $insert = $appDb->prepare("INSERT INTO tgg_member_settings (contact_id, password_hash, role, is_profile_public, public_fields) VALUES (:id, :hash, 'member', :is_public, :fields)");
                    // Default temporary password
                    $insert->execute([
                        'id' => $profileId,
                        'hash' => password_hash('change_me_123', PASSWORD_DEFAULT),
                        'is_public' => $isPublic,
                        'fields' => $allowedFieldsJSON
                    ]);
                }

                $successMsg = "Privacy preferences saved successfully.";
                // Refresh settings array
                $settings['is_profile_public'] = $isPublic;
                $settings['public_fields'] = $allowedFieldsJSON;
                $publicFields = $allowedFields;

            } catch (Exception $e) {
                $errorMsg = "Failed to save privacy settings: " . $e->getMessage();
            }
        }

        // B. Handle Password Change (Only owner or admin)
        if (isset($_POST['password_update'])) {
            $newPassword = $_POST['new_password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';

            if (empty($newPassword) || strlen($newPassword) < 8) {
                $errorMsg = "New password must be at least 8 characters.";
            } elseif ($newPassword !== $confirmPassword) {
                $errorMsg = "Passwords do not match.";
            } else {
                try {
                    Auth::registerPassword($profileId, $newPassword, $settings['role']);
                    $successMsg = "Password updated successfully.";
                } catch (Exception $e) {
                    $errorMsg = "Failed to update password: " . $e->getMessage();
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
    <title><?php echo e($contact['display_name']); ?> - Member Profile</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <div class="app-container">
        <header class="navbar">
            <div class="logo">TGG Members</div>
            <nav class="nav-links">
                <?php if (Auth::check()): ?>
                    <a href="index.php" class="<?php echo $isOwner ? 'active' : ''; ?>">Dashboard</a>
                    <a href="calendar.php">Calendar</a>
                    <a href="volunteers.php">Volunteers</a>
                    <a href="checkin.php">Check-In</a>
                    <?php if (has_role('admin')): ?>
                        <a href="admin/dashboard.php">Admin</a>
                    <?php endif; ?>
                    <a href="index.php?action=logout" class="btn-logout">Logout</a>
                <?php else: ?>
                    <a href="index.php">Login</a>
                    <a href="join.php">Join Us</a>
                    <a href="calendar.php">Calendar</a>
                    <a href="volunteers.php">Volunteers</a>
                <?php endif; ?>
            </nav>
        </header>

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
                            <?php echo strtoupper(substr($contact['first_name'] ?? 'M', 0, 1) . substr($contact['last_name'] ?? '', 0, 1)); ?>
                        </div>
                        <div class="profile-title">
                            <h2><?php echo e($contact['display_name']); ?></h2>
                            <span class="badge badge-role"><?php echo e(ucfirst($settings['role'])); ?></span>
                        </div>
                    </div>

                    <div class="profile-body-grid">
                        <!-- Left Panel: Profile Details -->
                        <div class="profile-details-column">
                            
                            <!-- PUBLIC SECTION -->
                            <div class="detail-section">
                                <h3 class="section-title">Public Status</h3>
                                <table class="profile-data-table">
                                    <?php if ($hasPrivateAccess || in_array('display_name', $publicFields)): ?>
                                    <tr>
                                        <td><strong>Full Name:</strong></td>
                                        <td><?php echo e($contact['display_name']); ?></td>
                                    </tr>
                                    <?php endif; ?>
                                    
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
                                    <?php else: ?>
                                        <tr>
                                            <td><strong>Membership:</strong></td>
                                            <td>No active membership records.</td>
                                        </tr>
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

                                        <p class="settings-instruction mt-10">Select fields that are visible to the public:</p>
                                        <div class="form-group checkbox-group">
                                            <input type="checkbox" id="field_name" name="public_fields[]" value="display_name" 
                                                <?php echo in_array('display_name', $publicFields) ? 'checked' : ''; ?>>
                                            <label for="field_name">Show Full Name</label>
                                        </div>
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

                                        <button type="submit" name="privacy_update" class="btn btn-secondary btn-block mt-15">Save Privacy settings</button>
                                    </form>
                                </div>

                                <!-- Password Change Panel -->
                                <div class="management-card mt-20">
                                    <h4>Change Portal Password</h4>
                                    <form action="profile.php?id=<?php echo $profileId; ?>" method="POST" class="settings-form">
                                        <input type="hidden" name="csrf_token" value="<?php echo e(get_csrf_token()); ?>">
                                        
                                        <div class="form-group">
                                            <label for="new_password">New Password (min 8 chars)</label>
                                            <input type="password" id="new_password" name="new_password" required>
                                        </div>
                                        <div class="form-group">
                                            <label for="confirm_password">Confirm New Password</label>
                                            <input type="password" id="confirm_password" name="confirm_password" required>
                                        </div>

                                        <button type="submit" name="password_update" class="btn btn-warning btn-block mt-10">Update Password</button>
                                    </form>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </main>

        <footer class="app-footer">
            <p>&copy; <?php echo date('Y'); ?> TGG Club Membership System. Secure Public Portal.</p>
        </footer>
    </div>
</body>
</html>
