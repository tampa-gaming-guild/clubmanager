<?php
namespace App;

use PDO;
use Exception;

/**
 * Billing Helper
 * Handles local billing transactions ledger, local subscription states, and CiviCRM sync.
 */
class BillingHelper {

    /**
     * Get the renewal grace period limit in days.
     * Stored in tgg_volunteer_credits setting 'renewal_grace_days'.
     * Defaults to 30 days.
     * @return int
     */
    public static function getRenewalGraceDays(): int {
        try {
            $db = Database::getAppConnection();
            $stmt = $db->query("SELECT credits FROM tgg_volunteer_credits WHERE credit_key = 'renewal_grace_days' LIMIT 1");
            $val = $stmt->fetchColumn();
            return $val !== false ? (int)$val : 30;
        } catch (Exception $e) {
            return 30;
        }
    }


    /**
     * Add a number of calendar months/years to a date and subtract one day, giving the
     * last day of an N-month/year membership period that starts on $startDate (e.g. a
     * 1-month join on 2026-06-05 ends 2026-07-04; a 1-month renewal starting the day
     * after a 2026-07-04 expiry ends 2026-08-04).
     *
     * If $startDate is itself the last calendar day of its month (e.g. Jan 31, or Feb 29
     * in a leap year), the period instead anchors to the last day of the target month
     * (e.g. Jan 31 -> Feb 28) rather than clamping to a fixed day-of-month. Without this,
     * a single short month (February) would permanently lock the renewal day onto a
     * smaller number forever after (Jan 31 -> Feb 27/28 -> Mar 27/28 -> ... even though
     * March has 31 days). Anchoring to month-end instead self-heals on the very next
     * period, since "day after the last day of a month" is always the 1st of the next
     * month, which never needs clamping again: Jan 31 -> Feb 28 -> Mar 31 -> Apr 30 -> ...
     * @param string $startDate 'Y-m-d'
     * @param int $interval
     * @param string $unit 'month' or 'year'
     * @return string 'Y-m-d'
     */
    private static function addPeriodMinusOneDay(string $startDate, int $interval, string $unit): string {
        $months = (strtolower($unit) === 'year') ? $interval * 12 : $interval;

        $dt = new \DateTime($startDate);
        $day = (int)$dt->format('j');
        $isLastDayOfStartMonth = ($day === (int)$dt->format('t'));

        $dt->setDate((int)$dt->format('Y'), (int)$dt->format('n'), 1);
        $dt->modify("+{$months} month");

        $daysInTargetMonth = (int)$dt->format('t');

        if ($isLastDayOfStartMonth) {
            $dt->setDate((int)$dt->format('Y'), (int)$dt->format('n'), $daysInTargetMonth);
            return $dt->format('Y-m-d');
        }

        $dt->setDate((int)$dt->format('Y'), (int)$dt->format('n'), min($day, $daysInTargetMonth));
        $dt->modify('-1 day');

        return $dt->format('Y-m-d');
    }

