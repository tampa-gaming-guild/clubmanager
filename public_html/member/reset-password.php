<?php
/**
 * Password Reset Execution Page
 * Validates reset token, processes new password input, updates DB, and notifies user.
 */
require_once dirname(__DIR__) . '/config/bootstrap.php';

use App\Database;
use App\MailHelper;

$errorMsg = null;
$successMsg = null;
$tokenValid = false;

$rawToken = $_GET['token'] ?? $_POST['token'] ?? '';
$hashedToken = hash('sha256', $rawToken);

if (empty($rawToken)) {
    $errorMsg = "Password reset token is missing. Please request a new link.";
} else {
    try {
        $appDb = Database::getAppConnection();
        $civiDb = Database::getCiviConnection();

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
                } elseif (strlen($password) < 8) {
                    $errorMsg = "Password must be at least 8 characters long.";
                } elseif ($password !== $confirmPassword) {
                    $errorMsg = "Passwords do not match. Please enter them again.";
                } else {
                    // Start transaction on both databases to ensure absolute integrity
                    $appDb->beginTransaction();
                    $civiDb->beginTransaction();

                    try {
                        // 1. Get contact details
                        $stmtCivi = $civiDb->prepare("SELECT contact_id FROM civicrm_email WHERE email = :email LIMIT 1");
                        $stmtCivi->execute(['email' => $email]);
                        $civiRow = $stmtCivi->fetch();

                        if (!$civiRow) {
                            throw new Exception("CiviCRM contact associated with this email could not be located.");
                        }
                        $contactId = (int)$civiRow['contact_id'];

                        // Retrieve display name
                        $stmtName = $civiDb->prepare("SELECT display_name FROM civicrm_contact WHERE id = :contact_id LIMIT 1");
                        $stmtName->execute(['contact_id' => $contactId]);
                        $nameRow = $stmtName->fetch();
                        $displayName = $nameRow['display_name'] ?? 'Member';

                        // 2. Update password hash in local settings
                        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                        $stmtUpdate = $appDb->prepare("UPDATE tgg_member_settings SET password_hash = :hash WHERE contact_id = :contact_id");
                        $stmtUpdate->execute([
                            'hash' => $passwordHash,
                            'contact_id' => $contactId
                        ]);

                        // 3. Delete token
                        $stmtDelete = $appDb->prepare("DELETE FROM tgg_password_resets WHERE email = :email");
                        $stmtDelete->execute(['email' => $email]);

                        $appDb->commit();
                        $civiDb->commit();

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
                        if ($civiDb->inTransaction()) $civiDb->rollBack();
                        throw $txEx;
                    }
                }
            }
        }
    } catch (Exception $e) {
        $errorMsg = "An error occurred: " . $e->getMessage();
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
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <div class="app-container">
        <header class="navbar">
            <div class="logo">TGG Members</div>
            <nav class="nav-links">
                <a href="index.php">Home / Login</a>
                <a href="join.php">Join Us</a>
                <a href="calendar.php">Calendar</a>
                <a href="volunteers.php">Volunteers</a>
            </nav>
        </header>

        <main class="main-content centered-content">
            <div class="auth-panel glass-panel">
                <h2>Choose New Password</h2>
                <p class="subtitle">Please choose a secure password of at least 8 characters.</p>

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
                        
                        <div class="form-group">
                            <label for="password">New Password</label>
                            <input type="password" id="password" name="password" required placeholder="••••••••" autofocus>
                        </div>

                        <div class="form-group">
                            <label for="confirm_password">Confirm New Password</label>
                            <input type="password" id="confirm_password" name="confirm_password" required placeholder="••••••••">
                        </div>

                        <button type="submit" class="btn btn-primary btn-block">Update Password</button>
                    </form>
                <?php elseif (!$successMsg && !$errorMsg): ?>
                    <div class="auth-footer">
                        <p><a href="forgot-password.php">Request a new reset link</a></p>
                    </div>
                <?php endif; ?>
            </div>
        </main>

        <footer class="app-footer">
            <p>&copy; <?php echo date('Y'); ?> TGG Club Membership System. Secure Portal.</p>
        </footer>
    </div>
</body>
</html>
