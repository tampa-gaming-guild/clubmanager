<?php
/**
 * Mobile API: the renewable plan list (excludes Trial) for the member's own
 * self-service Renew screen -- mirrors host/subscription-plans.php but
 * requires only plain auth, not 'edit checkins', since this data (level
 * names/prices) isn't sensitive and every member needs it to renew.
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
use App\BillingHelper;

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['error' => 'Method not allowed'], 405);
}

ApiAuth::requireAuth();

$tiers = array_values(array_filter(
    BillingHelper::getSubscriptionPlans(true),
    fn($tier) => !BillingHelper::isTrialPlan($tier)
));

json_response([
    'plans' => array_map(fn($p) => [
        'id' => (int)$p['id'],
        'name' => $p['name'],
        'minimum_fee' => (float)$p['minimum_fee'],
        'duration_interval' => (int)$p['duration_interval'],
        'duration_unit' => $p['duration_unit'],
    ], $tiers),
]);
