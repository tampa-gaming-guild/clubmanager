<?php
/**
 * Email Change Cancel Page
 * One-click cancel target from the security alarm sent to the OLD address
 * when an email change is requested. Deletes the pending request so its
 * verification link can no longer complete. No login required -- the person
 * holding the old mailbox may be a victim locked out of their session.
 */
require_once dirname(dirname(__DIR__)) . '/config/bootstrap.php';

use App\Database;

$errorMsg = null;
$successMsg = null;

$rawToken = $_GET['token'] ?? '';
$hashedToken = hash('sha256', $rawToken);

if (empty($rawToken)) {
    $errorMsg = "Invalid or missing cancellation link.";
} else {
    try {
        $appDb = Database::getAppConnection();

        $stmt = $appDb->prepare("SELECT contact_id, new_email FROM tgg_email_change_requests WHERE cancel_token = :token LIMIT 1");
        $stmt->execute(['token' => $hashedToken]);
        $request = $stmt->fetch();

        if (!$request) {
            $errorMsg = "Invalid or already-used cancellation link. If the email change already went through, use the revert link from the follow-up email instead.";
        } else {
            // Cancel even if the request has expired -- deleting a stale row
            // is harmless and reassures the member.
            $appDb->prepare("DELETE FROM tgg_email_change_requests WHERE contact_id = :id")
                ->execute(['id' => $request['contact_id']]);
            $successMsg = "The pending email change has been cancelled — your login email is unchanged.";
        }
    } catch (Exception $e) {
        $errorMsg = safe_err("Could not cancel the email change: ", $e);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cancel Email Change - TGG Member Portal</title>
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <link rel="icon" type="image/png" href="favicon.png">
    <link rel="apple-touch-icon" href="favicon.png">
    <link rel="manifest" href="manifest.json">
    <link rel="stylesheet" href="assets/css/style.css<?php echo asset_version('assets/css/style.css'); ?>">
</head>
<body>
    <div class="app-container">
        <?php $navGuestCheckin = false; include __DIR__ . '/partials/navbar.php'; ?>

        <main class="main-content centered-content">
            <div class="auth-panel glass-panel">
                <h2>Cancel Email Change</h2>

                <?php if ($errorMsg): ?>
                    <div class="alert alert-danger"><?php echo e($errorMsg); ?></div>
                    <p style="margin-top: 15px;">If you're worried about your account, reset your password:</p>
                    <a href="forgot-password.php" class="btn btn-warning btn-block">Reset My Password</a>
                <?php elseif ($successMsg): ?>
                    <div class="alert alert-success">
                        <p><?php echo e($successMsg); ?></p>
                    </div>
                    <p style="margin-top: 15px;"><strong>If you did not request that change, someone may know your password.</strong> Reset it now:</p>
                    <a href="forgot-password.php" class="btn btn-warning btn-block">Reset My Password</a>
                <?php endif; ?>
            </div>
        </main>

        <?php include __DIR__ . '/partials/footer.php'; ?>
    <script>
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
