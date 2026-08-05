<?php
/**
 * Mobile API: payment context (entrance fee owed, or the renewable tier
 * list) for the caller's OWN check-in-blocking payment -- backs the native
 * Card/Cash + tier-picker screen used instead of loading pay-entrance.php
 * in a webview. See src/PaymentFlow.php for the shared logic.
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
use App\PaymentFlow;

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['error' => 'Method not allowed'], 405);
}

$user = ApiAuth::requireAuth();
$contactId = (int)$user['contact_id'];

$reason = ($_GET['reason'] ?? '') === 'entrance_fee' ? 'entrance_fee' : 'renewal';

if (PaymentFlow::hasPendingPayment($contactId)) {
    json_response(['has_pending_payment' => true]);
}

try {
    $context = $reason === 'entrance_fee' ? PaymentFlow::entranceFeeContext($contactId) : PaymentFlow::renewalContext($contactId);
    json_response(array_merge(['has_pending_payment' => false], $context));
} catch (Exception $e) {
    json_response(['error' => safe_err('', $e)], 400);
}
