<?php
/**
 * Mobile API: complete a password reset with the emailed 6-digit code, in a
 * single call (no intermediate token exchange -- the code is verified and
 * the password applied in the same request). Unauthenticated. Shares its
 * security-sensitive logic with the website's code-entry/reset pages via
 * Auth::verifyResetCode() and Auth::completePasswordReset().
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
use App\RateLimiter;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

// Per-email code_attempts (enforced inside Auth::verifyResetCode()) is the
// primary brake on code-guessing; this IP bucket is the same secondary
// throttle the other unauthenticated auth endpoints use.
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
if (!RateLimiter::attempt('api_reset_password:' . $ip, 10, 900)) {
    json_response(['error' => 'Too many requests. Please try again later.'], 429);
}

$body = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
$email = trim(strtolower((string)($body['email'] ?? $_POST['email'] ?? '')));
$code = trim((string)($body['code'] ?? $_POST['code'] ?? ''));
$newPassword = (string)($body['new_password'] ?? $_POST['new_password'] ?? '');

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_response(['error' => 'Please enter a valid email address'], 400);
}
if (!preg_match('/^[0-9]{6}$/', $code)) {
    json_response(['error' => 'Please enter the 6-digit code from your email'], 400);
}
if ($newPassword === '') {
    json_response(['error' => 'Please enter a new password'], 400);
}

try {
    // A correct code is not consumed by verification alone, so a password
    // that fails complexity below can be retried with the same code.
    Auth::verifyResetCode($email, $code);
    Auth::completePasswordReset($email, $newPassword);
} catch (Exception $e) {
    json_response(['error' => $e->getMessage()], 400);
}

json_response(['success' => true, 'message' => 'Your password has been reset. You can now sign in.']);
