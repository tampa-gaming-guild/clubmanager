<?php
/**
 * Mobile API: request a password-reset email (same email + code as the
 * website's forgot-password.php, sent via Auth::createPasswordSetupToken()
 * + the password_reset_link template). Unauthenticated -- this is the
 * logged-out "I forgot my password" entry point.
 */
require_once (function() {
    $dir = dirname(dirname(dirname(dirname(__DIR__))));
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
use App\AuditLog;
use App\Database;
use App\MailHelper;
use App\RateLimiter;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

// Tighter than api_login's 10/900: this endpoint triggers an email send,
// so abuse is both an enumeration risk and a mail-spam risk.
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
if (!RateLimiter::attempt('api_forgot_password:' . $ip, 5, 900)) {
    json_response(['error' => 'Too many requests. Please try again later.'], 429);
}

$body = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
$email = trim(strtolower((string)($body['email'] ?? $_POST['email'] ?? '')));

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_response(['error' => 'Please enter a valid email address'], 400);
}

$appDb = Database::getAppConnection();
$stmt = $appDb->prepare("SELECT id, display_name FROM tgg_contacts WHERE email = :email AND is_deleted = 0 LIMIT 1");
$stmt->execute(['email' => $email]);
$contactRow = $stmt->fetch();

if ($contactRow) {
    $contactId = (int)$contactRow['id'];
    $displayName = $contactRow['display_name'] ?? 'Member';

    $reset = Auth::createPasswordSetupToken($email, '+1 hour');
    $resetLink = rtrim($_ENV['BASE_URL'] ?? 'http://localhost/member', '/') . '/reset-password.php?token=' . $reset['token'];

    MailHelper::sendTemplate($email, 'password_reset_link', [
        'display_name' => $displayName,
        'reset_link' => $resetLink,
        'reset_code' => $reset['code'],
        'expires_in' => '1 hour',
    ], $contactId, null);

    AuditLog::log('security', 'password_reset_requested', ['email' => $email, 'via' => 'mobile_forgot'], $contactId);
}

// Anti-enumeration: identical response whether or not the email matched an
// account, same as the web forgot-password.php.
json_response(['success' => true, 'message' => 'If that email is registered, a reset code has been sent.']);
