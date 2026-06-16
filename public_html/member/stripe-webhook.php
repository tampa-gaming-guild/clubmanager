<?php
/**
 * Stripe Webhook Endpoint
 * Listens for checkout.session.completed events to finalize membership activation/renewal in CiviCRM.
 */
require_once dirname(dirname(__DIR__)) . '/config/bootstrap.php';

use App\Database;
use App\StripeHelper;

// 1. Retrieve Webhook Secret and Signature Header
$webhookSecret = $_ENV['STRIPE_WEBHOOK_SECRET'] ?? '';
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

// Get Raw Request Body
$payload = file_get_contents('php://input');

if (empty($payload)) {
    http_response_code(400);
    echo json_encode(['error' => 'Empty payload']);
    exit;
}

// 2. Verify Signature
if (empty($webhookSecret)) {
    http_response_code(500);
    echo json_encode(['error' => 'Webhook secret configuration missing']);
    exit;
}

if (!StripeHelper::verifyWebhookSignature($payload, $sigHeader, $webhookSecret)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid webhook signature']);
    exit;
}

// 3. Parse Event
$event = json_decode($payload, true);

if ($event['type'] === 'checkout.session.completed') {
    $session = $event['data']['object'];
    
    try {
        \App\BillingHelper::processCheckoutSession($session);
        echo json_encode(['status' => 'success', 'message' => 'Membership processed successfully']);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// Respond 200 to unsupported event types to acknowledge receipt
echo json_encode(['status' => 'received']);
