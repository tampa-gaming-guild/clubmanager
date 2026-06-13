<?php
/**
 * Forgot Password Request Page
 * Validates member email, generates secure token, and dispatches reset link.
 */
require_once dirname(dirname(__DIR__)) . '/config/bootstrap.php';

use App\Database;
use App\MailHelper;

$errorMsg = null;
$successMsg = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errorMsg = "Invalid security token. Please reload the page.";
    } else {
        $email = trim(strtolower($_POST['email'] ?? ''));

        if (empty($email)) {
            $errorMsg = "Please enter your email address.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errorMsg = "Please enter a valid email address.";
        } else {
            try {
                $civiDb = Database::getCiviConnection();
                $appDb = Database::getAppConnection();

                // 1. Check if email exists in CiviCRM
                $stmt = $civiDb->prepare("SELECT contact_id FROM civicrm_email WHERE email = :email LIMIT 1");
                $stmt->execute(['email' => $email]);
                $civiRow = $stmt->fetch();

                if ($civiRow) {
                    $contactId = (int)$civiRow['contact_id'];

                    // 2. Check if local settings account exists for this contact
                    $stmt2 = $appDb->prepare("SELECT contact_id FROM tgg_member_settings WHERE contact_id = :contact_id LIMIT 1");
                    $stmt2->execute(['contact_id' => $contactId]);
                    $appRow = $stmt2->fetch();

                    if ($appRow) {
                        // Retrieve contact display name
                        $stmt3 = $civiDb->prepare("SELECT display_name FROM civicrm_contact WHERE id = :contact_id LIMIT 1");
                        $stmt3->execute(['contact_id' => $contactId]);
                        $contactRow = $stmt3->fetch();
                        $displayName = $contactRow['display_name'] ?? 'Member';

                        // 3. Generate secure token
                        $rawToken = bin2hex(random_bytes(32));
                        $hashedToken = hash('sha256', $rawToken);
                        $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));

                        // 4. Save to password resets table
                        $stmt4 = $appDb->prepare("
                            INSERT INTO tgg_password_resets (email, token, expires_at)
                            VALUES (:email, :token, :expires_at)
                            ON DUPLICATE KEY UPDATE token = :token2, expires_at = :expires_at2
                        ");
                        $stmt4->execute([
                            'email' => $email,
                            'token' => $hashedToken,
                            'expires_at' => $expiresAt,
                            'token2' => $hashedToken,
                            'expires_at2' => $expiresAt
                        ]);

                        // 5. Send Email using Template
                        $resetLink = rtrim($_ENV['BASE_URL'] ?? 'http://localhost/member', '/') . '/reset-password.php?token=' . $rawToken;
                        $placeholders = [
                            'display_name' => $displayName,
                            'reset_link' => $resetLink,
                            'expires_in' => '1 hour'
                        ];

                        MailHelper::sendTemplate($email, 'password_reset_link', $placeholders, $contactId, null);
                    }
                }

                // General feedback (does not leak email existence for security)
                $successMsg = "If the email address is registered in our portal, you will receive a password reset link shortly. Please check your inbox (and spam folder).";

            } catch (Exception $e) {
                $errorMsg = "An error occurred while processing your request: " . $e->getMessage();
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
    <title>Forgot Password - Member Portal</title>
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
                <h2>Reset Password</h2>
                <p class="subtitle">Enter your registered email address and we'll send you a link to reset your password.</p>

                <?php if ($errorMsg): ?>
                    <div class="alert alert-danger"><?php echo e($errorMsg); ?></div>
                <?php endif; ?>

                <?php if ($successMsg): ?>
                    <div class="alert alert-success">
                        <p><?php echo e($successMsg); ?></p>
                        <br>
                        <a href="index.php" class="btn btn-primary">Return to Login</a>
                    </div>
                <?php else: ?>
                    <form action="forgot-password.php" method="POST" class="auth-form">
                        <input type="hidden" name="csrf_token" value="<?php echo e(get_csrf_token()); ?>">
                        
                        <div class="form-group">
                            <label for="email">Email Address</label>
                            <input type="email" id="email" name="email" required placeholder="member@example.com" autofocus>
                        </div>

                        <button type="submit" class="btn btn-primary btn-block">Send Reset Link</button>
                    </form>

                    <div class="auth-footer">
                        <p><a href="index.php">Back to Sign In</a></p>
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
