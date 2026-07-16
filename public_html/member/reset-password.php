<?php
/**
 * Password Reset Execution Page
 * Validates reset token, processes new password input, updates DB, and notifies user.
 */
require_once dirname(dirname(__DIR__)) . '/config/bootstrap.php';

use App\AuditLog;
use App\Database;
use App\MailHelper;

$errorMsg = null;
$successMsg = null;
$tokenValid = false;

$rawToken = $_GET['token'] ?? $_POST['token'] ?? '';
$hashedToken = hash('sha256', $rawToken);

if (empty($rawToken)) {
    redirect('enter-code.php');
} else {
    try {
        $appDb = Database::getAppConnection();

        // Check if token exists and is not expired
        $stmt = $appDb->prepare("SELECT email, expires_at FROM tgg_password_resets WHERE token = :token LIMIT 1");
        $stmt->execute(['token' => $hashedToken]);
        $resetRow = $stmt->fetch();

        if ($resetRow) {
            $expiryTime = strtotime($resetRow['expires_at']);
            if ($expiryTime >= time()) {
                $tokenValid = true;
                $email = $resetRow['email'];
            } else {
                $errorMsg = "This password reset link has expired. Please request a new link.";
                // Clean up expired token
                $cleanup = $appDb->prepare("DELETE FROM tgg_password_resets WHERE email = :email");
                $cleanup->execute(['email' => $resetRow['email']]);
            }
        } else {
            $errorMsg = "Invalid password reset link. Please request a new link.";
        }

        // Handle Form Submission
        if ($tokenValid && $_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
                $errorMsg = "Invalid security token. Please reload the page.";
            } else {
                $password = $_POST['password'] ?? '';
                $confirmPassword = $_POST['confirm_password'] ?? '';

                if (empty($password) || empty($confirmPassword)) {
                    $errorMsg = "Both password fields are required.";
                } elseif (!is_password_complex($password, $compError)) {
                    $errorMsg = $compError;
                } elseif ($password !== $confirmPassword) {
                    $errorMsg = "Passwords do not match. Please enter them again.";
                } else {
                    // Start transaction to ensure absolute integrity
                    $appDb->beginTransaction();

                    try {
                        // 1. Get contact details
                        $stmtCivi = $appDb->prepare("SELECT id as contact_id FROM tgg_contacts WHERE email = :email LIMIT 1");
                        $stmtCivi->execute(['email' => $email]);
                        $civiRow = $stmtCivi->fetch();

                        if (!$civiRow) {
                            throw new Exception("Local contact associated with this email could not be located.");
                        }
                        $contactId = (int)$civiRow['contact_id'];

                        // Retrieve display name
                        $stmtName = $appDb->prepare("SELECT display_name FROM tgg_contacts WHERE id = :contact_id LIMIT 1");
                        $stmtName->execute(['contact_id' => $contactId]);
                        $nameRow = $stmtName->fetch();
                        $displayName = $nameRow['display_name'] ?? 'Member';

                        // 2. Update or insert settings record, resetting failed login attempts and lockout
                        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                        
                        $stmtCheck = $appDb->prepare("SELECT role FROM tgg_member_settings WHERE contact_id = :contact_id LIMIT 1");
                        $stmtCheck->execute(['contact_id' => $contactId]);
                        $exists = $stmtCheck->fetch();

                        if ($exists) {
                            $stmtUpdate = $appDb->prepare("
                                UPDATE tgg_member_settings 
                                SET password_hash = :hash, failed_login_attempts = 0, locked_until = NULL 
                                WHERE contact_id = :contact_id
                            ");
                            $stmtUpdate->execute([
                                'hash' => $passwordHash,
                                'contact_id' => $contactId
                            ]);
                        } else {
                            $stmtInsert = $appDb->prepare("
                                INSERT INTO tgg_member_settings (contact_id, password_hash, role, failed_login_attempts, locked_until)
                                VALUES (:contact_id, :hash, 'member', 0, NULL)
                            ");
                            $stmtInsert->execute([
                                'contact_id' => $contactId,
                                'hash' => $passwordHash
                            ]);
                        }

                        // 3. Delete token
                        $stmtDelete = $appDb->prepare("DELETE FROM tgg_password_resets WHERE email = :email");
                        $stmtDelete->execute(['email' => $email]);

                        // 3b. Void any pending email change: a password reset is
                        // the member's recovery action, and a still-live change
                        // verification link (possibly attacker-initiated) must
                        // not complete after they've re-secured the account.
                        $stmtVoidChange = $appDb->prepare("DELETE FROM tgg_email_change_requests WHERE contact_id = :contact_id");
                        $stmtVoidChange->execute(['contact_id' => $contactId]);

                        $appDb->commit();

                        // Token-link flow: no login session, so attribute the
                        // reset explicitly to the account owner.
                        AuditLog::log('security', 'password_reset_completed', [
                            'email' => $email
                        ], $contactId, $contactId);

                        // 4. Send Confirmation Email
                        $loginUrl = rtrim($_ENV['BASE_URL'] ?? 'http://localhost/member', '/') . '/index.php';
                        $placeholders = [
                            'display_name' => $displayName,
                            'login_url' => $loginUrl
                        ];
                        MailHelper::sendTemplate($email, 'password_reset_completed', $placeholders, $contactId, null);

                        $successMsg = "Your password has been reset successfully. You can now sign in using your new credentials.";
                        $tokenValid = false; // Disable form

                    } catch (Exception $txEx) {
                        if ($appDb->inTransaction()) $appDb->rollBack();
                        throw $txEx;
                    }
                }
            }
        }
    } catch (Exception $e) {
        $errorMsg = safe_err("An error occurred: ", $e);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - Member Portal</title>
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <link rel="icon" type="image/png" href="favicon.png">
    <link rel="stylesheet" href="assets/css/style.css<?php echo asset_version('assets/css/style.css'); ?>">
</head>
<body>
    <div class="app-container">
        <?php $navGuestCheckin = false; include __DIR__ . '/partials/navbar.php'; ?>

        <main class="main-content centered-content">
            <div class="auth-panel glass-panel">
                <h2>Choose New Password</h2>
                <p class="subtitle">Please choose a password of at least 8 characters. For stronger security, we recommend a longer passphrase (15+ characters).</p>

                <?php if ($errorMsg): ?>
                    <div class="alert alert-danger"><?php echo e($errorMsg); ?></div>
                <?php endif; ?>

                <?php if ($successMsg): ?>
                    <div class="alert alert-success">
                        <p><?php echo e($successMsg); ?></p>
                        <br>
                        <a href="index.php" class="btn btn-primary">Go to Sign In</a>
                    </div>
                <?php endif; ?>

                <?php if ($tokenValid): ?>
                    <form action="reset-password.php" method="POST" class="auth-form">
                        <input type="hidden" name="csrf_token" value="<?php echo e(get_csrf_token()); ?>">
                        <input type="hidden" name="token" value="<?php echo e($rawToken); ?>">
                        
                        <!-- Hidden read-only email field for browser password managers to associate new password correctly -->
                        <input type="text" name="email" value="<?php echo e($email ?? ''); ?>" readonly autocomplete="username" style="display: none;">

                        <div class="form-group">
                            <label for="password">New Password</label>
                            <div class="password-toggle-wrapper">
                                <input type="password" id="password" name="password" required placeholder="••••••••" autofocus autocomplete="new-password">
                                <span class="password-toggle-icon" onclick="togglePasswordVisibility('password')">👁️</span>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="confirm_password">Confirm New Password</label>
                            <div class="password-toggle-wrapper">
                                <input type="password" id="confirm_password" name="confirm_password" required placeholder="••••••••" autocomplete="new-password">
                                <span class="password-toggle-icon" onclick="togglePasswordVisibility('confirm_password')">👁️</span>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary btn-block">Update Password</button>
                    </form>
                <?php elseif (!$successMsg): ?>
                    <div class="auth-footer">
                        <p><a href="enter-code.php" class="btn btn-secondary btn-block">Enter Reset Code Manually</a></p>
                        <p style="margin-top: 15px;"><a href="forgot-password.php">Request a new reset link/code</a></p>
                        <p><a href="index.php">Back to Sign In</a></p>
                    </div>
                <?php endif; ?>
            </div>
        </main>

        <?php $footerText = 'TGG Club Membership System. Secure Portal.'; include __DIR__ . '/partials/footer.php'; ?>
</body>
</html>
