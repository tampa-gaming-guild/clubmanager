<?php
namespace App;

use Exception;

/**
 * Stripe Payment Helper
 * Zero-dependency wrapper for Stripe Checkout API and Webhook verification.
 */
class StripeHelper {

    /**
     * Create a Stripe Checkout Session
     * @param int $contactId CiviCRM contact ID
     * @param int $membershipTypeId CiviCRM membership type ID
     * @param string $membershipTypeName Name of membership (e.g. Annual Standard)
     * @param float $amount Amount to charge in USD
     * @param string $action 'join' or 'renew'
     * @return array Checkout session response from Stripe
     * @throws Exception
     */
    public static function createCheckoutSession(int $contactId, int $membershipTypeId, string $membershipTypeName, float $amount, string $action): array {
        $secretKey = $_ENV['STRIPE_SECRET_KEY'] ?? '';
        if (empty($secretKey)) {
            throw new Exception("Stripe Secret Key is not configured in environment.");
        }

        $baseUrl = rtrim($_ENV['BASE_URL'] ?? 'http://localhost/member', '/');
        $successUrl = "{$baseUrl}/join.php?status=success&session_id={CHECKOUT_SESSION_ID}";
        $cancelUrl = "{$baseUrl}/join.php?status=cancelled";

        if ($action === 'renew') {
            $successUrl = "{$baseUrl}/renew.php?status=success&session_id={CHECKOUT_SESSION_ID}";
            $cancelUrl = "{$baseUrl}/renew.php?status=cancelled";
        }

        $ch = curl_init("https://api.stripe.com/v1/checkout/sessions");
        
        $fields = [
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price_data' => [
                    'currency' => 'usd',
                    'product_data' => [
                        'name' => $membershipTypeName,
                        'description' => ucfirst($action) . " Membership Dues",
                    ],
                    'unit_amount' => (int)($amount * 100), // Stripe expects amount in cents
                ],
                'quantity' => 1,
            ]],
            'mode' => 'payment',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'metadata' => [
                'contact_id' => $contactId,
                'membership_type_id' => $membershipTypeId,
                'action' => $action
            ]
        ];

        curl_setopt($ch, CURLOPT_USERPWD, $secretKey . ":");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true);

        if ($httpCode !== 200) {
            $error = $data['error']['message'] ?? 'Unknown Stripe Checkout Error';
            throw new Exception("Stripe API Error: " . $error);
        }

        return $data;
    }

    /**
     * Retrieve a Checkout Session from Stripe (used to verify payments on success page redirect)
     * @param string $sessionId Stripe Checkout Session ID
     * @return array
     * @throws Exception
     */
    public static function retrieveCheckoutSession(string $sessionId): array {
        $secretKey = $_ENV['STRIPE_SECRET_KEY'] ?? '';
        if (empty($secretKey)) {
            throw new Exception("Stripe Secret Key is not configured.");
        }

        $ch = curl_init("https://api.stripe.com/v1/checkout/sessions/" . urlencode($sessionId));
        curl_setopt($ch, CURLOPT_USERPWD, $secretKey . ":");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true);

        if ($httpCode !== 200) {
            $error = $data['error']['message'] ?? 'Unknown Stripe retrieve session error';
            throw new Exception("Stripe API Error: " . $error);
        }

        return $data;
    }

    /**
     * Verify Stripe Webhook Signature natively
     * @param string $payload Raw HTTP POST request body
     * @param string $sigHeader Stripe-Signature header
     * @param string $secret Webhook signing secret
     * @return bool True if signature is valid, false otherwise
     */
    public static function verifyWebhookSignature(string $payload, string $sigHeader, string $secret): bool {
        if (empty($sigHeader) || empty($secret)) {
            return false;
        }

        $parts = explode(',', $sigHeader);
        $timestamp = null;
        $v1Sig = null;

        foreach ($parts as $part) {
            $kv = explode('=', $part, 2);
            if (count($kv) === 2) {
                $key = trim($kv[0]);
                $val = trim($kv[1]);
                if ($key === 't') {
                    $timestamp = $val;
                } elseif ($key === 'v1') {
                    $v1Sig = $val;
                }
            }
        }

        if (!$timestamp || !$v1Sig) {
            return false;
        }

        // Toleration: Check if timestamp is older than 5 minutes to mitigate replay attacks
        if (abs(time() - (int)$timestamp) > 300) {
            return false;
        }

        $signedPayload = $timestamp . '.' . $payload;
        $expectedSignature = hash_hmac('sha256', $signedPayload, $secret);

        return hash_equals($expectedSignature, $v1Sig);
    }
}
