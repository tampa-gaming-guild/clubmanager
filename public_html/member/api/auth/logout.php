<?php
/**
 * Mobile API: revoke a refresh token (log out this device).
 */
require_once dirname(dirname(dirname(dirname(__DIR__)))) . '/config/bootstrap.php';

use App\ApiAuth;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$body = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
$refreshToken = (string)($body['refresh_token'] ?? $_POST['refresh_token'] ?? '');

if ($refreshToken === '') {
    json_response(['error' => 'refresh_token is required'], 400);
}

ApiAuth::revoke($refreshToken);
json_response(['success' => true]);
