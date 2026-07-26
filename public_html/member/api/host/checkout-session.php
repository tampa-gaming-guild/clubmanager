<?php
/**
 * Mobile API (host tools): create a Stripe Checkout session for a member's
 * renewal, for when they have no card on file (see host/renew.php's
 * 'card_on_file' method for when they do). Returns the checkout URL for the
 * app to open in an in-app webview; success/cancel point back at renew.php,
 * which verifies/records the payment itself (see its "Run BEFORE Auth
 * check" step) without needing a PHP session -- the webview never needs to
 * be logged into the web app for that step to work.
 */
require_once dirname(dirname(dirname(dirname(__DIR__)))) . '/config/bootstrap.php';

use App\ApiAuth;
use App\BillingHelper;
use App\Database;
use App\StripeHelper;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

ApiAuth::requirePermission('edit checkins');

$body = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
$contactId = (int)($body['contact_id'] ?? 0);
$planId = (int)($body['plan_id'] ?? 0);

if ($contactId <= 0 || $planId <= 0) {
    json_response(['error' => 'Invalid request.'], 400);
}

$appDb = Database::getAppConnection();

$contactStmt = $appDb->prepare("SELECT display_name, email FROM tgg_contacts WHERE id = :id AND is_deleted = 0 LIMIT 1");
$contactStmt->execute(['id' => $contactId]);
$contact = $contactStmt->fetch();
if (!$contact) {
    json_response(['error' => 'Member not found.'], 404);
}

$tiers = BillingHelper::getSubscriptionPlans(true);
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
