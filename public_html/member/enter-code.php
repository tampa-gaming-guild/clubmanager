<?php
/**
 * Reset Password Code Entry Page
 * Accepts the emailed 6-digit reset code (scoped to the email address, with an
 * attempt cap) or a pasted long link token, then redirects to reset-password.php.
 */
require_once (function() {
    $dir = dirname(dirname(__DIR__));
    if (file_exists($dir . '/.env') && $lines = @file($dir . '/.env')) {
        foreach ($lines as $line) {
            if (preg_match('/^\s*BOOTSTRAP_PATH\s*=\s*["\']?(.*?)["\']?\s*$/', $line, $m)) {
                return $m[1];
            }
        }
    }
    return $dir . '/config/bootstrap.php';
})();

use App\Auth;
use App\Database;

$errorMsg = null;
$successMsg = null;

if (isset($_GET['sent']) && $_GET['sent'] == 1) {
    $successMsg = "If the email address is registered in our portal, you will receive a password reset link and a 6-digit code shortly. Please check your inbox (and spam folder) and enter the code below.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errorMsg = "Invalid security token. Please reload the page.";
    } else {
        $email = trim(strtolower($_POST['email'] ?? ''));
        $code = trim($_POST['code'] ?? '');

        if (empty($code)) {
            $errorMsg = "Please enter the reset code.";
        } elseif (preg_match('/^[0-9]{6}$/', $code)) {
            // 6-digit code path: verification (lookup, attempt-capping,
            // enumeration-safe error) lives in Auth::verifyResetCode(),
            // shared with the mobile API's reset-password endpoint.
            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errorMsg = "Please enter the email address the code was sent to.";
            } else {
                try {
                    Auth::verifyResetCode($email, $code);

                    // Match: the code is single-use. Rotate to a fresh long
                    // token (only its hash is stored, so the original can't
                    // be recovered) and hand off to reset-password.php.
                    $appDb = Database::getAppConnection();
                    $newRawToken = bin2hex(random_bytes(32));
                    $appDb->prepare("UPDATE tgg_password_resets SET token = :token, code = NULL, code_attempts = 0 WHERE email = :email")
                        ->execute(['token' => hash('sha256', $newRawToken), 'email' => $email]);
                    redirect('reset-password.php?token=' . urlencode($newRawToken));
                } catch (Exception $e) {
                    $errorMsg = $e->getMessage();
                }
            }
        } else {
            // Long link-token path (pasted from the email). Unscoped lookup is
            // fine here: the token space is 2^256.
            $hashedToken = hash('sha256', $code);
            try {
                $appDb = Database::getAppConnection();
                $stmt = $appDb->prepare("SELECT email, expires_at FROM tgg_password_resets WHERE token = :token LIMIT 1");
                $stmt->execute(['token' => $hashedToken]);
                $resetRow = $stmt->fetch();

                if ($resetRow) {
                    $expiryTime = strtotime($resetRow['expires_at']);
                    if ($expiryTime >= time()) {
                        // Redirect to reset-password.php with token
                        redirect('reset-password.php?token=' . urlencode($code));
                    } else {
                        $errorMsg = "This reset code has expired. Please request a new one.";
                    }
                } else {
                    $errorMsg = "Invalid reset code. Please check and try again.";
                }
            } catch (Exception $e) {
                $errorMsg = safe_err("An error occurred: ", $e);
            }
        }
    }
}

// Sticky email: what the user just typed, else the address they submitted on
// forgot-password.php (set there regardless of account existence).
$prefillEmail = trim(strtolower($_POST['email'] ?? ($_SESSION['password_reset_email'] ?? '')));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include __DIR__ . '/partials/theme_init.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enter Reset Code - Member Portal</title>
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <link rel="icon" type="image/png" href="favicon.png">
    <link rel="stylesheet" href="assets/css/style.css<?php echo asset_version('assets/css/style.css'); ?>">
</head>
<body>
    <div class="app-container">
        <?php $navGuestCheckin = false; include __DIR__ . '/partials/navbar.php'; ?>

        <main class="main-content centered-content">
            <div class="auth-panel glass-panel">
                <h2>Enter Reset Code</h2>
                <p class="subtitle">Enter the 6-digit code from the reset email, along with your email address.</p>

                <?php if ($errorMsg): ?>
                    <div class="alert alert-danger"><?php echo e($errorMsg); ?></div>
                <?php endif; ?>

                <?php if ($successMsg): ?>
                    <div class="alert alert-success"><?php echo e($successMsg); ?></div>
                <?php endif; ?>

                <form action="enter-code.php" method="POST" class="auth-form">
                    <input type="hidden" name="csrf_token" value="<?php echo e(get_csrf_token()); ?>">

                    <div class="form-group">
                        <label for="email">Email Address</label>
                        <input type="email" id="email" name="email" required placeholder="member@example.com" value="<?php echo e($prefillEmail); ?>"<?php echo $prefillEmail === '' ? ' autofocus' : ''; ?>>
                    </div>

                    <div class="form-group">
                        <label for="code">Reset Code</label>
                        <input type="text" id="code" name="code" required inputmode="numeric" autocomplete="one-time-code" placeholder="6-digit code"<?php echo $prefillEmail !== '' ? ' autofocus' : ''; ?>>
                    </div>

                    <button type="submit" class="btn btn-primary btn-block">Verify Code</button>
                </form>

                <div class="auth-footer">
                    <p><a href="forgot-password.php">Request a new reset link/code</a></p>
                    <p><a href="index.php">Back to Sign In</a></p>
                </div>
            </div>
        </main>

        <?php $footerText = 'TGG Club Membership System. Secure Portal.'; include __DIR__ . '/partials/footer.php'; ?>
</body>
</html>
