<?php
/**
 * Reset Password Code Entry Page
 * Accepts the emailed 6-digit reset code (scoped to the email address, with an
 * attempt cap) or a pasted long link token, then redirects to reset-password.php.
 */
require_once dirname(dirname(__DIR__)) . '/config/bootstrap.php';

use App\Database;

const MAX_CODE_ATTEMPTS = 5;

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
            // 6-digit code path: a 6-digit space is small, so the lookup is
            // scoped to the email address and capped at MAX_CODE_ATTEMPTS
            // wrong guesses per issued code. Every failure shows the same
            // generic message so the responses reveal nothing about which
            // emails have pending resets.
            $genericError = "Invalid or expired code. Please check the code and email address, or request a new one.";
            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errorMsg = "Please enter the email address the code was sent to.";
            } else {
                try {
                    $appDb = Database::getAppConnection();
                    $stmt = $appDb->prepare("SELECT code, expires_at, code_attempts FROM tgg_password_resets WHERE email = :email LIMIT 1");
                    $stmt->execute(['email' => $email]);
                    $row = $stmt->fetch();

                    if (!$row || $row['code'] === null || (int)$row['code_attempts'] >= MAX_CODE_ATTEMPTS || strtotime($row['expires_at']) < time()) {
                        $errorMsg = $genericError;
                    } elseif (!hash_equals($row['code'], hash('sha256', $code))) {
                        // Wrong code: count the strike. Hitting the cap deletes
                        // the whole reset row, link token included.
                        $attempts = (int)$row['code_attempts'] + 1;
                        if ($attempts >= MAX_CODE_ATTEMPTS) {
                            $appDb->prepare("DELETE FROM tgg_password_resets WHERE email = :email")
                                ->execute(['email' => $email]);
                        } else {
                            $appDb->prepare("UPDATE tgg_password_resets SET code_attempts = :attempts WHERE email = :email")
                                ->execute(['attempts' => $attempts, 'email' => $email]);
                        }
                        $errorMsg = $genericError;
                    } else {
                        // Match: the code is single-use. Rotate to a fresh long
                        // token (only its hash is stored, so the original can't
                        // be recovered) and hand off to reset-password.php.
                        $newRawToken = bin2hex(random_bytes(32));
                        $appDb->prepare("UPDATE tgg_password_resets SET token = :token, code = NULL, code_attempts = 0 WHERE email = :email")
                            ->execute(['token' => hash('sha256', $newRawToken), 'email' => $email]);
                        redirect('reset-password.php?token=' . urlencode($newRawToken));
                    }
                } catch (Exception $e) {
                    $errorMsg = safe_err("An error occurred: ", $e);
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
