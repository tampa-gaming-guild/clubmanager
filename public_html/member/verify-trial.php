<?php
/**
 * Trial Membership Verification Page
 * Validates the emailed verification token and activates the one-time 30-day Trial membership.
 */
require_once dirname(dirname(__DIR__)) . '/config/bootstrap.php';

use App\Database;
use App\BillingHelper;
use App\MailHelper;

$errorMsg = null;
$successMsg = null;

$rawToken = $_GET['token'] ?? '';
$hashedToken = hash('sha256', $rawToken);

if (empty($rawToken)) {
    $errorMsg = "Invalid or missing verification link.";
} else {
    try {
        $appDb = Database::getAppConnection();

        $stmt = $appDb->prepare("SELECT contact_id, plan_id, expires_at FROM tgg_trial_verifications WHERE token = :token LIMIT 1");
        $stmt->execute(['token' => $hashedToken]);
        $verification = $stmt->fetch();

        if (!$verification) {
            $errorMsg = "Invalid or already-used verification link.";
        } elseif (strtotime($verification['expires_at']) < time()) {
            $errorMsg = "This verification link has expired. Please register again to request a new Trial membership.";
            $cleanup = $appDb->prepare("DELETE FROM tgg_trial_verifications WHERE contact_id = :contact_id");
            $cleanup->execute(['contact_id' => $verification['contact_id']]);
        } else {
            $contactId = (int)$verification['contact_id'];
            $planId = (int)$verification['plan_id'];

            $nameStmt = $appDb->prepare("SELECT display_name, email FROM tgg_contacts WHERE id = :id LIMIT 1");
            $nameStmt->execute(['id' => $contactId]);
            $contact = $nameStmt->fetch();
            $displayName = $contact['display_name'] ?? 'Member';
            $email = $contact['email'] ?? '';

            $activation = BillingHelper::activateTrial($contactId, $planId);

            $deleteToken = $appDb->prepare("DELETE FROM tgg_trial_verifications WHERE contact_id = :contact_id");
            $deleteToken->execute(['contact_id' => $contactId]);

            $successMsg = "Your 30-day Trial membership is now active! It runs through " . date('F j, Y', strtotime($activation['end_date'])) . ".";

            if (!empty($email)) {
                try {
                    $loginUrl = rtrim($_ENV['BASE_URL'] ?? 'http://localhost/member', '/') . '/index.php';
                    MailHelper::sendTemplate($email, 'trial_activated', [
                        'display_name' => $displayName,
                        'start_date' => $activation['start_date'],
                        'end_date' => $activation['end_date'],
                        'login_url' => $loginUrl
                    ], $contactId, null);
                } catch (Exception $mailEx) {
                    error_log("Failed to send trial activation email: " . $mailEx->getMessage());
                }
            }
        }
    } catch (Exception $e) {
        $errorMsg = safe_err("Could not verify your Trial membership: ", $e);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Trial Membership - TGG Member Portal</title>
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
                <h2>Trial Membership Verification</h2>

                <?php if ($errorMsg): ?>
                    <div class="alert alert-danger"><?php echo e($errorMsg); ?></div>
                    <br>
                    <a href="join.php" class="btn btn-primary">Back to Join</a>
                <?php elseif ($successMsg): ?>
                    <div class="alert alert-success">
                        <p><?php echo e($successMsg); ?></p>
                        <br>
                        <a href="index.php?action=login" class="btn btn-primary">Go to Login</a>
                    </div>
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
