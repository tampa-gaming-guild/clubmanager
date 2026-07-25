<?php
/**
 * Mobile API (host tools): today's check-in list, for a host to see who's
 * already arrived. Requires 'edit checkins'.
 */
require_once dirname(dirname(dirname(dirname(__DIR__)))) . '/config/bootstrap.php';

use App\ApiAuth;
use App\Event;

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['error' => 'Method not allowed'], 405);
}

ApiAuth::requirePermission('edit checkins');

json_response(['checkins' => Event::getTodaysCheckins()]);
