<?php
/**
 * Mobile API: the caller's full attendance history, paginated for infinite
 * scroll -- the "View All" screen behind profile.php's 3-row teaser.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config/bootstrap.php';

use App\ApiAuth;
use App\Database;

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['error' => 'Method not allowed'], 405);
}

$user = ApiAuth::requireAuth();
$contactId = (int)$user['contact_id'];

$offset = max(0, (int)($_GET['offset'] ?? 0));
$limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));

$appDb = Database::getAppConnection();
$stmt = $appDb->prepare("
    SELECT checked_in_at, notes, guest_name
    FROM tgg_checkins
    WHERE contact_id = :contact_id
    ORDER BY checked_in_at DESC
    LIMIT :limit OFFSET :offset
");
$stmt->bindValue('contact_id', $contactId, PDO::PARAM_INT);
$stmt->bindValue('limit', $limit + 1, PDO::PARAM_INT);
$stmt->bindValue('offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();

$hasMore = count($rows) > $limit;
json_response(['rows' => array_slice($rows, 0, $limit), 'has_more' => $hasMore]);
