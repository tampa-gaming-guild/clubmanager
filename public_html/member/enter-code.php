<?php
/**
 * Reset Password Code Entry Page
 * Accepts and validates the secure password reset code before redirecting.
 */
require_once dirname(dirname(__DIR__)) . '/config/bootstrap.php';

use App\Database;

$errorMsg = null;
$successMsg = null;

if (isset($_GET['sent']) && $_GET['sent'] == 1) {
    $successMsg = "If the email address is registered in our portal, you will receive a password reset link and code shortly. Please check your inbox (and spam folder) and enter the code below.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errorMsg = "Invalid security token. Please reload the page.";
    } else {
        $token = trim($_POST['token'] ?? '');
        if (empty($token)) {
            $errorMsg = "Please enter the reset code.";
        } else {
            $hashedToken = hash('sha256', $token);
            try {
                $appDb = Database::getAppConnection();
                $stmt = $appDb->prepare("SELECT email, expires_at FROM tgg_password_resets WHERE token = :token LIMIT 1");
                $stmt->execute(['token' => $hashedToken]);
                $resetRow = $stmt->fetch();
                
                if ($resetRow) {
                    $expiryTime = strtotime($resetRow['expires_at']);
                    if ($expiryTime >= time()) {
                        // Redirect to reset-password.php with token
                        redirect('reset-password.php?token=' . urlencode($token));
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enter Reset Code - Member Portal</title>
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <link rel="icon" type="image/png" href="favicon.png">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <div class="app-container">
        <?php $navGuestCheckin = false; include __DIR__ . '/partials/navbar.php'; ?>

        <main class="main-content centered-content">
            <div class="auth-panel glass-panel">
                <h2>Enter Reset Code</h2>
                <p class="subtitle">Please enter the secure reset code sent to your email address.</p>

                <?php if ($errorMsg): ?>
                    <div class="alert alert-danger"><?php echo e($errorMsg); ?></div>
                <?php endif; ?>

                <?php if ($successMsg): ?>
                    <div class="alert alert-success"><?php echo e($successMsg); ?></div>
                <?php endif; ?>

                <form action="enter-code.php" method="POST" class="auth-form">
                    <input type="hidden" name="csrf_token" value="<?php echo e(get_csrf_token()); ?>">
                    
                    <div class="form-group">
                        <label for="token">Reset Code</label>
                        <input type="text" id="token" name="token" required placeholder="Paste or type code here" autofocus autocomplete="off">
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
