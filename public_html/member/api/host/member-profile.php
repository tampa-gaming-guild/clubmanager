<?php
/**
 * Mobile API (host tools): another member's profile view for the host to
 * reference during renewals -- contact info, membership status,
 * Membership Credits, attendance, and payment history. Read-only mirror of
 * api/profile.php's GET, parameterized instead of always-self, gated on
 * 'edit checkins' like the rest of host/*.php. No 3-row "View All" paging
 * here (unlike the member's own profile) -- keeps this to what a host
 * actually needs at a glance.
 */
require_once (function() {
    $dir = dirname(dirname(dirname(dirname(__DIR__))));
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
use App\MembershipCredits;
use App\MembershipService;

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['error' => 'Method not allowed'], 405);
}

ApiAuth::requirePermission('edit checkins');

$contactId = (int)($_GET['contact_id'] ?? 0);
if ($contactId <= 0) {
    json_response(['error' => 'Invalid member.'], 400);
}

$appDb = Database::getAppConnection();

$contactStmt = $appDb->prepare("SELECT display_name, email, phone FROM tgg_contacts WHERE id = :id AND is_deleted = 0 LIMIT 1");
$contactStmt->execute(['id' => $contactId]);
$contact = $contactStmt->fetch();
if (!$contact) {
    json_response(['error' => 'Member not found'], 404);
}

$attendanceStmt = $appDb->prepare("
    SELECT checked_in_at, notes, guest_name
    FROM tgg_checkins
    WHERE contact_id = :contact_id
    ORDER BY checked_in_at DESC
    LIMIT 3
");
$attendanceStmt->execute(['contact_id' => $contactId]);

$ledgerStmt = $appDb->prepare("
    SELECT l.created_at, l.amount, l.currency, l.action_type, l.payment_status, p.name AS plan_name
    FROM tgg_billing_ledger l
    INNER JOIN tgg_subscription_plans p ON l.plan_id = p.id
    WHERE l.contact_id = :contact_id
    ORDER BY l.created_at DESC
    LIMIT 3
");
$ledgerStmt->execute(['contact_id' => $contactId]);

// Drives whether the Renew screen offers "Charge Card on File" -- see
// BillingHelper::processOfflineRenewal()'s 'card_on_file' payment method.
$billingStmt = $appDb->prepare("SELECT stripe_customer_id, stripe_payment_method_id FROM tgg_subscriptions WHERE contact_id = :id LIMIT 1");
$billingStmt->execute(['id' => $contactId]);
$billingRow = $billingStmt->fetch();
$hasCardOnFile = !empty($billingRow['stripe_customer_id']) && !empty($billingRow['stripe_payment_method_id']);

json_response([
    'contact' => [
        'contact_id' => $contactId,
        'display_name' => $contact['display_name'],
        'email' => $contact['email'],
        'phone' => $contact['phone'],
    ],
    'membership' => MembershipService::getMemberMembershipDetails($contactId),
    'has_card_on_file' => $hasCardOnFile,
    'credits' => MembershipCredits::getCreditSummary($contactId),
    'credit_grants' => array_slice(MembershipCredits::getTransactionHistory($contactId), 0, 3),
    'recent_attendance' => $attendanceStmt->fetchAll(),
    'payment_history' => $ledgerStmt->fetchAll(),
]);
