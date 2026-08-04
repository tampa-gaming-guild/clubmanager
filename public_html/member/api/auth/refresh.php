<?php
/**
 * Mobile API: exchange a refresh token for a new access/refresh token pair.
 * The refresh token is rotated (old one revoked) on every successful call.
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

use App\ApiAuth;
use App\RateLimiter;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
if (!RateLimiter::attempt('api_refresh:' . $ip, 30, 900)) {
    json_response(['error' => 'Too many requests. Please try again later.'], 429);
}

$body = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
$refreshToken = (string)($body['refresh_token'] ?? $_POST['refresh_token'] ?? '');

if ($refreshToken === '') {
    json_response(['error' => 'refresh_token is required'], 400);
}

$tokens = ApiAuth::refresh($refreshToken);
if ($tokens === false) {
    json_response(['error' => 'Invalid, expired, or revoked refresh token'], 401);
}

json_response($tokens);
