<?php
/**
 * Mobile API (host tools): live member search by name/email/phone, mirrors
 * host_checkin.php's search action. Requires 'edit checkins'.
 */
require_once (function() {
    $dir = dirname(dirname(dirname(__DIR__)));
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

ApiAuth::requirePermission('edit checkins');

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 2) {
    json_response([]);
}

$appDb = Database::getAppConnection();
$qPhone = normalize_phone($q);
$sql = "
    SELECT id, display_name, email, phone
    FROM tgg_contacts
    WHERE (display_name LIKE :q1 OR email LIKE :q2" . ($qPhone !== '' ? " OR REGEXP_REPLACE(phone, '[^0-9]', '') LIKE :q3" : "") . ")
      AND is_deleted = 0
    LIMIT 15
";
$stmt = $appDb->prepare($sql);
$params = ['q1' => '%' . $q . '%', 'q2' => '%' . $q . '%'];
if ($qPhone !== '') {
    $params['q3'] = '%' . $qPhone . '%';
}
$stmt->execute($params);

json_response($stmt->fetchAll());
