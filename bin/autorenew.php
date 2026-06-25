#!/usr/bin/env php
<?php
/**
 * Daily auto-renewal cron job.
 * Sends 5-day-advance renewal reminders, then charges any subscription whose membership
 * period has ended and has auto-renew enabled using the Stripe card on file.
 */
require_once dirname(__DIR__) . '/config/bootstrap.php';

use App\Database;
use App\BillingHelper;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script may only be run from the CLI.\n");
}

$reminders = BillingHelper::sendAutoRenewalReminders();
echo "[autorenew] Sent {$reminders['sent']} upcoming-renewal reminder(s).\n";

$appDb = Database::getAppConnection();
$stmt = $appDb->query("
    SELECT contact_id FROM tgg_subscriptions
    WHERE auto_renew = 1 AND status = 'active' AND end_date <= CURRENT_DATE()
");
$contactIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

$charged = 0;
$declined = 0;
$expired = 0;
$skipped = 0;
$errors = 0;

foreach ($contactIds as $contactId) {
    try {
        $result = BillingHelper::processAutoRenewalCharge((int)$contactId);
        switch ($result['result']) {
            case 'charged':
                $charged++;
                break;
            case 'declined':
                $declined++;
                break;
            case 'expired':
                $expired++;
                break;
            case 'error':
                $errors++;
                break;
            default:
                $skipped++;
                break;
        }
        echo "[autorenew] contact_id={$contactId}: {$result['result']} - {$result['message']}\n";
    } catch (\Throwable $e) {
        $errors++;
        error_log("[autorenew] contact_id={$contactId}: ERROR - " . $e->getMessage());
    }
}

$summary = "[autorenew] Summary: " . count($contactIds) . " due, {$charged} charged, {$declined} declined, "
    . "{$expired} expired, {$skipped} skipped, {$errors} errors.";
echo $summary . "\n";
