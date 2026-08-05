<?php
/**
 * Mobile API: the caller's full Membership Credit grant history, paginated
 * for infinite scroll -- the "View All" screen behind profile.php's 3-row
 * teaser. getTransactionHistory() already computes the whole FIFO-ordered
 * list in one pass (most recent first); pagination here is just an in-memory
 * slice of that, not a separate SQL query, since "remaining" per grant only
 * makes sense computed against the full history.
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
use App\MembershipCredits;

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['error' => 'Method not allowed'], 405);
}

$user = ApiAuth::requireAuth();
$contactId = (int)$user['contact_id'];

$offset = max(0, (int)($_GET['offset'] ?? 0));
$limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));

$allGrants = MembershipCredits::getTransactionHistory($contactId);

json_response([
    'rows' => array_slice($allGrants, $offset, $limit),
    'has_more' => ($offset + $limit) < count($allGrants),
]);
