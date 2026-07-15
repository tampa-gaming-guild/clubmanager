<?php
/**
 * Forgot Password Request Page
 * Validates member email, generates secure token, and dispatches reset link.
 */
require_once dirname(dirname(__DIR__)) . '/config/bootstrap.php';

use App\Auth;
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

                    // 2. Generate secure token + 6-digit code and save to password resets table
                    $reset = Auth::createPasswordSetupToken($email, '+1 hour');

                    // 3. Send Email using Template
                    $resetLink = rtrim($_ENV['BASE_URL'] ?? 'http://localhost/member', '/') . '/reset-password.php?token=' . $reset['token'];
                    $placeholders = [
                        'display_name' => $displayName,
                        'reset_link' => $resetLink,
                        'reset_code' => $reset['code'],
                        'expires_in' => '1 hour'
                    ];

                    MailHelper::sendTemplate($email, 'password_reset_link', $placeholders, $contactId, null);
                }

                // Prefill for enter-code.php. Set regardless of whether the email
                // matched a contact, so it doesn't leak account existence.
                $_SESSION['password_reset_email'] = $email;

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
    <link rel="stylesheet" href="assets/css/style.css<?php echo asset_version('assets/css/style.css'); ?>">
</head>
<body>
    <div class="app-container">
        <?php $navGuestCheckin = false; include __DIR__ . '/partials/navbar.php'; ?>

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
                        <p><a href="enter-code.php">Already have a reset code? Enter it manually</a></p>
                        <p><a href="index.php">Back to Sign In</a></p>
                    </div>
                <?php endif; ?>
            </div>
        </main>

        <?php $footerText = 'TGG Club Membership System. Secure Portal.'; include __DIR__ . '/partials/footer.php'; ?>
</body>
</html>
