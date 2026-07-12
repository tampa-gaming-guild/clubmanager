#!/usr/bin/env php
<?php
/**
 * One-time backfill: resolve a reusable Stripe Customer + PaymentMethod pair for each contact
 * imported from CiviCRM, so migrated members can be auto-renewed by bin/autorenew.php instead of
 * having to manually re-enter a card after cutover.
 *
 * CiviCRM's own tables (civicrm_stripe_customers) only ever recorded a Stripe Customer ID, never
 * a specific PaymentMethod ID, so that has to be resolved via one read-only Stripe API lookup per
 * contact (GET /v1/customers/{id}) -- see StripeHelper::retrieveCustomer(). This script makes NO
 * writes to Stripe: no charges, no subscription changes, no customer updates. Canceling any
 * still-active native Stripe Subscriptions from the old CiviCRM/Stripe integration is a separate,
 * manual step taken at cutover -- this script does not touch that.
 *
 * Requires a Stripe key that can see LIVE-mode Customer objects (the app's normal
 * STRIPE_SECRET_KEY is a local dev test-mode key, which will fail every lookup with "No such
 * customer" -- Stripe's test/live data is fully segregated, not just permission-gated). Rather
 * than temporarily swapping the app's main STRIPE_SECRET_KEY (which every other payment code
 * path also relies on) this script uses its own dedicated STRIPE_BACKFILL_SECRET_KEY env var,
 * so there's no risk of forgetting to swap it back or of it interfering with anything else.
 * Set it to a Stripe Restricted Key scoped to read-only Customers/PaymentMethods access, never
 * the full live secret key -- see .env.example for how to create one.
 *
 * Defaults to a dry run (logs what would be written, touches no data). Pass --apply to actually
 * update tgg_subscriptions.
 *
 * Usage: php bin/backfill-stripe-tokens.php [--apply]
 */
require_once dirname(__DIR__) . '/config/bootstrap.php';

use App\Database;
use App\StripeHelper;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script may only be run from the CLI.\n");
}

$backfillApiKey = $_ENV['STRIPE_BACKFILL_SECRET_KEY'] ?? '';
if (empty($backfillApiKey)) {
    exit("STRIPE_BACKFILL_SECRET_KEY is not set in .env -- this script needs its own Stripe Restricted Key "
        . "(read-only Customers/PaymentMethods access) to look up live customer data. See .env.example.\n");
}

$apply = in_array('--apply', $argv, true);

echo "[" . date('Y-m-d H:i:s') . "] [backfill-stripe-tokens] Starting" . ($apply ? " (APPLY mode -- will write to tgg_subscriptions)" : " (dry run -- no writes)") . "\n";

$appDb = Database::getAppConnection();
$civiDb = Database::getCiviConnection();

// Determine live (non-test) payment processor IDs dynamically rather than hardcoding one,
// since the specific ID can differ between CiviCRM installs.
$liveProcessorIds = $civiDb->query("SELECT id FROM civicrm_payment_processor WHERE is_test = 0")->fetchAll(PDO::FETCH_COLUMN);
if (empty($liveProcessorIds)) {
    echo "[" . date('Y-m-d H:i:s') . "] [backfill-stripe-tokens] No live (non-test) payment processor found in CiviCRM data; nothing to do.\n";
    exit(0);
}
$placeholders = implode(',', array_fill(0, count($liveProcessorIds), '?'));

$stmt = $civiDb->prepare("
    SELECT contact_id, customer_id
    FROM civicrm_stripe_customers
    WHERE processor_id IN ({$placeholders})
      AND customer_id IS NOT NULL AND customer_id != ''
");
$stmt->execute($liveProcessorIds);
$candidates = $stmt->fetchAll();

$resolved = 0;
$noPaymentMethod = 0;
$noLocalSubscription = 0;
$alreadyHasCard = 0;
$lookupErrors = 0;

$subCheckStmt = $appDb->prepare("SELECT contact_id, stripe_payment_method_id FROM tgg_subscriptions WHERE contact_id = :contact_id LIMIT 1");
$updateStmt = $appDb->prepare("
    UPDATE tgg_subscriptions
    SET stripe_customer_id = :stripe_customer_id, stripe_payment_method_id = :stripe_payment_method_id, auto_renew = 1
    WHERE contact_id = :contact_id
");

foreach ($candidates as $row) {
    $contactId = (int)$row['contact_id'];
    $customerId = $row['customer_id'];

    $subCheckStmt->execute(['contact_id' => $contactId]);
    $sub = $subCheckStmt->fetch();
    if (!$sub) {
        $noLocalSubscription++;
        echo "[" . date('Y-m-d H:i:s') . "] [backfill-stripe-tokens] contact_id={$contactId}: skipped, no local tgg_subscriptions row (not imported / no membership)\n";
        continue;
    }
    if (!empty($sub['stripe_payment_method_id'])) {
        $alreadyHasCard++;
        echo "[" . date('Y-m-d H:i:s') . "] [backfill-stripe-tokens] contact_id={$contactId}: skipped, already has a card on file\n";
        continue;
    }

    try {
        $customer = StripeHelper::retrieveCustomer($customerId, $backfillApiKey);
        $paymentMethodId = $customer['invoice_settings']['default_payment_method'] ?? null;

        if (empty($paymentMethodId)) {
            // Fall back to the most recently attached card if no default is explicitly set.
            $methods = StripeHelper::listPaymentMethods($customerId, $backfillApiKey);
            $paymentMethodId = $methods[0]['id'] ?? null;
        }

        if (empty($paymentMethodId)) {
            $noPaymentMethod++;
            echo "[" . date('Y-m-d H:i:s') . "] [backfill-stripe-tokens] contact_id={$contactId}, customer_id={$customerId}: no payment method on file, skipping\n";
            continue;
        }

        $resolved++;
        echo "[" . date('Y-m-d H:i:s') . "] [backfill-stripe-tokens] contact_id={$contactId}, customer_id={$customerId}: resolved payment_method_id={$paymentMethodId}"
            . ($apply ? " -- writing to tgg_subscriptions\n" : " -- would write to tgg_subscriptions (dry run)\n");

        if ($apply) {
            $updateStmt->execute([
                'stripe_customer_id' => $customerId,
                'stripe_payment_method_id' => $paymentMethodId,
                'contact_id' => $contactId,
            ]);
        }
    } catch (\Throwable $e) {
        $lookupErrors++;
        // Raw exception detail goes to the error log only (matches bin/autorenew.php's convention),
        // not to stdout -- stdout output from this script could end up copy-pasted into a ticket,
        // chat, or shared log without the same handling care as the PHP error log.
        error_log("[" . date('Y-m-d H:i:s') . "] [backfill-stripe-tokens] contact_id={$contactId}, customer_id={$customerId}: lookup failed - " . $e->getMessage());
        echo "[" . date('Y-m-d H:i:s') . "] [backfill-stripe-tokens] contact_id={$contactId}, customer_id={$customerId}: lookup failed, see error log for details\n";
    }
}

$summary = "[" . date('Y-m-d H:i:s') . "] [backfill-stripe-tokens] Summary: " . count($candidates) . " candidate(s), "
    . "{$resolved} resolved, {$alreadyHasCard} already had a card, {$noLocalSubscription} had no local subscription, "
    . "{$noPaymentMethod} had no payment method on file, {$lookupErrors} lookup error(s)."
    . ($apply ? "" : " (dry run -- no data was written; re-run with --apply to write)");
echo $summary . "\n";
