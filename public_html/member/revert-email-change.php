<?php
/**
 * Email Change Revert Page
 * Post-completion account-takeover recovery: consumes the 72-hour revert
 * token mailed to the OLD address when a verified email change completed,
 * restoring that address as the login email. Restores unconditionally (a
 * chained A->B->C takeover still reverts to A), kills every pending and
 * revert row for the contact, and auto-sends a password reset link -- the
 * attacker knew the password, so restoring the email alone is not enough.
 * No login required.
 */
require_once dirname(dirname(__DIR__)) . '/config/bootstrap.php';

use App\Database;
use App\Auth;
use App\MailHelper;
use App\BillingHelper;

$errorMsg = null;
$successMsg = null;

$rawToken = $_GET['token'] ?? '';
$hashedToken = hash('sha256', $rawToken);

if (empty($rawToken)) {
    $errorMsg = "Invalid or missing revert link.";
} else {
    try {
        $appDb = Database::getAppConnection();

        $stmt = $appDb->prepare("SELECT id, contact_id, old_email, expires_at FROM tgg_email_change_reverts WHERE token = :token LIMIT 1");
        $stmt->execute(['token' => $hashedToken]);
        $revert = $stmt->fetch();

        if (!$revert) {
            $errorMsg = "Invalid or already-used revert link.";
        } elseif (strtotime($revert['expires_at']) < time()) {
            $errorMsg = "This revert link has expired. Please contact an administrator immediately to recover your account.";
            $appDb->prepare("DELETE FROM tgg_email_change_reverts WHERE id = :id")->execute(['id' => $revert['id']]);
        } else {
            $contactId = (int)$revert['contact_id'];
            $oldEmail = $revert['old_email'];

            $contactStmt = $appDb->prepare("SELECT id, display_name FROM tgg_contacts WHERE id = :id AND is_deleted = 0 LIMIT 1");
            $contactStmt->execute(['id' => $contactId]);
            $contact = $contactStmt->fetch();

            // No unique index on tgg_contacts.email: make sure the address we
            // are restoring hasn't since been claimed by a different account.
            $dupStmt = $appDb->prepare("SELECT id FROM tgg_contacts WHERE email = :email AND is_deleted = 0 AND id != :id LIMIT 1");
            $dupStmt->execute(['email' => $oldEmail, 'id' => $contactId]);
            $emailTaken = (bool)$dupStmt->fetch();

            if (!$contact) {
                $errorMsg = "This account is no longer active. Please contact an administrator.";
                $appDb->prepare("DELETE FROM tgg_email_change_reverts WHERE id = :id")->execute(['id' => $revert['id']]);
            } elseif ($emailTaken) {
                $errorMsg = "That email address is now used by another account, so it cannot be restored automatically. Please contact an administrator immediately.";
            } else {
                $appDb->beginTransaction();
                try {
                    $appDb->prepare("UPDATE tgg_contacts SET email = :email WHERE id = :id")
                        ->execute(['email' => $oldEmail, 'id' => $contactId]);
                    // Kill the attacker's chain: every outstanding revert token
                    // and any still-pending next change for this contact.
                    $appDb->prepare("DELETE FROM tgg_email_change_reverts WHERE contact_id = :id")->execute(['id' => $contactId]);
                    $appDb->prepare("DELETE FROM tgg_email_change_requests WHERE contact_id = :id")->execute(['id' => $contactId]);
                    $appDb->commit();
                } catch (Exception $txEx) {
                    if ($appDb->inTransaction()) $appDb->rollBack();
                    throw $txEx;
                }

                if (Auth::check() && (int)$_SESSION['user']['contact_id'] === $contactId) {
                    $_SESSION['user']['email'] = $oldEmail;
                }

                BillingHelper::syncStripeCustomerEmail($contactId, $oldEmail);

                // The attacker knew the password: proactively send a reset
                // link to the restored address. Best-effort -- the revert
                // itself already succeeded.
                try {
                    $reset = Auth::createPasswordSetupToken($oldEmail, '+1 hour');
                    $resetLink = rtrim($_ENV['BASE_URL'] ?? 'http://localhost/member', '/') . '/reset-password.php?token=' . $reset['token'];
                    MailHelper::sendTemplate($oldEmail, 'password_reset_link', [
                        'display_name' => $contact['display_name'] ?? 'Member',
                        'reset_link' => $resetLink,
                        'reset_code' => $reset['code'],
                        'expires_in' => '1 hour'
                    ], $contactId, null);
                } catch (Exception $mailEx) {
                    error_log("Failed to send post-revert password reset for contact {$contactId}: " . $mailEx->getMessage());
                }

                $successMsg = "Your login email has been restored to " . $oldEmail . " and any pending changes were cancelled. We've emailed you a password reset link — use it now; whoever changed your email knew your password.";
            }
        }
    } catch (Exception $e) {
        $errorMsg = safe_err("Could not revert the email change: ", $e);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Revert Email Change - TGG Member Portal</title>
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
                <h2>Revert Email Change</h2>

                <?php if ($errorMsg): ?>
                    <div class="alert alert-danger"><?php echo e($errorMsg); ?></div>
                    <br>
                    <a href="index.php" class="btn btn-primary">Back to Home</a>
                <?php elseif ($successMsg): ?>
                    <div class="alert alert-success">
                        <p><?php echo e($successMsg); ?></p>
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
