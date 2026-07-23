<?php
/**
 * Mobile API: exchange email + password for an access/refresh token pair.
 * See ApiAuth for token shapes/lifetimes.
 */
require_once dirname(dirname(dirname(dirname(__DIR__)))) . '/config/bootstrap.php';

use App\ApiAuth;
use App\RateLimiter;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

// Per-IP throttling: unlike the web login form, this endpoint is reachable
// directly by any HTTP client, so the per-account lockout in
// tgg_member_settings isn't sufficient on its own.
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
if (!RateLimiter::attempt('api_login:' . $ip, 10, 900)) {
    json_response(['error' => 'Too many login attempts. Please try again later.'], 429);
}

$body = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
$email = trim((string)($body['email'] ?? $_POST['email'] ?? ''));
$password = (string)($body['password'] ?? $_POST['password'] ?? '');
$deviceLabel = trim((string)($body['device_label'] ?? $_POST['device_label'] ?? '')) ?: null;

if ($email === '' || $password === '') {
    json_response(['error' => 'Email and password are required'], 400);
}

try {
    $tokens = ApiAuth::login($email, $password, $deviceLabel);
} catch (Exception $e) {
    // Account-lockout exceptions (code 423) carry a user-facing message, same as the web login form.
    json_response(['error' => safe_err('', $e)], 423);
}

if ($tokens === false) {
    json_response(['error' => 'Invalid email or password'], 401);
}

json_response($tokens);
