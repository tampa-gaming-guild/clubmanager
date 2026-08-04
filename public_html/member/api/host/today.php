<?php
/**
 * Mobile API (host tools): today's check-in list, for a host to see who's
 * already arrived. Requires 'edit checkins'.
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
use App\Event;

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['error' => 'Method not allowed'], 405);
}

ApiAuth::requirePermission('edit checkins');

json_response(['checkins' => Event::getTodaysCheckins()]);
