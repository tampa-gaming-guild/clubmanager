<?php
/**
 * Mobile API (host tools): the renewable plan list for the Renew form,
 * mirroring renew.php's $tiers -- all active plans except Trial, which is a
 * one-time join-only offer, never a renewal choice.
 */
require_once dirname(dirname(dirname(dirname(__DIR__)))) . '/config/bootstrap.php';

use App\ApiAuth;
use App\BillingHelper;

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['error' => 'Method not allowed'], 405);
}

ApiAuth::requirePermission('edit checkins');

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
