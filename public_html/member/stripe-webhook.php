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
if (!empty($webhookSecret)) {
    if (!StripeHelper::verifyWebhookSignature($payload, $sigHeader, $webhookSecret)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid webhook signature']);
        exit;
    }
}

// 3. Parse Event
$event = json_decode($payload, true);

if ($event['type'] === 'checkout.session.completed') {
    $session = $event['data']['object'];
    
    $metadata = $session['metadata'] ?? null;
    if (!$metadata || !isset($metadata['contact_id'], $metadata['membership_type_id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing metadata in session']);
        exit;
    }

    $contactId = (int)$metadata['contact_id'];
    $membershipTypeId = (int)$metadata['membership_type_id'];
    $action = $metadata['action'] ?? 'join';
    $amountTotal = (float)($session['amount_total'] / 100);
    $trxnId = $session['payment_intent'] ?? $session['id'];

    try {
        $civiDb = Database::getCiviConnection();
        $civiDb->beginTransaction();

        // A. Insert record into civicrm_contribution (CiviContribute)
        $insertContribution = $civiDb->prepare("
            INSERT INTO civicrm_contribution (contact_id, financial_type_id, receive_date, total_amount, trxn_id, contribution_status_id) 
            VALUES (:contact_id, 1, NOW(), :total_amount, :trxn_id, 1)
        ");
        $insertContribution->execute([
            'contact_id' => $contactId,
            'total_amount' => $amountTotal,
            'trxn_id' => $trxnId
        ]);

        // B. Fetch Membership Type duration details
        $typeQuery = $civiDb->prepare("SELECT duration_unit, duration_interval FROM civicrm_membership_type WHERE id = :id LIMIT 1");
        $typeQuery->execute(['id' => $membershipTypeId]);
        $type = $typeQuery->fetch();

        if (!$type) {
            throw new Exception("Membership type ID {$membershipTypeId} not found.");
        }

        $durationInterval = (int)$type['duration_interval'];
        $durationUnit = strtolower($type['duration_unit']); // 'month' or 'year'

        // C. Calculate dates for membership
        // Check if member already has a membership record
        $memberQuery = $civiDb->prepare("SELECT id, end_date FROM civicrm_membership WHERE contact_id = :contact_id LIMIT 1");
        $memberQuery->execute(['contact_id' => $contactId]);
        $existingMembership = $memberQuery->fetch();

        $today = date('Y-m-d');
        $startDate = $today;
        
        if ($existingMembership && $action === 'renew') {
            $existingEndDate = $existingMembership['end_date'];
            
            // If existing membership is still active, start renewal from day after current expiry
            if (strtotime($existingEndDate) >= strtotime($today)) {
                $startDate = date('Y-m-d', strtotime($existingEndDate . ' +1 day'));
            }
        }

        // Compute expiry date based on unit/interval
        $unitString = $durationUnit === 'month' ? 'month' : 'year';
        $endDate = date('Y-m-d', strtotime($startDate . " +{$durationInterval} {$unitString}"));

        if ($existingMembership) {
            // Update existing membership (CiviMember)
            // status_id = 2 represents 'Current' membership status
            $updateMembership = $civiDb->prepare("
                UPDATE civicrm_membership 
                SET membership_type_id = :membership_type_id, start_date = :start_date, end_date = :end_date, status_id = 2 
                WHERE id = :id
            ");
            $updateMembership->execute([
                'membership_type_id' => $membershipTypeId,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'id' => (int)$existingMembership['id']
            ]);
        } else {
            // Create new membership (CiviMember)
            $insertMembership = $civiDb->prepare("
                INSERT INTO civicrm_membership (contact_id, membership_type_id, join_date, start_date, end_date, status_id) 
                VALUES (:contact_id, :membership_type_id, :join_date, :start_date, :end_date, 2)
            ");
            $insertMembership->execute([
                'contact_id' => $contactId,
                'membership_type_id' => $membershipTypeId,
                'join_date' => $today,
                'start_date' => $startDate,
                'end_date' => $endDate
            ]);
        }

        $civiDb->commit();
        echo json_encode(['status' => 'success', 'message' => 'Membership processed successfully']);

    } catch (Exception $e) {
        if (isset($civiDb) && $civiDb->inTransaction()) {
            $civiDb->rollBack();
        }
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// Respond 200 to unsupported event types to acknowledge receipt
echo json_encode(['status' => 'received']);