    /**
     * Get local subscription plans
     * @param bool $onlyActive If true, only retrieves active plans
     * @return array
     */
    public static function getSubscriptionPlans(bool $onlyActive = false): array {
        try {
            $db = Database::getAppConnection();
            $sql = "SELECT p.*, dr.price AS price, dr.price AS minimum_fee
                    FROM tgg_subscription_plans p
                    LEFT JOIN tgg_subscription_rates dr ON p.default_rate_id = dr.id";
            if ($onlyActive) {
                $sql .= " WHERE p.active = 'active'";
            }
            $sql .= " ORDER BY dr.price ASC";
            $stmt = $db->query($sql);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Get local subscription details for a contact
     * @param int $contactId
     * @return array|null
     */
    public static function getMemberSubscriptionDetails(int $contactId): ?array {
        try {
            $db = Database::getAppConnection();
             $stmt = $db->prepare("
                 SELECT s.status, s.join_date, s.start_date, s.end_date, s.plan_id as membership_id, s.rate_id,
                        p.name as plan_name, p.name as membership_name,
                        COALESCE(r.price, dr.price) as price,
                        COALESCE(r.price, dr.price) as minimum_fee,
                        COALESCE(r.billing_frequency, p.duration_unit) as duration_unit,
                        COALESCE(CASE WHEN r.billing_frequency IS NOT NULL THEN 1 ELSE p.duration_interval END, p.duration_interval) as duration_interval,
                        p.guests_per_month
                 FROM tgg_subscriptions s
                 INNER JOIN tgg_subscription_plans p ON s.plan_id = p.id
                 LEFT JOIN tgg_subscription_rates r ON s.rate_id = r.id
                 LEFT JOIN tgg_subscription_rates dr ON p.default_rate_id = dr.id
                 WHERE s.contact_id = :contact_id
                 LIMIT 1
             ");
             $stmt->execute(['contact_id' => $contactId]);
             $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($row) {
                $today = date('Y-m-d');
                $isActiveStatus = strtolower($row['status']) === 'active';
                // Positive once end_date is in the past; negative/zero while still within the paid period.
                $daysSinceExpiry = (strtotime($today) - strtotime($row['end_date'])) / 86400;

                $graceDays = self::getRenewalGraceDays();
                // Members remain in good standing (is_active) through a grace window past expiry,
                // matching the 'Grace Period' entry in tgg_membership_statuses.
                $row['is_active'] = ($isActiveStatus && $daysSinceExpiry <= $graceDays) ? 1 : 0;

                if (!$row['is_active']) {
                    $row['status_label'] = 'Expired';
                } elseif ($daysSinceExpiry > 0) {
                    $row['status_label'] = 'Grace Period';
                } else {
                    // Members read as "New" for their first grace period days after joining, then "Current".
                    $daysSinceJoin = (strtotime($today) - strtotime($row['join_date'])) / 86400;
                    $row['status_label'] = ($daysSinceJoin < $graceDays) ? 'New' : 'Current';
                }
            }
            
            return $row ?: null;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Process a successful Stripe checkout session idempotently
     * Logs to local transaction ledger, updates local subscription status.
     * @param array $session Stripe Checkout Session array
     * @return bool True if processed successfully
     * @throws Exception
     */
    public static function processCheckoutSession(array $session): bool {
        $sessionId = $session['id'] ?? '';
        if (empty($sessionId)) {
            throw new Exception("Invalid checkout session payload: missing ID.");
        }

        if (($session['payment_status'] ?? '') !== 'paid') {
            throw new Exception("Payment not yet confirmed (status: " . ($session['payment_status'] ?? 'none') . ")");
        }

        $appDb = Database::getAppConnection();

        // 1. Check Idempotency - has this session already been logged?
        $stmt = $appDb->prepare("SELECT id FROM tgg_billing_ledger WHERE stripe_session_id = :session_id LIMIT 1");
        $stmt->execute(['session_id' => $sessionId]);
        if ($stmt->fetch()) {
            return true; // Already processed
        }

        $metadata = $session['metadata'] ?? null;
        if (!$metadata || !isset($metadata['contact_id'], $metadata['plan_id'])) {
            throw new Exception("Missing metadata (contact_id or plan_id) in Stripe Checkout session.");
        }

        $contactId = (int)$metadata['contact_id'];
        $planId = (int)$metadata['plan_id'];
        $action = $metadata['action'] ?? 'join';
        $amountTotal = (float)(($session['amount_total'] ?? 0) / 100);
        $currency = strtolower($session['currency'] ?? 'usd');
        $paymentIntentId = $session['payment_intent'] ?? $session['id'];

        // Get local plan details
        $planStmt = $appDb->prepare("SELECT * FROM tgg_subscription_plans WHERE id = :id LIMIT 1");
        $planStmt->execute(['id' => $planId]);
        $plan = $planStmt->fetch(PDO::FETCH_ASSOC);
        if (!$plan) {
            throw new Exception("Local plan ID {$planId} not found.");
        }

        // Query existing local subscription up front so both the ledger entry and the
        // subscription row below record the same resolved rate.
        $subStmt = $appDb->prepare("SELECT plan_id, join_date, end_date, rate_id, stripe_payment_method_id FROM tgg_subscriptions WHERE contact_id = :contact_id LIMIT 1");
        $subStmt->execute(['contact_id' => $contactId]);
        $existingSub = $subStmt->fetch(PDO::FETCH_ASSOC);

        $today = date('Y-m-d');
        $existingEndDate = $existingSub['end_date'] ?? null;

        // A member's existing rate (which may be a grandfathered/custom one) only carries
        // forward when they're renewing the SAME plan within the grace period of their last
        // expiry. If their plan is changing, or they've let membership lapse past grace and
        // come back later, rate_id is reset below to the plan's current default rate so
        // they're billed its price going forward -- a stale historical rate should never
        // persist through a plan change or a real gap in membership, and it should never be
        // left NULL (floating on whatever the plan's price happens to be later).
        $pastGracePeriod = false;
        if ($existingEndDate) {
            $daysSinceExpiry = (strtotime($today) - strtotime($existingEndDate)) / 86400;
            $pastGracePeriod = $daysSinceExpiry > self::getRenewalGraceDays();
        }
        $planIsChanging = $existingSub && (int)$existingSub['plan_id'] !== $planId;
        $resetRate = $pastGracePeriod || $planIsChanging;

        $targetRateId = ($existingSub && !empty($existingSub['rate_id']) && !$resetRate)
            ? (int)$existingSub['rate_id']
            : ($plan['default_rate_id'] ?? null);

        // 2. Start Transaction
        $appDb->beginTransaction();

        try {
            // A. Log transaction locally in tgg_billing_ledger
            $insertLedger = $appDb->prepare("
                INSERT INTO tgg_billing_ledger (contact_id, plan_id, rate_id, stripe_session_id, payment_intent_id, amount, currency, payment_status, action_type)
                VALUES (:contact_id, :plan_id, :rate_id, :stripe_session_id, :payment_intent_id, :amount, :currency, 'paid', :action_type)
            ");
            $insertLedger->execute([
                'contact_id' => $contactId,
                'plan_id' => $planId,
                // Entrance fees are one-off per-visit charges, not tied to a dues rate.
                'rate_id' => $action === 'entrance_fee' ? null : $targetRateId,
                'stripe_session_id' => $sessionId,
                'payment_intent_id' => $paymentIntentId,
                'amount' => $amountTotal,
                'currency' => $currency,
                'action_type' => $action
            ]);

            // Entrance fee charges are a one-off per-visit charge, not a membership
            // renewal -- the ledger entry above is all that's needed, so skip the
            // subscription extension and dues emails below entirely.
            if ($action === 'entrance_fee') {
                $appDb->commit();
                return true;
            }

            // B. Calculate start and end dates
            $durationInterval = (int)$plan['duration_interval'];
            $durationUnit = strtolower($plan['duration_unit']);

            $startDate = $today;
            if ($existingEndDate && !$pastGracePeriod) {
                // Extend from the day after the old expiry if the membership is still active,
                // or lapsed by the configured grace period days or less. Beyond that, start a
                // brand-new period from today instead of stacking the term on a stale expiry.
                // join_date is never touched by the UPDATE below, so this never resets how long
                // someone has been a member -- it only affects this period's start/end dates.
                $startDate = date('Y-m-d', strtotime($existingEndDate . ' +1 day'));
            }

            if ($existingSub && !empty($existingSub['rate_id']) && !$resetRate) {
                $rateStmt = $appDb->prepare("SELECT * FROM tgg_subscription_rates WHERE id = :rate_id LIMIT 1");
                $rateStmt->execute(['rate_id' => $existingSub['rate_id']]);
                $rate = $rateStmt->fetch(PDO::FETCH_ASSOC);
                if ($rate) {
                    $durationInterval = 1;
                    if ($rate['billing_frequency'] === 'annual') {
                        $durationUnit = 'year';
                    } elseif ($rate['billing_frequency'] === 'monthly') {
                        $durationUnit = 'month';
                    } elseif ($rate['billing_frequency'] === 'daily') {
                        $durationUnit = 'day';
                    }
                }
            }

            if ($durationUnit === 'day') {
                // Daily payment should never change the expiration date
                $endDate = $existingEndDate ? $existingEndDate : $today;
            } else {
                $unitString = $durationUnit;
                if (!in_array($unitString, ['month', 'year'])) {
                    $unitString = 'year';
                }
                $endDate = self::addPeriodMinusOneDay($startDate, $durationInterval, $unitString);
            }

            // C1. Capture/refresh the Stripe customer + payment method for future off-session
            // auto-renewal charges. Best-effort: a Stripe hiccup here must never block an
            // already-paid member's subscription activation.
            $capturedCustomerId = $session['customer'] ?? null;
            $capturedPaymentMethodId = null;
            try {
                if (!empty($paymentIntentId) && strpos($paymentIntentId, 'pi_') === 0) {
                    $pi = StripeHelper::retrievePaymentIntent($paymentIntentId);
                    $capturedPaymentMethodId = $pi['payment_method'] ?? null;
                    $capturedCustomerId = $capturedCustomerId ?: ($pi['customer'] ?? null);
                }
            } catch (Exception $e) {
                error_log("Failed to retrieve PaymentIntent for off-session card capture: " . $e->getMessage());
            }

            // auto_renew only ever gets switched ON the very first time a card is captured for
            // this contact, regardless of join vs renew -- this is what lets an imported member's
            // first in-app payment enable it too, while never re-enabling it on any later payment
            // once a member has explicitly turned it off via their profile.
            $isFirstCardCapture = empty($existingSub['stripe_payment_method_id'] ?? null);
            $setAutoRenewOn = $capturedPaymentMethodId && $isFirstCardCapture && !self::isTrialPlan($plan);

            // C2. Insert or update local subscription. rate_id is always written explicitly
            // (never left NULL) -- $targetRateId is either the carried-forward existing rate,
            // or the plan's current default rate for a new join / plan change / post-grace renewal.
            if ($existingSub) {
                $updateSub = $appDb->prepare("
                    UPDATE tgg_subscriptions
                    SET plan_id = :plan_id, status = 'active', start_date = :start_date, end_date = :end_date,
                        rate_id = :rate_id,
                        auto_renew_attempts = 0,
                        stripe_customer_id = COALESCE(:stripe_customer_id, stripe_customer_id),
                        stripe_payment_method_id = COALESCE(:stripe_payment_method_id, stripe_payment_method_id)"
                        . ($setAutoRenewOn ? ", auto_renew = 1" : "") . "
                    WHERE contact_id = :contact_id
                ");
                $updateSub->execute([
                    'plan_id' => $planId,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'rate_id' => $targetRateId,
                    'stripe_customer_id' => $capturedCustomerId,
                    'stripe_payment_method_id' => $capturedPaymentMethodId,
                    'contact_id' => $contactId
                ]);
            } else {
                $joinDate = $today;
                $insertSub = $appDb->prepare("
                    INSERT INTO tgg_subscriptions (contact_id, plan_id, rate_id, status, join_date, start_date, end_date, stripe_customer_id, stripe_payment_method_id, auto_renew)
                    VALUES (:contact_id, :plan_id, :rate_id, 'active', :join_date, :start_date, :end_date, :stripe_customer_id, :stripe_payment_method_id, :auto_renew)
                ");
                $insertSub->execute([
                    'contact_id' => $contactId,
                    'plan_id' => $planId,
                    'rate_id' => $targetRateId,
                    'join_date' => $joinDate,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'stripe_customer_id' => $capturedCustomerId,
                    'stripe_payment_method_id' => $capturedPaymentMethodId,
                    'auto_renew' => $setAutoRenewOn ? 1 : 0
                ]);
            }

            $appDb->commit();

            // Send confirmation email
            try {
                $contactQuery = $appDb->prepare("
                    SELECT display_name, email 
                    FROM tgg_contacts 
                    WHERE id = :contact_id LIMIT 1
                ");
                $contactQuery->execute(['contact_id' => $contactId]);
                $contact = $contactQuery->fetch(PDO::FETCH_ASSOC);

                if ($contact && !empty($contact['email'])) {
                    $loginUrl = rtrim($_ENV['BASE_URL'] ?? 'http://localhost/member', '/') . '/index.php';
                    $placeholders = [
                        'display_name' => $contact['display_name'] ?? 'Member',
                        'tier_name' => $plan['name'] ?? 'Membership Tier',
                        'amount' => number_format($amountTotal, 2),
                        'start_date' => $startDate,
                        'end_date' => $endDate,
                        'login_url' => $loginUrl
                    ];
                    MailHelper::sendTemplate($contact['email'], 'payment_received', $placeholders, $contactId, null);

                    // New (non-Trial) members don't set a password at signup, so once their
                    // first payment clears, send a welcome email with a link they can use to
                    // set one up if they ever want portal access -- it's optional, not required.
                    if ($action === 'join') {
                        $rawToken = Auth::createPasswordSetupToken($contact['email'], '+7 days');
                        $setPasswordLink = rtrim($_ENV['BASE_URL'] ?? 'http://localhost/member', '/') . '/reset-password.php?token=' . $rawToken;
                        MailHelper::sendTemplate($contact['email'], 'signup', [
                            'display_name' => $contact['display_name'] ?? 'Member',
                            'tier_name' => $plan['name'] ?? 'Membership Tier',
                            'start_date' => $startDate,
                            'end_date' => $endDate,
                            'set_password_link' => $setPasswordLink
                        ], $contactId, null);
                    }
                }
            } catch (Exception $mailEx) {
                // Log mail exception but do not interrupt the checkout flow
                error_log("Failed to send activation email: " . $mailEx->getMessage());
            }

            return true;

        } catch (Exception $e) {
            if ($appDb->inTransaction()) {
                $appDb->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Check whether a plan is the one-time, non-renewable Trial membership. Matches
     * any plan name containing "trial" (e.g. "Trial" or "30 Day Trial"), not just an
     * exact "Trial" name, since the live plan name includes the trial length.
     * @param array $plan Plan row (must include 'name')
     * @return bool
     */
    public static function isTrialPlan(array $plan): bool {
        return stripos(trim($plan['name'] ?? ''), 'trial') !== false;
    }

    /**
     * Get the active Trial plan, if one is configured.
     * @return array|null
     */
    public static function getTrialPlan(): ?array {
        foreach (self::getSubscriptionPlans(true) as $plan) {
            if (self::isTrialPlan($plan)) {
                return $plan;
            }
        }
        return null;
    }

    /**
     * Check whether a plan is the Associate membership tier, which charges a per-visit
     * entrance fee on every check-in except the one immediately after a dues payment.
     * Matches any plan name containing "associate".
     * @param array $plan Plan row (must include 'name')
     * @return bool
     */
    public static function isAssociatePlan(array $plan): bool {
        // Accepts either a tgg_subscription_plans row ('name') or a
        // MembershipService::getMemberMembershipDetails() row ('membership_name').
        $name = $plan['name'] ?? $plan['membership_name'] ?? '';
        return stripos(trim($name), 'associate') !== false;
    }

    /**
     * Check whether an email address has already used (or has a pending) Trial membership.
     * Checked across all contacts with this email, including soft-deleted ones, since the
     * Trial is limited to one per person ever, not just once per active account.
     * @param string $email
     * @return bool
     */
    public static function hasUsedOrPendingTrial(string $email): bool {
        $appDb = Database::getAppConnection();
        $email = strtolower(trim($email));

        $stmt = $appDb->prepare("
            SELECT 1 FROM tgg_billing_ledger bl
            INNER JOIN tgg_subscription_plans p ON p.id = bl.plan_id
            INNER JOIN tgg_contacts c ON c.id = bl.contact_id
            WHERE LOWER(p.name) LIKE '%trial%' AND LOWER(c.email) = :email
            LIMIT 1
        ");
        $stmt->execute(['email' => $email]);
        if ($stmt->fetch()) {
            return true;
        }

        $stmt = $appDb->prepare("
            SELECT 1 FROM tgg_trial_verifications v
            INNER JOIN tgg_contacts c ON c.id = v.contact_id
            WHERE LOWER(c.email) = :email AND v.expires_at >= NOW()
            LIMIT 1
        ");
        $stmt->execute(['email' => $email]);
        return (bool)$stmt->fetch();
    }

    /**
     * Activate a verified Trial membership: creates the local subscription and logs
     * a $0.00 ledger entry (the ledger entry is what makes the trial show up as "used"
     * for the lifetime one-trial-per-email check).
     * @param int $contactId
     * @param int $planId
     * @return array Activation details (start_date, end_date, plan)
     * @throws Exception
     */
    public static function activateTrial(int $contactId, int $planId): array {
        $appDb = Database::getAppConnection();

        $planStmt = $appDb->prepare("SELECT * FROM tgg_subscription_plans WHERE id = :id LIMIT 1");
        $planStmt->execute(['id' => $planId]);
        $plan = $planStmt->fetch(PDO::FETCH_ASSOC);
        if (!$plan || !self::isTrialPlan($plan)) {
            throw new Exception("Trial plan not found.");
        }

        $intervalDays = (int)$plan['duration_interval'];
        $today = date('Y-m-d');
        $endDate = date('Y-m-d', strtotime($today . " +{$intervalDays} days"));

        $appDb->beginTransaction();

        try {
            $rateId = $plan['default_rate_id'] ?? null;

            $uniqueId = uniqid('trial_', true);
            $insertLedger = $appDb->prepare("
                INSERT INTO tgg_billing_ledger (contact_id, plan_id, rate_id, stripe_session_id, payment_intent_id, amount, currency, payment_status, action_type)
                VALUES (:contact_id, :plan_id, :rate_id, :stripe_session_id, :payment_intent_id, 0.00, 'usd', 'paid', 'join')
            ");
            $insertLedger->execute([
                'contact_id' => $contactId,
                'plan_id' => $planId,
                'rate_id' => $rateId,
                'stripe_session_id' => $uniqueId,
                'payment_intent_id' => $uniqueId
            ]);

            $insertSub = $appDb->prepare("
                INSERT INTO tgg_subscriptions (contact_id, plan_id, rate_id, status, join_date, start_date, end_date)
                VALUES (:contact_id, :plan_id, :rate_id, 'active', :join_date, :start_date, :end_date)
                ON DUPLICATE KEY UPDATE plan_id = VALUES(plan_id), rate_id = VALUES(rate_id), status = 'active', join_date = VALUES(join_date), start_date = VALUES(start_date), end_date = VALUES(end_date)
            ");
            $insertSub->execute([
                'contact_id' => $contactId,
                'plan_id' => $planId,
                'rate_id' => $rateId,
                'join_date' => $today,
                'start_date' => $today,
                'end_date' => $endDate
            ]);

            $appDb->commit();

            return ['start_date' => $today, 'end_date' => $endDate, 'plan' => $plan];
        } catch (Exception $e) {
            if ($appDb->inTransaction()) {
                $appDb->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Send the "your Trial is now active" welcome email, with a link to optionally set up
     * portal access. Shared by the email-verification flow (verify-trial.php) and the
     * in-person activation paths (addMember(), activatePendingTrialInPerson()) -- all three
     * activate a Trial the same way and should welcome the member the same way.
     * @param int $contactId
     * @param string $displayName
     * @param string $email
     * @param array $activation Return value of activateTrial() (needs start_date, end_date)
     * @param int|null $senderId Contact ID of the staff member performing the action, if any, for email logging
     */
    public static function sendTrialActivatedEmail(int $contactId, string $displayName, string $email, array $activation, ?int $senderId = null): void {
        if (empty($email)) {
            return;
        }
        try {
            $rawToken = Auth::createPasswordSetupToken($email, '+7 days');
            $loginUrl = rtrim($_ENV['BASE_URL'] ?? 'http://localhost/member', '/') . '/index.php';
            $setPasswordLink = rtrim($_ENV['BASE_URL'] ?? 'http://localhost/member', '/') . '/reset-password.php?token=' . $rawToken;
            MailHelper::sendTemplate($email, 'trial_activated', [
                'display_name' => $displayName,
                'start_date' => $activation['start_date'] ?? date('Y-m-d'),
                'end_date' => $activation['end_date'] ?? 'N/A',
                'login_url' => $loginUrl,
                'set_password_link' => $setPasswordLink
            ], $contactId, $senderId);
        } catch (Exception $mailEx) {
            error_log("Failed to send trial activation email: " . $mailEx->getMessage());
        }
    }

    /**
     * Look up a contact's pending online Trial registration (submitted via join.php but not
     * yet email-verified).
     * @param int $contactId
     * @return int|null The plan_id they registered for, or null if there's no pending Trial
     */
    public static function getPendingTrialPlanId(int $contactId): ?int {
        $appDb = Database::getAppConnection();
        $stmt = $appDb->prepare("SELECT plan_id FROM tgg_trial_verifications WHERE contact_id = :contact_id LIMIT 1");
        $stmt->execute(['contact_id' => $contactId]);
        $planId = $stmt->fetchColumn();
        return $planId !== false ? (int)$planId : null;
    }

    /**
     * Activate a contact's pending online Trial registration without requiring them to click
     * the emailed verification link -- used when a host is checking the person in physically,
     * which is at least as strong a verification signal as an email click. Mirrors the
     * in-person Trial activation addMember() already does for brand-new walk-ins, but for a
     * contact who already submitted the self-service Join form and is just waiting on the
     * email step. Ignores the verification token's 24-hour expiry, since that clock exists to
     * bound the *unattended* email-link path, not this host-attended one.
     * @param int $contactId
     * @param int|null $senderId Contact ID of the host performing the activation, for email logging
     * @return array Activation details (start_date, end_date, plan, display_name)
     * @throws Exception if there's no pending Trial registration for this contact
     */
    public static function activatePendingTrialInPerson(int $contactId, ?int $senderId = null): array {
        $planId = self::getPendingTrialPlanId($contactId);
        if (!$planId) {
            throw new Exception("No pending Trial registration found for this member.");
        }

        $appDb = Database::getAppConnection();
        $contactStmt = $appDb->prepare("SELECT display_name, email FROM tgg_contacts WHERE id = :id LIMIT 1");
        $contactStmt->execute(['id' => $contactId]);
        $contact = $contactStmt->fetch(PDO::FETCH_ASSOC);
        $displayName = $contact['display_name'] ?? 'Member';
        $email = $contact['email'] ?? '';

        $activation = self::activateTrial($contactId, $planId);

        $deleteToken = $appDb->prepare("DELETE FROM tgg_trial_verifications WHERE contact_id = :contact_id");
        $deleteToken->execute(['contact_id' => $contactId]);

        self::sendTrialActivatedEmail($contactId, $displayName, $email, $activation, $senderId);

        $activation['display_name'] = $displayName;
        return $activation;
    }

    /**
     * Create a brand-new local contact and member account (mirrors the self-service
     * join.php flow), optionally activating a membership immediately. Used by both the
     * Admin Dashboard's "Add Member" and the host-facing Hosting View's "Add Member",
     * which share the same business rules but differ in who's allowed to activate a
     * membership at creation time.
     * @param string $firstName
     * @param string $lastName
     * @param string $email
     * @param string $phone
     * @param int $planId 0 if no membership should be activated
     * @param string $paymentMethod Required for non-Trial plans: cash|check|complimentary|volunteer credit
     * @param bool $allowMembershipActivation False for a host without admin rights -- the contact is still created, just without a plan
     * @param int|null $senderId Contact ID of the admin/host performing the action, for email logging
     * @return array{contact_id:int, display_name:string}
     * @throws Exception
     */
    public static function addMember(
        string $firstName,
        string $lastName,
        string $email,
        string $phone,
        int $planId,
        string $paymentMethod,
        bool $allowMembershipActivation,
        ?int $senderId
    ): array {
        $firstName = trim($firstName);
        $lastName = trim($lastName);
        $email = trim(strtolower($email));
        $phone = normalize_phone(trim($phone));

        if (empty($firstName) || empty($lastName)) {
            throw new Exception("First and last name are required.");
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Please enter a valid email address.");
        }

        $appDb = Database::getAppConnection();

        $existsStmt = $appDb->prepare("SELECT id FROM tgg_contacts WHERE email = :email AND is_deleted = 0 LIMIT 1");
        $existsStmt->execute(['email' => $email]);
        if ($existsStmt->fetch()) {
            throw new Exception("A member with this email already exists. Use their existing profile to manage their membership instead.");
        }

        // Resolve and validate the selected plan up front, before creating the contact,
        // the same order join.php uses for its own Trial-eligibility check.
        $selectedPlan = null;
        $isTrialActivation = false;
        if ($allowMembershipActivation && $planId > 0) {
            $planStmt = $appDb->prepare("SELECT * FROM tgg_subscription_plans WHERE id = :id LIMIT 1");
            $planStmt->execute(['id' => $planId]);
            $selectedPlan = $planStmt->fetch(PDO::FETCH_ASSOC);
            if (!$selectedPlan) {
                throw new Exception("Invalid membership level selected.");
            }

            $isTrialActivation = self::isTrialPlan($selectedPlan);
            if ($isTrialActivation) {
                if (self::hasUsedOrPendingTrial($email)) {
                    throw new Exception("This email address has already used its one-time Trial membership and is not eligible for another.");
                }
            } elseif (empty($paymentMethod)) {
                throw new Exception("Please select a payment method to activate this membership level.");
            }
        }

        $displayName = "{$firstName} {$lastName}";
        $appDb->beginTransaction();

        try {
            // A. Create Local Contact
            $insertContact = $appDb->prepare("INSERT INTO tgg_contacts (contact_type, display_name, first_name, last_name, email, phone, is_deleted)
                                               VALUES ('Individual', :display_name, :first_name, :last_name, :email, :phone, 0)");
            $insertContact->execute([
                'display_name' => $displayName,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'phone' => !empty($phone) ? $phone : null
            ]);
            $newContactId = (int)$appDb->lastInsertId();

            // B. Create Local Member Settings with a random, discarded password hash --
            // they get a "set up your password" link by email if a membership is activated.
            $randomPasswordHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
            $defaultPublicFields = json_encode(['display_name', 'membership_name', 'status_label']);
            $insertSettings = $appDb->prepare("INSERT INTO tgg_member_settings (contact_id, password_hash, role, is_profile_public, public_fields)
                                               VALUES (:contact_id, :password_hash, 'member', 1, :public_fields)");
            $insertSettings->execute([
                'contact_id' => $newContactId,
                'password_hash' => $randomPasswordHash,
                'public_fields' => $defaultPublicFields
            ]);

            $appDb->commit();
        } catch (Exception $txException) {
            if ($appDb->inTransaction()) {
                $appDb->rollBack();
            }
            throw $txException;
        }

        // Activate the membership picked above, if any -- Trial uses the same one-time,
        // no-payment activation as the email-verified self-service flow (skipping the
        // verification link, since the admin/host is verifying the person in person);
        // any other plan goes through the existing offline-payment recording.
        if ($selectedPlan) {
            if ($isTrialActivation) {
                self::activateTrial($newContactId, $planId);
            } else {
                self::processOfflineRenewal($newContactId, $planId, $paymentMethod, 'join');
            }

            // Send a welcome email with a link to set up portal access, mirroring the welcome
            // email sent after a self-service Stripe join (or, for Trial, after email verification).
            try {
                $membership = self::getMemberSubscriptionDetails($newContactId);
                $rawToken = Auth::createPasswordSetupToken($email, '+7 days');
                $setPasswordLink = rtrim($_ENV['BASE_URL'] ?? 'http://localhost/member', '/') . '/reset-password.php?token=' . $rawToken;
                if ($isTrialActivation) {
                    $loginUrl = rtrim($_ENV['BASE_URL'] ?? 'http://localhost/member', '/') . '/index.php';
                    MailHelper::sendTemplate($email, 'trial_activated', [
                        'display_name' => $displayName,
                        'start_date' => $membership['start_date'] ?? date('Y-m-d'),
                        'end_date' => $membership['end_date'] ?? 'N/A',
                        'login_url' => $loginUrl,
                        'set_password_link' => $setPasswordLink
                    ], $newContactId, $senderId);
                } else {
                    MailHelper::sendTemplate($email, 'signup', [
                        'display_name' => $displayName,
                        'tier_name' => $membership['membership_name'] ?? 'Member',
                        'start_date' => $membership['start_date'] ?? date('Y-m-d'),
                        'end_date' => $membership['end_date'] ?? 'N/A',
                        'set_password_link' => $setPasswordLink
                    ], $newContactId, $senderId);
                }
            } catch (Exception $mailEx) {
                error_log("Failed to send new member welcome email: " . $mailEx->getMessage());
            }
        }

        return ['contact_id' => $newContactId, 'display_name' => $displayName];
    }

    /**
     * Add or update a membership plan locally
     * @param array $data Plan attributes
     * @return bool
     * @throws Exception
     */
    /**
     * @return array{new_rate_created: bool, effective_date: ?string} 'new_rate_created' is
     * true only when editing an existing plan's price actually created a new default rate
     * (never true for a brand-new plan, or an edit that didn't change price); 'effective_date'
     * ('Y-m-d') is set alongside it so the caller can tell the admin exactly when the new
     * price takes effect.
     */
    public static function savePlan(array $data): array {
        $appDb = Database::getAppConnection();

        $id = isset($data['id']) ? (int)$data['id'] : null;
        $name = trim($data['name'] ?? '');
        $description = trim($data['description'] ?? '');
        $price = (float)($data['price'] ?? 0);
        $durationUnit = strtolower($data['duration_unit'] ?? 'year');
        $durationInterval = (int)($data['duration_interval'] ?? 1);
        $active = trim($data['active'] ?? 'active');
        if (!in_array($active, ['active', 'inactive'])) {
            $active = 'active';
        }
        $guestsPerMonth = max(0, (int)($data['guests_per_month'] ?? 0));

        if (empty($name)) {
            throw new Exception("Plan name cannot be empty.");
        }
        if ($price < 0) {
            throw new Exception("Price cannot be negative.");
        }
        if ($durationInterval <= 0) {
            throw new Exception("Duration interval must be greater than zero.");
        }
        if (!in_array($durationUnit, ['day', 'month', 'year'])) {
            throw new Exception("Invalid duration unit. Allowed units are 'day', 'month', or 'year'.");
        }

        $freq = 'monthly';
        if ($durationUnit === 'year') {
            $freq = 'annual';
        } elseif ($durationUnit === 'day') {
            $freq = 'daily';
        }

        $newRateCreated = false;
        $effectiveDate = null;

        $appDb->beginTransaction();

        try {
            if ($id) {
                // Update existing plan's own fields. Price lives entirely on Rate now, so it's
                // never written here directly -- see the rate handling below.
                $updateLocal = $appDb->prepare("
                    UPDATE tgg_subscription_plans
                    SET name = :name, description = :description, duration_unit = :duration_unit, duration_interval = :duration_interval, active = :active, guests_per_month = :guests_per_month
                    WHERE id = :id
                ");
                $updateLocal->execute([
                    'name' => $name,
                    'description' => $description,
                    'duration_unit' => $durationUnit,
                    'duration_interval' => $durationInterval,
                    'active' => $active,
                    'guests_per_month' => $guestsPerMonth,
                    'id' => $id
                ]);

                // If the submitted price differs from the plan's current default rate, that's a
                // price change: create a new rate and repoint the plan's default at it, leaving
                // the old default rate untouched (still active, still named the same -- rates are
                // told apart by their effective date/created_at in the UI, not by name) so members
                // already grandfathered onto it keep their price until they renew past grace,
                // change plans, or an admin explicitly retires it via BillingHelper::retireRate().
                // This is the mechanism that actually grandfathers existing members through a
                // price change.
                $currentRateStmt = $appDb->prepare("
                    SELECT r.id, r.name, r.price
                    FROM tgg_subscription_plans p
                    LEFT JOIN tgg_subscription_rates r ON p.default_rate_id = r.id
                    WHERE p.id = :id
                ");
                $currentRateStmt->execute(['id' => $id]);
                $currentRate = $currentRateStmt->fetch(PDO::FETCH_ASSOC);

                $priceChanged = !$currentRate || round((float)$currentRate['price'], 2) !== round($price, 2);
                if ($priceChanged) {
                    $newRateCreated = true;
                    $effectiveDate = date('Y-m-d');

                    $insertRate = $appDb->prepare("
                        INSERT INTO tgg_subscription_rates (plan_id, name, price, billing_frequency, inactive, created_at)
                        VALUES (:plan_id, :name, :price, :billing_frequency, 0, NOW())
                    ");
                    $insertRate->execute([
                        'plan_id' => $id,
                        'name' => $name . ' - Standard',
                        'price' => $price,
                        'billing_frequency' => $freq
                    ]);
                    $newRateId = $appDb->lastInsertId();

                    $appDb->prepare("UPDATE tgg_subscription_plans SET default_rate_id = :rate_id WHERE id = :id")
                        ->execute(['rate_id' => $newRateId, 'id' => $id]);
                }

            } else {
                // Insert new plan
                // Generate next civi membership type id locally to maintain schema compatibility
                $maxCiviId = (int)$appDb->query("SELECT MAX(civicrm_membership_type_id) FROM tgg_subscription_plans")->fetchColumn();
                $civiTypeId = $maxCiviId + 1;

                 $insertLocal = $appDb->prepare("
                     INSERT INTO tgg_subscription_plans (name, description, duration_unit, duration_interval, civicrm_membership_type_id, active, guests_per_month)
                     VALUES (:name, :description, :duration_unit, :duration_interval, :civicrm_membership_type_id, :active, :guests_per_month)
                 ");
                 $insertLocal->execute([
                     'name' => $name,
                     'description' => $description,
                     'duration_unit' => $durationUnit,
                     'duration_interval' => $durationInterval,
                     'civicrm_membership_type_id' => $civiTypeId,
                     'active' => $active,
                     'guests_per_month' => $guestsPerMonth
                 ]);

                 $planId = $appDb->lastInsertId();
                 $insertRate = $appDb->prepare("
                     INSERT INTO tgg_subscription_rates (plan_id, name, price, billing_frequency, inactive, created_at)
                     VALUES (:plan_id, :name, :price, :billing_frequency, 0, NOW())
                 ");
                 $insertRate->execute([
                     'plan_id' => $planId,
                     'name' => $name . ' - Standard',
                     'price' => $price,
                     'billing_frequency' => $freq
                 ]);
                 $newRateId = $appDb->lastInsertId();

                 $appDb->prepare("UPDATE tgg_subscription_plans SET default_rate_id = :rate_id WHERE id = :id")
                     ->execute(['rate_id' => $newRateId, 'id' => $planId]);
             }

            $appDb->commit();
            return ['new_rate_created' => $newRateCreated, 'effective_date' => $effectiveDate];

        } catch (Exception $e) {
            if ($appDb->inTransaction()) {
                $appDb->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Explicitly retire a rate: moves every subscription currently on it to the plan's
     * current default rate, then marks it inactive/retired. This is the only way members
     * get moved off a grandfathered/historical rate -- it never happens automatically (e.g.
     * a rate's own expiration_date is informational only and is not enforced here).
     *
     * Active members (status='active' and not past their grace period -- i.e. not the
     * "Expired" status_label, even if their DB status column is still literally 'active'
     * because nothing has flipped it) are emailed that their rate is changing, effective
     * their next billing period (their current end_date + 1 day); their current, already-paid
     * period is unaffected. Members who are actually expired are moved silently, not emailed.
     * @param int $rateId
     * @param int $planId
     * @return array{moved: int, emailed: int} Members migrated, and how many of those got notified
     * @throws Exception
     */
    public static function retireRate(int $rateId, int $planId): array {
        $appDb = Database::getAppConnection();

        $planStmt = $appDb->prepare("SELECT name, default_rate_id FROM tgg_subscription_plans WHERE id = :id LIMIT 1");
        $planStmt->execute(['id' => $planId]);
        $plan = $planStmt->fetch(PDO::FETCH_ASSOC);
        if (!$plan) {
            throw new Exception("Plan not found.");
        }
        if (empty($plan['default_rate_id'])) {
            throw new Exception("This plan has no current default rate to move members onto.");
        }
        if ((int)$plan['default_rate_id'] === $rateId) {
            throw new Exception("Cannot retire a plan's current default rate. Change the plan's price first to establish a new default, then retire this one.");
        }

        $oldRateStmt = $appDb->prepare("SELECT id, price, billing_frequency FROM tgg_subscription_rates WHERE id = :id AND plan_id = :plan_id LIMIT 1");
        $oldRateStmt->execute(['id' => $rateId, 'plan_id' => $planId]);
        $oldRate = $oldRateStmt->fetch(PDO::FETCH_ASSOC);
        if (!$oldRate) {
            throw new Exception("Rate not found for this plan.");
        }

        $newRateStmt = $appDb->prepare("SELECT price, billing_frequency FROM tgg_subscription_rates WHERE id = :id LIMIT 1");
        $newRateStmt->execute(['id' => $plan['default_rate_id']]);
        $newRate = $newRateStmt->fetch(PDO::FETCH_ASSOC);

        // Gather active members to notify before moving anyone -- once rate_id is repointed
        // below, this rate no longer identifies who was just affected.
        $graceDays = self::getRenewalGraceDays();
        $activeMembersStmt = $appDb->prepare("
            SELECT s.contact_id, s.end_date, c.display_name, c.email
            FROM tgg_subscriptions s
            INNER JOIN tgg_contacts c ON c.id = s.contact_id
            WHERE s.rate_id = :rate_id
              AND s.status = 'active'
              AND s.end_date >= CURRENT_DATE() - INTERVAL :grace_days DAY
        ");
        $activeMembersStmt->execute(['rate_id' => $rateId, 'grace_days' => $graceDays]);
        $activeMembers = $activeMembersStmt->fetchAll(PDO::FETCH_ASSOC);

        $appDb->beginTransaction();
        try {
            $moveMembers = $appDb->prepare("UPDATE tgg_subscriptions SET rate_id = :default_rate_id WHERE rate_id = :rate_id");
            $moveMembers->execute(['default_rate_id' => $plan['default_rate_id'], 'rate_id' => $rateId]);
            $movedCount = $moveMembers->rowCount();

            $appDb->prepare("UPDATE tgg_subscription_rates SET inactive = 1, retired_at = NOW() WHERE id = :id")
                ->execute(['id' => $rateId]);

            $appDb->commit();
        } catch (Exception $e) {
            if ($appDb->inTransaction()) {
                $appDb->rollBack();
            }
            throw $e;
        }

        $emailedCount = 0;
        foreach ($activeMembers as $member) {
            if (empty($member['email'])) {
                continue;
            }
            try {
                MailHelper::sendTemplate($member['email'], 'rate_retired', [
                    'display_name' => $member['display_name'] ?? 'Member',
                    'tier_name' => $plan['name'],
                    'old_price' => number_format((float)$oldRate['price'], 2),
                    'new_price' => number_format((float)($newRate['price'] ?? 0), 2),
                    'billing_frequency' => $newRate['billing_frequency'] ?? $oldRate['billing_frequency'],
                    'effective_date' => date('Y-m-d', strtotime($member['end_date'] . ' +1 day')),
                    'end_date' => $member['end_date'],
                ], (int)$member['contact_id'], null);
                $emailedCount++;
            } catch (Exception $e) {
                error_log("Failed to send rate_retired email to contact #{$member['contact_id']}: " . $e->getMessage());
            }
        }

        return ['moved' => $movedCount, 'emailed' => $emailedCount];
    }

    /**
     * Process an offline/manual membership renewal or extension
     * @param int $contactId
     * @param int $planId
     * @param string $paymentMethod 'cash', 'check', 'complimentary', 'volunteer credit'
     * @param string $action 'join' or 'renew'
     * @param string $levelChangeMode 'extend_current' or 'change_level'
     * @return bool True if successful
     * @throws Exception
     */
    public static function processOfflineRenewal(
        int $contactId,
        int $planId,
        string $paymentMethod,
        string $action = 'renew',
        string $levelChangeMode = 'extend_current',
        string $durationMode = 'standard',
        string $customDate = null,
        float $customAmount = null
    ): bool {
        $appDb = Database::getAppConnection();

        // Validate payment method
        $validMethods = ['cash', 'check', 'complimentary', 'volunteer credit'];
        if (!in_array(strtolower($paymentMethod), $validMethods)) {
            throw new Exception("Invalid offline payment method: " . htmlspecialchars($paymentMethod));
        }

        // Get local plan details
        $planStmt = $appDb->prepare("SELECT * FROM tgg_subscription_plans WHERE id = :id LIMIT 1");
        $planStmt->execute(['id' => $planId]);
        $plan = $planStmt->fetch(PDO::FETCH_ASSOC);
        if (!$plan) {
            throw new Exception("Local plan ID {$planId} not found.");
        }

        // Generate custom identifiers
        $paymentMethodLabel = ucwords($paymentMethod);
        $uniqueId = uniqid('offline_', true);
        $paymentIntentId = 'offline_' . str_replace(' ', '_', strtolower($paymentMethod)) . '_' . time();

        // Query existing local subscription up front (a plain read, no need to wait for the
        // transaction) so grace-period and plan-change status can be determined before deciding
        // whether the member's existing rate (if any) still applies.
        $subStmt = $appDb->prepare("SELECT plan_id, join_date, end_date, rate_id FROM tgg_subscriptions WHERE contact_id = :contact_id LIMIT 1");
        $subStmt->execute(['contact_id' => $contactId]);
        $existingSub = $subStmt->fetch(PDO::FETCH_ASSOC);

        // Resolve final plan ID based on levelChangeMode
        $finalPlanId = $planId;
        $isCustomDuration = in_array($durationMode, ['1_month', '1_year', 'custom_date']);
        $resolvedChangeMode = $isCustomDuration ? 'extend_current' : $levelChangeMode;
        if ($resolvedChangeMode === 'extend_current') {
            if ($existingSub) {
                $finalPlanId = (int)$existingSub['plan_id'];
            }
        }

        $today = date('Y-m-d');
        $startDate = $today;

        // A member's existing rate (which may be a grandfathered/custom one) only carries forward
        // when they're renewing the SAME plan within the grace period of their last expiry. If
        // their plan is changing, or they've let membership lapse past grace and come back later,
        // rate_id is reset below to the plan's current default rate so they're billed its price
        // going forward -- a stale historical rate should never persist through a plan change or
        // a real gap in membership, and it should never be left NULL.
        $pastGracePeriod = false;
        if ($existingSub) {
            $existingEndDate = $existingSub['end_date'];
            // Extend from the day after the old expiry if still active, or lapsed by the configured grace period
            // days or less. Beyond that, start a fresh period from today instead. join_date
            // is never touched here (no UPDATE below includes it), so this never resets how long
            // someone has been a member.
            $daysSinceExpiry = (strtotime($today) - strtotime($existingEndDate)) / 86400;
            if ($daysSinceExpiry <= self::getRenewalGraceDays()) {
                $startDate = date('Y-m-d', strtotime($existingEndDate . ' +1 day'));
            } else {
                $pastGracePeriod = true;
            }
        }
        $planIsChanging = $existingSub && (int)$existingSub['plan_id'] !== $finalPlanId;
        $resetRate = $pastGracePeriod || $planIsChanging;

        $targetRateId = ($existingSub && !empty($existingSub['rate_id']) && !$resetRate)
            ? (int)$existingSub['rate_id']
            : ($plan['default_rate_id'] ?? null);

        $planPrice = 0.0;
        if ($targetRateId) {
            $rateStmt = $appDb->prepare("SELECT price FROM tgg_subscription_rates WHERE id = :id LIMIT 1");
            $rateStmt->execute(['id' => $targetRateId]);
            $planPrice = (float)($rateStmt->fetchColumn() ?: 0);
        }

        $amountTotal = ($customAmount !== null) ? $customAmount : (($durationMode === 'standard') ? $planPrice : 0.00);
        $currency = 'usd';

        $appDb->beginTransaction();

        try {
            // A. Log transaction locally in tgg_billing_ledger
            $insertLedger = $appDb->prepare("
                INSERT INTO tgg_billing_ledger (contact_id, plan_id, rate_id, stripe_session_id, payment_intent_id, amount, currency, payment_status, action_type)
                VALUES (:contact_id, :plan_id, :rate_id, :stripe_session_id, :payment_intent_id, :amount, :currency, 'paid', :action_type)
            ");
            $insertLedger->execute([
                'contact_id' => $contactId,
                'plan_id' => $planId,
                'rate_id' => $targetRateId,
                'stripe_session_id' => $uniqueId,
                'payment_intent_id' => $paymentIntentId,
                'amount' => $amountTotal,
                'currency' => $currency,
                'action_type' => $action
            ]);

            if ($durationMode === '1_month') {
                $endDate = self::addPeriodMinusOneDay($startDate, 1, 'month');
            } elseif ($durationMode === '1_year') {
                $endDate = self::addPeriodMinusOneDay($startDate, 1, 'year');
            } elseif ($durationMode === 'custom_date') {
                $endDate = date('Y-m-d', strtotime($customDate));
            } else {
                $durationInterval = (int)$plan['duration_interval'];
                $durationUnit = strtolower($plan['duration_unit']);

                if ($existingSub && !empty($existingSub['rate_id']) && !$resetRate) {
                    $rateStmt = $appDb->prepare("SELECT * FROM tgg_subscription_rates WHERE id = :rate_id LIMIT 1");
                    $rateStmt->execute(['rate_id' => $existingSub['rate_id']]);
                    $rate = $rateStmt->fetch(PDO::FETCH_ASSOC);
                    if ($rate) {
                        $durationInterval = 1;
                        if ($rate['billing_frequency'] === 'annual') {
                            $durationUnit = 'year';
                        } elseif ($rate['billing_frequency'] === 'monthly') {
                            $durationUnit = 'month';
                        } elseif ($rate['billing_frequency'] === 'daily') {
                            $durationUnit = 'day';
                        }
                    }
                }
                if ($durationUnit === 'day') {
                    // Daily payment should never change the expiration date
                    $existingEndDate = null;
                    if ($existingSub) {
                        $existingEndDate = $existingSub['end_date'];
                    }
                    $endDate = $existingEndDate ? $existingEndDate : $today;
                } else {
                    $unitString = $durationUnit;
                    if (!in_array($unitString, ['month', 'year'])) {
                        $unitString = 'year';
                    }
                    $endDate = self::addPeriodMinusOneDay($startDate, $durationInterval, $unitString);
                }
            }

            // C. Insert or update local subscription. rate_id is always written explicitly
            // (never left NULL) -- $targetRateId is either the carried-forward existing rate,
            // or the plan's current default rate for a new join / plan change / post-grace renewal.
            if ($existingSub) {
                $updateSub = $appDb->prepare("
                    UPDATE tgg_subscriptions
                    SET plan_id = :plan_id, status = 'active', start_date = :start_date, end_date = :end_date, rate_id = :rate_id, auto_renew_attempts = 0
                    WHERE contact_id = :contact_id
                ");
                $updateSub->execute([
                    'plan_id' => $finalPlanId,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'rate_id' => $targetRateId,
                    'contact_id' => $contactId
                ]);
            } else {
                $joinDate = $today;
                $insertSub = $appDb->prepare("
                    INSERT INTO tgg_subscriptions (contact_id, plan_id, rate_id, status, join_date, start_date, end_date)
                    VALUES (:contact_id, :plan_id, :rate_id, 'active', :join_date, :start_date, :end_date)
                ");
                $insertSub->execute([
                    'contact_id' => $contactId,
                    'plan_id' => $finalPlanId,
                    'rate_id' => $targetRateId,
                    'join_date' => $joinDate,
                    'start_date' => $startDate,
                    'end_date' => $endDate
                ]);
            }

            // Commit transaction
            $appDb->commit();

            // Send confirmation email for offline renewal
            try {
                $contactQuery = $appDb->prepare("
                    SELECT display_name, email 
                    FROM tgg_contacts 
                    WHERE id = :contact_id LIMIT 1
                ");
                $contactQuery->execute(['contact_id' => $contactId]);
                $contact = $contactQuery->fetch(PDO::FETCH_ASSOC);

                if ($contact && !empty($contact['email'])) {
                    $loginUrl = rtrim($_ENV['BASE_URL'] ?? 'http://localhost/member', '/') . '/index.php';
                    $placeholders = [
                        'display_name' => $contact['display_name'] ?? 'Member',
                        'tier_name' => $plan['name'] ?? 'Membership Tier',
                        'amount' => number_format($amountTotal, 2),
                        'start_date' => $startDate,
                        'end_date' => $endDate,
                        'login_url' => $loginUrl
                    ];
                    
                    $senderId = $_SESSION['user']['contact_id'] ?? null;
                    MailHelper::sendTemplate($contact['email'], 'payment_received', $placeholders, $contactId, $senderId);
                }
            } catch (Exception $mailEx) {
                // Log mail exception but do not interrupt the renewal success
                error_log("Failed to send offline renewal receipt email: " . $mailEx->getMessage());
            }

            return true;

        } catch (Exception $e) {
            if ($appDb->inTransaction()) {
                $appDb->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Charge a due auto-renewal off-session via the member's saved Stripe card. Allows up to
     * 3 consecutive attempts per renewal cycle (this call plus 2 retries, one per daily cron
     * run): the first failure sends a warning email, the 3rd failure marks the subscription
     * expired immediately (rather than waiting out the full renewal grace period) and sends a
     * distinct "membership expired" email. A successful charge resets the attempt counter,
     * extends the subscription, and sends the existing payment_received receipt email.
     * @param int $contactId
     * @return array ['result' => 'charged'|'declined'|'expired'|'skipped', 'message' => string, 'end_date' => ?string]
     */
    public static function processAutoRenewalCharge(int $contactId): array {
        try {
            $appDb = Database::getAppConnection();

            $subStmt = $appDb->prepare("
                SELECT s.*, p.name AS plan_name, p.duration_interval, p.duration_unit, p.default_rate_id
                FROM tgg_subscriptions s
                INNER JOIN tgg_subscription_plans p ON p.id = s.plan_id
                WHERE s.contact_id = :contact_id
                  AND s.auto_renew = 1 AND s.status = 'active' AND s.end_date <= CURRENT_DATE()
                LIMIT 1
            ");
            $subStmt->execute(['contact_id' => $contactId]);
            $sub = $subStmt->fetch(PDO::FETCH_ASSOC);

            if (!$sub) {
                return ['result' => 'skipped', 'message' => 'Not due, auto-renew disabled, or already renewed today', 'end_date' => null];
            }

            $plan = ['name' => $sub['plan_name'], 'duration_interval' => $sub['duration_interval'], 'duration_unit' => $sub['duration_unit']];
            if (self::isTrialPlan($plan)) {
                return ['result' => 'skipped', 'message' => 'Trial plans are never auto-renewed', 'end_date' => null];
            }
            if (empty($sub['stripe_customer_id']) || empty($sub['stripe_payment_method_id'])) {
                return ['result' => 'skipped', 'message' => 'No card on file', 'end_date' => null];
            }

            // Resolve billing period: a custom/grandfathered rate overrides the plan's own
            // duration, exactly like processCheckoutSession(). Every active subscription is
            // pinned to a rate going forward (never NULL) -- falling back to the plan's current
            // default rate here is a defensive guard, not the normal path.
            $effectiveRateId = $sub['rate_id'] ?: $sub['default_rate_id'];
            $durationInterval = (int)$plan['duration_interval'];
            $durationUnit = strtolower($plan['duration_unit']);
            $amount = 0.0;
            if (!empty($effectiveRateId)) {
                $rateStmt = $appDb->prepare("SELECT * FROM tgg_subscription_rates WHERE id = :rate_id LIMIT 1");
                $rateStmt->execute(['rate_id' => $effectiveRateId]);
                $rate = $rateStmt->fetch(PDO::FETCH_ASSOC);
                if ($rate) {
                    $durationInterval = 1;
                    $amount = (float)$rate['price'];
                    if ($rate['billing_frequency'] === 'annual') {
                        $durationUnit = 'year';
                    } elseif ($rate['billing_frequency'] === 'monthly') {
                        $durationUnit = 'month';
                    } elseif ($rate['billing_frequency'] === 'daily') {
                        $durationUnit = 'day';
                    }
                }
            }

            $contactId = (int)$sub['contact_id'];
            $existingEndDate = $sub['end_date'];
            $attemptNumber = (int)$sub['auto_renew_attempts'] + 1;

            // Each attempt gets its own permanent ledger row -- both successes and failures --
            // so every charge attempt this cycle is logged, not just the latest outcome.
            $syntheticId = "autorenew_{$contactId}_{$existingEndDate}_{$attemptNumber}";
            $dupeCheck = $appDb->prepare("SELECT id FROM tgg_billing_ledger WHERE stripe_session_id = :id LIMIT 1");
            $dupeCheck->execute(['id' => $syntheticId]);
            if ($dupeCheck->fetch()) {
                return ['result' => 'skipped', 'message' => 'This attempt was already logged today', 'end_date' => null];
            }

            $charge = StripeHelper::chargeOffSession(
                $sub['stripe_customer_id'],
                $sub['stripe_payment_method_id'],
                $amount,
                'usd',
                "Auto-renewal: {$plan['name']}",
                ['contact_id' => $contactId, 'plan_id' => (int)$sub['plan_id'], 'cycle_end_date' => $existingEndDate]
            );

            $contactQuery = $appDb->prepare("SELECT display_name, email FROM tgg_contacts WHERE id = :contact_id LIMIT 1");
            $contactQuery->execute(['contact_id' => $contactId]);
            $contact = $contactQuery->fetch(PDO::FETCH_ASSOC);

            if ($charge['success']) {
                $startDate = date('Y-m-d', strtotime($existingEndDate . ' +1 day'));
                if ($durationUnit === 'day') {
                    $endDate = $existingEndDate;
                } else {
                    $unitString = in_array($durationUnit, ['month', 'year'], true) ? $durationUnit : 'year';
                    $endDate = self::addPeriodMinusOneDay($startDate, $durationInterval, $unitString);
                }

                $appDb->beginTransaction();
                try {
                    $insertLedger = $appDb->prepare("
                        INSERT INTO tgg_billing_ledger (contact_id, plan_id, rate_id, stripe_session_id, payment_intent_id, amount, currency, payment_status, action_type)
                        VALUES (:contact_id, :plan_id, :rate_id, :stripe_session_id, :payment_intent_id, :amount, 'usd', 'paid', 'auto_renew')
                    ");
                    $insertLedger->execute([
                        'contact_id' => $contactId,
                        'plan_id' => (int)$sub['plan_id'],
                        'rate_id' => $effectiveRateId,
                        'stripe_session_id' => $syntheticId,
                        'payment_intent_id' => $charge['payment_intent_id'],
                        'amount' => $amount
                    ]);

                    $updateSub = $appDb->prepare("
                        UPDATE tgg_subscriptions
                        SET status = 'active', start_date = :start_date, end_date = :end_date, auto_renew_attempts = 0
                        WHERE contact_id = :contact_id
                    ");
                    $updateSub->execute(['start_date' => $startDate, 'end_date' => $endDate, 'contact_id' => $contactId]);

                    $appDb->commit();
                } catch (Exception $e) {
                    if ($appDb->inTransaction()) {
                        $appDb->rollBack();
                    }
                    throw $e;
                }

                if ($contact && !empty($contact['email'])) {
                    try {
                        $loginUrl = rtrim($_ENV['BASE_URL'] ?? 'http://localhost/member', '/') . '/index.php';
                        MailHelper::sendTemplate($contact['email'], 'payment_received', [
                            'display_name' => $contact['display_name'] ?? 'Member',
                            'tier_name' => $plan['name'] ?? 'Membership Tier',
                            'amount' => number_format($amount, 2),
                            'start_date' => $startDate,
                            'end_date' => $endDate,
                            'login_url' => $loginUrl
                        ], $contactId, null);
                    } catch (Exception $mailEx) {
                        error_log("Failed to send auto-renewal receipt email: " . $mailEx->getMessage());
                    }
                }

                return ['result' => 'charged', 'message' => "Renewed through {$endDate}", 'end_date' => $endDate];
            }

            // Declined (or requires_action, treated as a decline for v1 -- see StripeHelper::chargeOffSession()).
            $insertLedger = $appDb->prepare("
                INSERT INTO tgg_billing_ledger (contact_id, plan_id, rate_id, stripe_session_id, payment_intent_id, amount, currency, payment_status, action_type)
                VALUES (:contact_id, :plan_id, :rate_id, :stripe_session_id, :payment_intent_id, :amount, 'usd', 'failed', 'auto_renew')
            ");
            $insertLedger->execute([
                'contact_id' => $contactId,
                'plan_id' => (int)$sub['plan_id'],
                'rate_id' => $effectiveRateId,
                'stripe_session_id' => $syntheticId,
                'payment_intent_id' => $charge['payment_intent_id'] ?? $syntheticId,
                'amount' => $amount
            ]);

            $renewUrl = rtrim($_ENV['BASE_URL'] ?? 'http://localhost/member', '/') . '/renew.php?contact_id=' . $contactId;

            if ($attemptNumber >= 3) {
                $appDb->prepare("UPDATE tgg_subscriptions SET status = 'expired', auto_renew_attempts = :attempts WHERE contact_id = :contact_id")
                    ->execute(['attempts' => $attemptNumber, 'contact_id' => $contactId]);

                if ($contact && !empty($contact['email'])) {
                    try {
                        MailHelper::sendTemplate($contact['email'], 'auto_renew_expired', [
                            'display_name' => $contact['display_name'] ?? 'Member',
                            'tier_name' => $plan['name'] ?? 'Membership Tier',
                            'end_date' => $existingEndDate,
                            'renew_url' => $renewUrl
                        ], $contactId, null);
                    } catch (Exception $mailEx) {
                        error_log("Failed to send auto-renewal expired email: " . $mailEx->getMessage());
                    }
                }

                return ['result' => 'expired', 'message' => $charge['message'] ?? 'Card declined on final attempt', 'end_date' => null];
            }

            $appDb->prepare("UPDATE tgg_subscriptions SET auto_renew_attempts = :attempts WHERE contact_id = :contact_id")
                ->execute(['attempts' => $attemptNumber, 'contact_id' => $contactId]);

            if ($attemptNumber === 1 && $contact && !empty($contact['email'])) {
                try {
                    MailHelper::sendTemplate($contact['email'], 'auto_renew_failed', [
                        'display_name' => $contact['display_name'] ?? 'Member',
                        'tier_name' => $plan['name'] ?? 'Membership Tier',
                        'end_date' => $existingEndDate,
                        'renew_url' => $renewUrl
                    ], $contactId, null);
                } catch (Exception $mailEx) {
                    error_log("Failed to send auto-renewal declined email: " . $mailEx->getMessage());
                }
            }

            return ['result' => 'declined', 'message' => $charge['message'] ?? 'Card declined', 'end_date' => null];

        } catch (Exception $e) {
            // A genuine API/config/network error (not a card decline) -- don't count it
            // against the member's 3-attempt limit or log it as a card decline, since it
            // isn't their card's fault. The cron will simply try again on its next run.
            error_log("Auto-renewal charge failed for contact #{$contactId}: " . $e->getMessage());
            return ['result' => 'error', 'message' => $e->getMessage(), 'end_date' => null];
        }
    }

    /**
     * Send the "your membership will auto-renew soon" reminder exactly 5 days before the
     * scheduled charge, once per renewal cycle (deduped via auto_renew_reminder_sent_for).
     * @return array ['sent' => int]
     */
    public static function sendAutoRenewalReminders(): array {
        $appDb = Database::getAppConnection();
        $sent = 0;
        $errors = [];

        $stmt = $appDb->prepare("
            SELECT s.contact_id, s.end_date, c.display_name, c.email, p.name AS plan_name,
                   COALESCE(r.price, dr.price) AS price
            FROM tgg_subscriptions s
            INNER JOIN tgg_contacts c ON c.id = s.contact_id
            INNER JOIN tgg_subscription_plans p ON p.id = s.plan_id
            LEFT JOIN tgg_subscription_rates r ON r.id = s.rate_id
            LEFT JOIN tgg_subscription_rates dr ON dr.id = p.default_rate_id
            WHERE s.auto_renew = 1 AND s.status = 'active'
              AND s.end_date >= CURRENT_DATE() + INTERVAL 1 DAY
              AND s.end_date <= CURRENT_DATE() + INTERVAL 5 DAY
              AND (s.auto_renew_reminder_sent_for IS NULL OR s.auto_renew_reminder_sent_for <> s.end_date)
        ");
        $stmt->execute();
        $due = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($due as $row) {
            $contactId = (int)$row['contact_id'];
            try {
                if (!empty($row['email'])) {
                    $manageUrl = rtrim($_ENV['BASE_URL'] ?? 'http://localhost/member', '/') . '/profile.php?id=' . $contactId;
                    MailHelper::sendTemplate($row['email'], 'auto_renew_upcoming', [
                        'display_name' => $row['display_name'] ?? 'Member',
                        'tier_name' => $row['plan_name'] ?? 'Membership Tier',
                        'amount' => number_format((float)$row['price'], 2),
                        'renew_date' => $row['end_date'],
                        'manage_url' => $manageUrl
                    ], $contactId, null);
                }

                $appDb->prepare("UPDATE tgg_subscriptions SET auto_renew_reminder_sent_for = :end_date WHERE contact_id = :contact_id")
                    ->execute(['end_date' => $row['end_date'], 'contact_id' => $contactId]);
                $sent++;
            } catch (Exception $e) {
                $msg = "Failed to send auto-renewal reminder for contact #{$contactId}: " . $e->getMessage();
                error_log($msg);
                $errors[] = "contact_id={$contactId}: ERROR - " . $e->getMessage();
            }
        }

        return ['sent' => $sent, 'errors' => $errors];
    }

    /**
     * Send a "your membership expires soon" reminder 5 days before expiry to members
     * who do not have auto-renew enabled, once per renewal cycle (deduped via
     * renewal_reminder_sent_for). Mirrors sendAutoRenewalReminders() for manual-pay members.
     * @return array ['sent' => int]
     */
    public static function sendManualRenewalReminders(): array {
        $appDb = Database::getAppConnection();
        $sent = 0;
        $errors = [];

        $stmt = $appDb->prepare("
            SELECT s.contact_id, s.end_date, c.display_name, c.email, p.name AS plan_name
            FROM tgg_subscriptions s
            INNER JOIN tgg_contacts c ON c.id = s.contact_id
            INNER JOIN tgg_subscription_plans p ON p.id = s.plan_id
            WHERE s.auto_renew = 0 AND s.status = 'active'
              AND s.end_date >= CURRENT_DATE() + INTERVAL 1 DAY
              AND s.end_date <= CURRENT_DATE() + INTERVAL 5 DAY
              AND LOWER(p.name) NOT LIKE '%trial%'
              AND (s.renewal_reminder_sent_for IS NULL OR s.renewal_reminder_sent_for <> s.end_date)
        ");
        $stmt->execute();
        $due = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($due as $row) {
            $contactId = (int)$row['contact_id'];
            try {
                if (!empty($row['email'])) {
                    $renewUrl = rtrim($_ENV['BASE_URL'] ?? 'http://localhost/member', '/') . '/join.php?email=' . urlencode($row['email']);
                    MailHelper::sendTemplate($row['email'], 'renewal_reminder', [
                        'display_name' => $row['display_name'] ?? 'Member',
                        'tier_name' => $row['plan_name'] ?? 'Membership Tier',
                        'end_date' => $row['end_date'],
                        'renew_url' => $renewUrl
                    ], $contactId, null);
                }

                $appDb->prepare("UPDATE tgg_subscriptions SET renewal_reminder_sent_for = :end_date WHERE contact_id = :contact_id")
                    ->execute(['end_date' => $row['end_date'], 'contact_id' => $contactId]);
                $sent++;
            } catch (Exception $e) {
                $msg = "Failed to send manual renewal reminder for contact #{$contactId}: " . $e->getMessage();
                error_log($msg);
                $errors[] = "contact_id={$contactId}: ERROR - " . $e->getMessage();
            }
        }

        return ['sent' => $sent, 'errors' => $errors];
    }

    /**
     * Send a "your membership has expired" notice once after the grace period ends for
     * members who never renewed, deduped via expired_notice_sent_for. Only fires for
     * subscriptions still in 'active' status — members whose auto-renew failed 3 times
     * already have status='expired' and received auto_renew_expired instead.
     * @return array ['sent' => int]
     */
    public static function sendExpiredNotices(): array {
        $appDb = Database::getAppConnection();
        $graceDays = self::getRenewalGraceDays();
        $sent = 0;
        $errors = [];

        $stmt = $appDb->prepare("
            SELECT s.contact_id, s.end_date, c.display_name, c.email, p.name AS plan_name
            FROM tgg_subscriptions s
            INNER JOIN tgg_contacts c ON c.id = s.contact_id
            INNER JOIN tgg_subscription_plans p ON p.id = s.plan_id
            WHERE s.status = 'active'
              AND s.end_date < CURDATE() - INTERVAL :grace_days DAY
              AND LOWER(p.name) NOT LIKE '%trial%'
              AND (s.expired_notice_sent_for IS NULL OR s.expired_notice_sent_for <> s.end_date)
        ");
        $stmt->execute(['grace_days' => $graceDays]);
        $due = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($due as $row) {
            $contactId = (int)$row['contact_id'];
            try {
                if (!empty($row['email'])) {
                    $renewUrl = rtrim($_ENV['BASE_URL'] ?? 'http://localhost/member', '/') . '/join.php?email=' . urlencode($row['email']);
                    MailHelper::sendTemplate($row['email'], 'membership_expired', [
                        'display_name' => $row['display_name'] ?? 'Member',
                        'tier_name' => $row['plan_name'] ?? 'Membership Tier',
                        'end_date' => $row['end_date'],
                        'renew_url' => $renewUrl
                    ], $contactId, null);
                }

                $appDb->prepare("UPDATE tgg_subscriptions SET expired_notice_sent_for = :end_date WHERE contact_id = :contact_id")
                    ->execute(['end_date' => $row['end_date'], 'contact_id' => $contactId]);
                $sent++;
            } catch (Exception $e) {
                $msg = "Failed to send expired membership notice for contact #{$contactId}: " . $e->getMessage();
                error_log($msg);
                $errors[] = "contact_id={$contactId}: ERROR - " . $e->getMessage();
            }
        }

        return ['sent' => $sent, 'errors' => $errors];
    }

    /**
     * Check whether an Associate member owes a per-visit entrance fee on this check-in.
     * Associates get exactly one free check-in after each dues payment; every check-in
     * after that, until their next payment, requires the fee. Computed by counting
     * check-ins since their most recent paid join/renew ledger entry for this plan --
     * entrance fee payments themselves don't reset the count, since paying one doesn't
     * grant a new free visit.
     * @param int $contactId
     * @param array $membership Row from MembershipService::getMemberMembershipDetails()
     * @return bool
     */
    public static function entranceFeeOwed(int $contactId, array $membership): bool {
        if (!self::isAssociatePlan($membership)) {
            return false;
        }

        $planId = (int)($membership['membership_id'] ?? 0);
        if ($planId <= 0) {
            return false;
        }

        $appDb = Database::getAppConnection();

        $stmt = $appDb->prepare("
            SELECT created_at FROM tgg_billing_ledger
            WHERE contact_id = :contact_id AND plan_id = :plan_id
              AND payment_status = 'paid' AND action_type IN ('join', 'renew', 'auto_renew')
            ORDER BY created_at DESC LIMIT 1
        ");
        $stmt->execute(['contact_id' => $contactId, 'plan_id' => $planId]);
        $lastPaymentAt = $stmt->fetchColumn();

        if (!$lastPaymentAt) {
            // No recorded dues payment to anchor a free visit to -- charge to be safe.
            return true;
        }

        $checkinStmt = $appDb->prepare("
            SELECT COUNT(*) FROM tgg_checkins
            WHERE contact_id = :contact_id AND checked_in_at >= :since
        ");
        $checkinStmt->execute(['contact_id' => $contactId, 'since' => $lastPaymentAt]);
        return (int)$checkinStmt->fetchColumn() > 0;
    }

    /**
     * Calculate how many guest passes a member has left for the current calendar month.
     * Passes are granted per the member's plan's guests_per_month and do not roll over --
     * usage is counted by querying guest check-ins since the first of the current month.
     * @param int $contactId
     * @param array $membership Row from MembershipService::getMemberMembershipDetails() or getMemberSubscriptionDetails()
     * @return array{allowance: int, used: int, remaining: int}
     */
    public static function getGuestPassesRemaining(int $contactId, array $membership): array {
        $allowance = max(0, (int)($membership['guests_per_month'] ?? 0));

        $appDb = Database::getAppConnection();
        $stmt = $appDb->prepare("
            SELECT COUNT(*) FROM tgg_checkins
            WHERE contact_id = :contact_id
              AND guest_name IS NOT NULL
              AND checked_in_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
        ");
        $stmt->execute(['contact_id' => $contactId]);
        $used = (int)$stmt->fetchColumn();

        return [
            'allowance' => $allowance,
            'used' => $used,
            'remaining' => max(0, $allowance - $used)
        ];
    }

    /**
     * Record a cash payment request that needs in-person host approval before the
     * member's check-in is completed (cash is never trusted automatically).
     * @param int $contactId
     * @param string $type 'entrance_fee' or 'membership_renewal'
     * @param int|null $planId Required for 'membership_renewal'; null for 'entrance_fee'
     * @param float $amount
     * @return int The new tgg_pending_payments row id
     * @throws Exception
     */
    public static function createPendingPayment(int $contactId, string $type, ?int $planId, float $amount): int {
        if (!in_array($type, ['entrance_fee', 'membership_renewal'], true)) {
            throw new Exception("Invalid pending payment type: " . htmlspecialchars($type));
        }

        $appDb = Database::getAppConnection();
        $stmt = $appDb->prepare("
            INSERT INTO tgg_pending_payments (contact_id, type, plan_id, amount, payment_method, status, requested_at)
            VALUES (:contact_id, :type, :plan_id, :amount, 'cash', 'pending', NOW())
        ");
        $stmt->execute([
            'contact_id' => $contactId,
            'type' => $type,
            'plan_id' => $planId,
            'amount' => $amount
        ]);

        return (int)$appDb->lastInsertId();
    }

    /**
     * Approve a pending cash payment request: records the payment, then completes the
     * member's check-in (this is the only place a cash-flagged visit actually checks in).
     * @param int $pendingId
     * @param int $resolverContactId The host's contact_id
     * @return array Details for the host's confirmation UI (display_name, type, amount)
     * @throws Exception
     */
    public static function approvePendingPayment(int $pendingId, int $resolverContactId): array {
        $appDb = Database::getAppConnection();

        $stmt = $appDb->prepare("SELECT * FROM tgg_pending_payments WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $pendingId]);
        $pending = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$pending) {
            throw new Exception("Pending payment request not found.");
        }
        if ($pending['status'] !== 'pending') {
            throw new Exception("This payment request has already been {$pending['status']}.");
        }

        $contactId = (int)$pending['contact_id'];
        $amount = (float)$pending['amount'];

        if ($pending['type'] === 'entrance_fee') {
            $uniqueId = uniqid('offline_cash_entrance_', true);
            $insertLedger = $appDb->prepare("
                INSERT INTO tgg_billing_ledger (contact_id, plan_id, stripe_session_id, payment_intent_id, amount, currency, payment_status, action_type)
                VALUES (:contact_id, :plan_id, :stripe_session_id, :payment_intent_id, :amount, 'usd', 'paid', 'entrance_fee')
            ");
            $insertLedger->execute([
                'contact_id' => $contactId,
                'plan_id' => $pending['plan_id'],
                'stripe_session_id' => $uniqueId,
                'payment_intent_id' => $uniqueId,
                'amount' => $amount
            ]);
        } elseif ($pending['type'] === 'membership_renewal') {
            self::processOfflineRenewal($contactId, (int)$pending['plan_id'], 'cash', 'renew', 'extend_current', 'standard', null, $amount);
        } else {
            throw new Exception("Unknown pending payment type: " . $pending['type']);
        }

        // Complete the check-in now that payment is confirmed (same duplicate-checkin
        // guard the kiosks use -- a host could be approving this well after the member
        // walked away, so don't assume it's still "today" in the same sense).
        $dupCheck = $appDb->prepare("SELECT COUNT(*) FROM tgg_checkins WHERE contact_id = :contact_id AND DATE(checked_in_at) = CURDATE()");
        $dupCheck->execute(['contact_id' => $contactId]);
        if ((int)$dupCheck->fetchColumn() === 0) {
            $insertCheckin = $appDb->prepare("INSERT INTO tgg_checkins (contact_id, checked_in_at, notes) VALUES (:contact_id, NOW(), :notes)");
            $insertCheckin->execute([
                'contact_id' => $contactId,
                'notes' => $pending['type'] === 'entrance_fee' ? 'Cash entrance fee approved by host' : 'Cash renewal approved by host'
            ]);
        }

        $resolve = $appDb->prepare("UPDATE tgg_pending_payments SET status = 'approved', resolved_at = NOW(), resolved_by = :resolved_by WHERE id = :id");
        $resolve->execute(['resolved_by' => $resolverContactId, 'id' => $pendingId]);

        $contactStmt = $appDb->prepare("SELECT display_name FROM tgg_contacts WHERE id = :id LIMIT 1");
        $contactStmt->execute(['id' => $contactId]);

        return [
            'contact_id' => $contactId,
            'display_name' => $contactStmt->fetchColumn() ?: "Member #{$contactId}",
            'type' => $pending['type'],
            'amount' => $amount
        ];
    }

    /**
     * Deny a pending cash payment request (e.g. the member left without paying).
     * Does not touch billing ledger, subscriptions, or check-ins.
     * @param int $pendingId
     * @param int $resolverContactId The host's contact_id
     * @throws Exception
     */
    public static function denyPendingPayment(int $pendingId, int $resolverContactId): void {
        $appDb = Database::getAppConnection();

        $stmt = $appDb->prepare("SELECT status FROM tgg_pending_payments WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $pendingId]);
        $status = $stmt->fetchColumn();
        if ($status === false) {
            throw new Exception("Pending payment request not found.");
        }
        if ($status !== 'pending') {
            throw new Exception("This payment request has already been {$status}.");
        }

        $update = $appDb->prepare("UPDATE tgg_pending_payments SET status = 'denied', resolved_at = NOW(), resolved_by = :resolved_by WHERE id = :id");
        $update->execute(['resolved_by' => $resolverContactId, 'id' => $pendingId]);
    }
}
