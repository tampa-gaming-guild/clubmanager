<?php
/**
 * Mobile API: create a Stripe Checkout session for the caller's OWN
 * membership renewal, mirroring renew.php's self-service Stripe branch.
 * Always the token's own contact_id -- self-service only, no override (that
 * distinct capability is host/checkout-session.php, gated on
 * 'edit checkins', for renewing someone else).
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
use App\Database;
use App\StripeHelper;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$user = ApiAuth::requireAuth();
$contactId = (int)$user['contact_id'];

$body = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
$planId = (int)($body['plan_id'] ?? 0);
if ($planId <= 0) {
    json_response(['error' => 'Invalid request.'], 400);
}

$appDb = Database::getAppConnection();
$contactStmt = $appDb->prepare("SELECT display_name, email FROM tgg_contacts WHERE id = :id AND is_deleted = 0 LIMIT 1");
$contactStmt->execute(['id' => $contactId]);
$contact = $contactStmt->fetch();
if (!$contact) {
    json_response(['error' => 'Member not found.'], 404);
}

$tiers = array_values(array_filter(
    BillingHelper::getSubscriptionPlans(true),
    fn($tier) => !BillingHelper::isTrialPlan($tier)
));
$tierIndex = array_search($planId, array_column($tiers, 'id'));
if ($tierIndex === false) {
    json_response(['error' => 'Invalid membership level.'], 400);
}
$tier = $tiers[$tierIndex];

$membership = BillingHelper::getMemberSubscriptionDetails($contactId);
$fee = (float)$tier['minimum_fee'];
if ($membership && (int)$membership['membership_id'] === $planId) {
    $fee = (float)$membership['minimum_fee'];
}

try {
    $session = StripeHelper::createCheckoutSession(
        $contactId,
        $planId,
        (int)$tier['civicrm_membership_type_id'],
        $tier['name'],
        $fee,
        'renew',
        $contact['email'],
        $contact['display_name'],
        'renew.php'
    );

    json_response(['checkout_url' => $session['url']]);
} catch (Exception $e) {
    json_response(['error' => safe_err('Failed to start card checkout: ', $e)], 400);
}
