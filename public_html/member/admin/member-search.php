<?php
/**
 * Navbar member search AJAX endpoint.
 * Returns up to 10 contacts matching the query by name or email.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config/bootstrap.php';

use App\Auth;
use App\Database;

Auth::requireAuth();
if (empty($_SESSION['user']['permissions'] ?? [])) {
    json_response(['error' => 'Unauthorized'], 403);
}

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 3) {
    json_response([]);
}

try {
    $appDb = Database::getAppConnection();
    $like = '%' . $q . '%';
    $stmt = $appDb->prepare("
        SELECT id, display_name, email
        FROM tgg_contacts
        WHERE (display_name LIKE :q1 OR first_name LIKE :q2 OR last_name LIKE :q3 OR email LIKE :q4)
          AND is_deleted = 0
        ORDER BY display_name ASC
        LIMIT 10
    ");
    $stmt->execute(['q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like]);
    json_response($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) {
    json_response([], 500);
}
