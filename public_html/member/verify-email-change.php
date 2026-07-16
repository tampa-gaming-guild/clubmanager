<?php
/**
 * Email Change Verification Page
 * Consumes the token emailed to the NEW address and, after re-validating,
 * applies the pending login email change. Leaves behind a 72-hour revert
 * token (mailed to the old address) as account-takeover recovery.
 */
require_once dirname(dirname(__DIR__)) . '/config/bootstrap.php';

use App\Database;
use App\Auth;
use App\AuditLog;
use App\MailHelper;
use App\BillingHelper;

$errorMsg = null;
$successMsg = null;
$newEmail = null;

$rawToken = $_GET['token'] ?? '';
$hashedToken = hash('sha256', $rawToken);

if (empty($rawToken)) {
    $errorMsg = "Invalid or missing verification link.";
} else {
    try {
        $appDb = Database::getAppConnection();

        $stmt = $appDb->prepare("SELECT contact_id, new_email, old_email, expires_at FROM tgg_email_change_requests WHERE token = :token LIMIT 1");
        $stmt->execute(['token' => $hashedToken]);
        $request = $stmt->fetch();

        if (!$request) {
            $errorMsg = "Invalid or already-used verification link.";
        } elseif (strtotime($request['expires_at']) < time()) {
            $errorMsg = "This verification link has expired. Please request the email change again from your profile.";
            $cleanup = $appDb->prepare("DELETE FROM tgg_email_change_requests WHERE contact_id = :contact_id");
            $cleanup->execute(['contact_id' => $request['contact_id']]);
        } else {
            $contactId = (int)$request['contact_id'];
            $newEmail = $request['new_email'];
            $oldEmail = $request['old_email'];

            // Re-validate at consumption time: the contact must still be
            // active, and the new address must still be free -- there is no
            // unique index on tgg_contacts.email, so this closes the race
            // window since the request was made.
            $contactStmt = $appDb->prepare("SELECT id, display_name FROM tgg_contacts WHERE id = :id AND is_deleted = 0 LIMIT 1");
            $contactStmt->execute(['id' => $contactId]);
            $contact = $contactStmt->fetch();

            $dupStmt = $appDb->prepare("SELECT id FROM tgg_contacts WHERE email = :email AND is_deleted = 0 AND id != :id LIMIT 1");
            $dupStmt->execute(['email' => $newEmail, 'id' => $contactId]);
            $emailTaken = (bool)$dupStmt->fetch();

            if (!$contact) {
                $errorMsg = "This account is no longer active. Please contact an administrator.";
                $appDb->prepare("DELETE FROM tgg_email_change_requests WHERE contact_id = :id")->execute(['id' => $contactId]);
            } elseif ($emailTaken) {
                $errorMsg = "That email address has since been taken by another account. Please request the change again with a different address.";
                $appDb->prepare("DELETE FROM tgg_email_change_requests WHERE contact_id = :id")->execute(['id' => $contactId]);
            } else {
                $rawRevertToken = bin2hex(random_bytes(32));
                $revertExpiresAt = date('Y-m-d H:i:s', strtotime('+72 hours'));

                $appDb->beginTransaction();
                try {
                    $appDb->prepare("UPDATE tgg_contacts SET email = :email WHERE id = :id")
                        ->execute(['email' => $newEmail, 'id' => $contactId]);
                    $appDb->prepare("DELETE FROM tgg_email_change_requests WHERE contact_id = :id")
                        ->execute(['id' => $contactId]);
                    $appDb->prepare("
                        INSERT INTO tgg_email_change_reverts (contact_id, old_email, new_email, token, expires_at)
                        VALUES (:contact_id, :old_email, :new_email, :token, :expires_at)
                    ")->execute([
                        'contact_id' => $contactId,
                        'old_email' => $oldEmail,
                        'new_email' => $newEmail,
                        'token' => hash('sha256', $rawRevertToken),
                        'expires_at' => $revertExpiresAt
                    ]);
                    $appDb->commit();
                } catch (Exception $txEx) {
                    if ($appDb->inTransaction()) $appDb->rollBack();
                    throw $txEx;
                }

                // Token-link flow: may be clicked with no session, so attribute
                // the change explicitly to the account owner.
                AuditLog::log('security', 'email_change_completed', [
                    'old_email' => $oldEmail,
                    'new_email' => $newEmail
                ], $contactId, $contactId);

                // Keep the viewer's session consistent if the owner clicked the
                // link while logged in. Never log anyone in from this page.
                if (Auth::check() && (int)$_SESSION['user']['contact_id'] === $contactId) {
                    $_SESSION['user']['email'] = $newEmail;
                }

                // Post-commit best-effort steps: none of these may block or
                // roll back the completed change.
                $baseUrl = rtrim($_ENV['BASE_URL'] ?? 'http://localhost/member', '/');
                try {
                    MailHelper::sendTemplate($oldEmail, 'email_change_completed', [
                        'display_name' => $contact['display_name'] ?? 'Member',
                        'old_email' => $oldEmail,
                        'new_email' => $newEmail,
                        'revert_link' => $baseUrl . '/revert-email-change.php?token=' . $rawRevertToken,
                        'revert_expires' => '72 hours'
                    ], $contactId, null);
                } catch (Exception $mailEx) {
                    error_log("Failed to send email-change completed notice to old address for contact {$contactId}: " . $mailEx->getMessage());
                }

                BillingHelper::syncStripeCustomerEmail($contactId, $newEmail);

                // Hygiene: password reset tokens are keyed by email; any issued
                // to the old address are inert now, so clear them out.
                try {
                    $appDb->prepare("DELETE FROM tgg_password_resets WHERE email = :email")->execute(['email' => $oldEmail]);
                } catch (Exception $cleanEx) {
                    error_log("Failed to clean up old-email password resets for contact {$contactId}: " . $cleanEx->getMessage());
                }

                $successMsg = "Your login email is now " . $newEmail . ". Use it the next time you sign in.";
            }
        }
    } catch (Exception $e) {
        $errorMsg = safe_err("Could not verify your email change: ", $e);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Email Change - TGG Member Portal</title>
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
                <h2>Email Change Verification</h2>

                <?php if ($errorMsg): ?>
                    <div class="alert alert-danger"><?php echo e($errorMsg); ?></div>
                    <br>
                    <a href="index.php" class="btn btn-primary">Back to Home</a>
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
