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
     * @param int $planId Local plan ID
     * @param int $membershipTypeId CiviCRM membership type ID
     * @param string $membershipTypeName Name of membership (e.g. Annual Standard)
     * @param float $amount Amount to charge in USD
     * @param string $action 'join' or 'renew'
     * @param string|null $email Member email to pre-fill on the Stripe-hosted checkout page
     * @param string|null $name Member name to pre-fill on the Stripe-hosted checkout page
     * @param string $returnPage Which page Stripe should redirect back to ('join.php', 'renew.php',
     *        or 'pay-entrance.php') -- this is the page that initiated the session, not necessarily
     *        implied by $action, since join.php now also handles self-service renewals for the
     *        public (no-login) entry point.
     * @param array $returnParams Extra query params appended to the pay-entrance.php success/cancel
     *        URLs (e.g. 'reason', 'return') so it knows which kiosk and flow to resume.
     * @return array Checkout session response from Stripe
     * @throws Exception
     */
    public static function createCheckoutSession(int $contactId, int $planId, int $membershipTypeId, string $membershipTypeName, float $amount, string $action, ?string $email = null, ?string $name = null, string $returnPage = 'join.php', array $returnParams = []): array {
        $secretKey = $_ENV['STRIPE_SECRET_KEY'] ?? '';
        if (empty($secretKey)) {
            throw new Exception("Stripe Secret Key is not configured in environment.");
        }

        $baseUrl = rtrim($_ENV['BASE_URL'] ?? 'http://localhost/member', '/');

        if ($returnPage === 'renew.php') {
            $successUrl = "{$baseUrl}/renew.php?status=success&session_id={CHECKOUT_SESSION_ID}&contact_id={$contactId}";
            $cancelUrl = "{$baseUrl}/renew.php?status=cancelled&contact_id={$contactId}";
        } elseif ($returnPage === 'pay-entrance.php') {
            $extra = '';
            foreach ($returnParams as $key => $val) {
                $extra .= '&' . urlencode($key) . '=' . urlencode($val);
            }
            $successUrl = "{$baseUrl}/pay-entrance.php?status=success&session_id={CHECKOUT_SESSION_ID}&contact_id={$contactId}{$extra}";
            $cancelUrl = "{$baseUrl}/pay-entrance.php?status=cancelled&contact_id={$contactId}{$extra}";
        } else {
            $successUrl = "{$baseUrl}/join.php?status=success&session_id={CHECKOUT_SESSION_ID}";
            $cancelUrl = "{$baseUrl}/join.php?status=cancelled";
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
            // Saves the resulting payment method against the Customer so it can later be
            // charged off-session (auto-renewal), not just used for this one payment.
            'payment_intent_data' => ['setup_future_usage' => 'off_session'],
            'metadata' => [
                'contact_id' => $contactId,
                'plan_id' => $planId,
                'membership_type_id' => $membershipTypeId,
                'action' => $action
            ]
        ];

        // A Customer object is required (not just customer_email) so the saved payment
        // method above has something to attach to for later off-session auto-renewal charges.
        if (!empty($email)) {
            $customer = self::createCustomer($email, $name ?? '');
            $fields['customer'] = $customer['id'];
        }

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

        // Defense-in-depth: callers redirect the browser straight to $data['url'],
        // so confirm it actually points at Stripe before handing it back.
        $host = parse_url($data['url'] ?? '', PHP_URL_HOST);
        if ($host !== 'checkout.stripe.com') {
            throw new Exception("Unexpected Stripe checkout URL host: " . ($host ?? 'none'));
        }

        return $data;
    }

    /**
     * Create a Stripe Customer so both name and email can be pre-filled on the Checkout page
     * (the Checkout Session API only supports prefilling email directly via customer_email;
     * prefilling name requires an associated Customer object). Also used whenever $name is
     * unavailable -- a Customer object is still required to attach a saved payment method to
     * for later off-session auto-renewal charges, even without a name to pre-fill.
     * @param string $email
     * @param string $name
     * @return array Customer object response from Stripe
     * @throws Exception
     */
    private static function createCustomer(string $email, string $name = ''): array {
        $secretKey = $_ENV['STRIPE_SECRET_KEY'] ?? '';
        if (empty($secretKey)) {
            throw new Exception("Stripe Secret Key is not configured in environment.");
        }

        $fields = ['email' => $email];
        if (!empty($name)) {
            $fields['name'] = $name;
        }

        $ch = curl_init("https://api.stripe.com/v1/customers");
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
            $error = $data['error']['message'] ?? 'Unknown Stripe Customer Error';
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
     * List a Customer's attached card payment methods (read-only), most-recently-attached first
     * (Stripe's default list ordering). Used as a fallback when a Customer has no
     * `invoice_settings.default_payment_method` set but does have at least one card on file.
     * @param string $customerId Stripe Customer ID (e.g. 'cus_...')
     * @param string|null $apiKey Optional key to use instead of $_ENV['STRIPE_SECRET_KEY'] --
     *        see retrieveCustomer() above for why.
     * @return array List of PaymentMethod objects
     * @throws Exception
     */
    public static function listPaymentMethods(string $customerId, ?string $apiKey = null): array {
        $secretKey = $apiKey ?? ($_ENV['STRIPE_SECRET_KEY'] ?? '');
        if (empty($secretKey)) {
            throw new Exception("Stripe Secret Key is not configured.");
        }

        $ch = curl_init("https://api.stripe.com/v1/payment_methods?" . http_build_query([
            'customer' => $customerId,
            'type' => 'card',
        ]));
        curl_setopt($ch, CURLOPT_USERPWD, $secretKey . ":");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true);

        if ($httpCode !== 200) {
            $error = $data['error']['message'] ?? 'Unknown Stripe list payment methods error';
            throw new Exception("Stripe API Error: " . $error);
        }

        return $data['data'] ?? [];
    }

    /**
     * Retrieve a PaymentIntent from Stripe (used to read back the payment_method and customer
     * attached to a completed Checkout Session -- the webhook payload only includes the
     * PaymentIntent ID as a string, not its expanded fields).
     * @param string $paymentIntentId Stripe PaymentIntent ID (e.g. 'pi_...')
     * @return array
     * @throws Exception
     */
    public static function retrievePaymentIntent(string $paymentIntentId): array {
        $secretKey = $_ENV['STRIPE_SECRET_KEY'] ?? '';
        if (empty($secretKey)) {
            throw new Exception("Stripe Secret Key is not configured.");
        }

        $ch = curl_init("https://api.stripe.com/v1/payment_intents/" . urlencode($paymentIntentId));
        curl_setopt($ch, CURLOPT_USERPWD, $secretKey . ":");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true);

        if ($httpCode !== 200) {
            $error = $data['error']['message'] ?? 'Unknown Stripe retrieve payment intent error';
            throw new Exception("Stripe API Error: " . $error);
        }

        return $data;
    }

    /**
     * Retrieve a Customer from Stripe (read-only). Used to resolve a Customer's default saved
     * payment method -- e.g. for legacy contacts whose Stripe Customer ID is known (from a prior
     * system) but whose PaymentMethod ID isn't recorded locally yet.
     * @param string $customerId Stripe Customer ID (e.g. 'cus_...')
     * @param string|null $apiKey Optional key to use instead of $_ENV['STRIPE_SECRET_KEY'] --
     *        e.g. bin/backfill-stripe-tokens.php passes its own restricted, read-only key here
     *        rather than relying on whatever key the rest of the app is configured with.
     * @return array
     * @throws Exception
     */
    public static function retrieveCustomer(string $customerId, ?string $apiKey = null): array {
        $secretKey = $apiKey ?? ($_ENV['STRIPE_SECRET_KEY'] ?? '');
        if (empty($secretKey)) {
            throw new Exception("Stripe Secret Key is not configured.");
        }

        $ch = curl_init("https://api.stripe.com/v1/customers/" . urlencode($customerId));
        curl_setopt($ch, CURLOPT_USERPWD, $secretKey . ":");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true);

        if ($httpCode !== 200) {
            $error = $data['error']['message'] ?? 'Unknown Stripe retrieve customer error';
            throw new Exception("Stripe API Error: " . $error);
        }

        return $data;
    }

    /**
     * Charge a previously-saved payment method off-session (no customer present), used for
     * automatic membership renewals. Unlike the other Stripe methods here, a card decline is
     * an expected, recoverable business outcome -- not an exception -- so it's returned as
     * ['success' => false, ...] rather than thrown. Genuine API/network/configuration errors
     * still throw.
     *
     * Known limitation: a PaymentIntent that comes back 'requires_action' (e.g. 3D Secure /
     * Strong Customer Authentication challenges, which can't be completed off-session) is
     * treated the same as a decline. There is no webhook-based async confirmation flow here.
     *
     * @param string $customerId Stripe Customer ID
     * @param string $paymentMethodId Stripe PaymentMethod ID
     * @param float $amount Amount to charge in USD
     * @param string $currency Three-letter currency code (e.g. 'usd')
     * @param string $description Shown on the charge in the Stripe dashboard/receipt
     * @param array $metadata Arbitrary metadata to attach to the PaymentIntent
     * @return array ['success' => bool, 'payment_intent_id' => ?string, 'message' => ?string, 'decline_code' => ?string, 'raw' => array]
     * @throws Exception
     */
    public static function chargeOffSession(string $customerId, string $paymentMethodId, float $amount, string $currency, string $description, array $metadata = []): array {
        $secretKey = $_ENV['STRIPE_SECRET_KEY'] ?? '';
        if (empty($secretKey)) {
            throw new Exception("Stripe Secret Key is not configured in environment.");
        }

        $fields = [
            'amount' => (int)round($amount * 100),
            'currency' => strtolower($currency),
            'customer' => $customerId,
            'payment_method' => $paymentMethodId,
            'off_session' => 'true',
            'confirm' => 'true',
            'description' => $description,
            'metadata' => $metadata
        ];

        $ch = curl_init("https://api.stripe.com/v1/payment_intents");
        curl_setopt($ch, CURLOPT_USERPWD, $secretKey . ":");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true);

        if ($httpCode === 200 && ($data['status'] ?? '') === 'succeeded') {
            return ['success' => true, 'payment_intent_id' => $data['id'], 'message' => null, 'decline_code' => null, 'raw' => $data];
        }

        if ($httpCode === 402 || in_array($data['status'] ?? '', ['requires_action', 'requires_payment_method'], true)) {
            $error = $data['error'] ?? [];
            return [
                'success' => false,
                'payment_intent_id' => $data['id'] ?? ($error['payment_intent']['id'] ?? null),
                'message' => $error['message'] ?? 'Card declined',
                'decline_code' => $error['decline_code'] ?? null,
                'raw' => $data
            ];
        }

        $error = $data['error']['message'] ?? 'Unknown Stripe off-session charge error';
        throw new Exception("Stripe API Error: " . $error);
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
