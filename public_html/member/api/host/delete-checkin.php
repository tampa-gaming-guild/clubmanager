<?php
/**
 * Mobile API (host tools): delete a check-in log entry, mirroring
 * index.php's Check-Ins Log delete action. Requires 'edit checkins'.
 */
require_once dirname(dirname(dirname(dirname(__DIR__)))) . '/config/bootstrap.php';

use App\ApiAuth;
use App\Database;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

ApiAuth::requirePermission('edit checkins');

$body = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
$checkinId = (int)($body['checkin_id'] ?? 0);
if ($checkinId <= 0) {
    json_response(['success' => false, 'error' => 'Invalid check-in ID.'], 400);
}

$appDb = Database::getAppConnection();
$appDb->prepare("DELETE FROM tgg_checkins WHERE id = :id")->execute(['id' => $checkinId]);

json_response(['success' => true]);
