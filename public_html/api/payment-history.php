<?php
/**
 * Mobile API: the caller's full billing history, paginated for infinite
 * scroll -- the "View All" screen behind profile.php's 3-row teaser.
 */
require_once (function() {
    $dir = dirname(dirname(__DIR__));
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
    SELECT l.created_at, l.amount, l.currency, l.action_type, l.payment_status, p.name AS plan_name
    FROM tgg_billing_ledger l
    INNER JOIN tgg_subscription_plans p ON l.plan_id = p.id
    WHERE l.contact_id = :contact_id
    ORDER BY l.created_at DESC
    LIMIT :limit OFFSET :offset
");
$stmt->bindValue('contact_id', $contactId, PDO::PARAM_INT);
$stmt->bindValue('limit', $limit + 1, PDO::PARAM_INT);
$stmt->bindValue('offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();

$hasMore = count($rows) > $limit;
json_response(['rows' => array_slice($rows, 0, $limit), 'has_more' => $hasMore]);
