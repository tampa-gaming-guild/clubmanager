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
                $appDb = Database::getAppConnection();

                // 1. Check if email exists in local contacts
                $stmt = $appDb->prepare("SELECT id, display_name FROM tgg_contacts WHERE email = :email AND is_deleted = 0 LIMIT 1");
                $stmt->execute(['email' => $email]);
                $civiRow = $stmt->fetch();

                if ($civiRow) {
                    $contactId = (int)$civiRow['id'];
                    $displayName = $civiRow['display_name'] ?? 'Member';

                    // 2. Generate secure token
                    $rawToken = bin2hex(random_bytes(32));
                    $hashedToken = hash('sha256', $rawToken);
                    $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));

                    // 3. Save to password resets table
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

                    // 4. Send Email using Template
                    $resetLink = rtrim($_ENV['BASE_URL'] ?? 'http://localhost/member', '/') . '/reset-password.php?token=' . $rawToken;
                    $placeholders = [
                        'display_name' => $displayName,
                        'reset_link' => $resetLink,
                        'reset_code' => $rawToken,
                        'expires_in' => '1 hour'
                    ];

                    MailHelper::sendTemplate($email, 'password_reset_link', $placeholders, $contactId, null);
                }

                // Redirect to code entry page
                redirect('enter-code.php?sent=1');

            } catch (Exception $e) {
                $errorMsg = safe_err("An error occurred while processing your request: ", $e);
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
                        <p><a href="reset-password.php">Already have a reset code? Enter it manually</a></p>
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
