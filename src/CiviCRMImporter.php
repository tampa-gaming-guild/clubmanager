<?php
namespace App;

use Exception;

/**
 * CiviCRM Data Importer
 * One-time synchronization of contacts and memberships from CiviCRM tables into the
 * local app database. Once the club has gone live and the local tables (tgg_*) are the
 * source of truth, this class -- along with admin/import.php and the CIVI_DB_* env vars
 * / Database::getCiviConnection() -- is not needed for normal operation and can be
 * safely deleted. For day-to-day membership lookups, see MembershipService instead.
 */
class CiviCRMImporter {

    /**
     * Known historical dues rates, confirmed by the club directly (not inferred from payment
     * data): "Regular Monthly" moved from $20/mo to $30/mo; "Regular Annual" moved from $200/yr
     * to $300/yr (at the same time Monthly increased) and later back down to $200/yr to
     * encourage annual memberships. No other plan's price has ever changed. Any contribution
     * amount that doesn't match a plan's current price or one of these known historical values
     * is a one-time payment (e.g. a donation or partial payment), not a recurring dues rate --
     * see isKnownDuesRate().
     */
    private const KNOWN_HISTORICAL_RATES = [
        'Regular Monthly' => [20.00, 30.00],
        'Regular Annual' => [200.00, 300.00],
    ];

    /**
     * Whether $amount is a legitimate dues rate for $planName: either the plan's current price,
     * or one of its known historical rates above. Anything else (e.g. a one-off $8 contribution)
     * is a one-time payment, not evidence of a different ongoing dues rate.
     */
    private static function isKnownDuesRate(string $planName, float $amount, float $currentPrice): bool {
        if (round($amount, 2) === round($currentPrice, 2)) {
            return true;
        }
        foreach (self::KNOWN_HISTORICAL_RATES[$planName] ?? [] as $knownRate) {
            if (round($amount, 2) === round((float)$knownRate, 2)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Test connection to both databases
     * @return array Array with status information
     */
    public static function testConnections(): array {
        $status = ['app' => false, 'civi' => false, 'errors' => []];
        try {
            $appDb = Database::getAppConnection();
            $status['app'] = true;
        } catch (Exception $e) {
            $status['errors'][] = "App DB Error: " . $e->getMessage();
        }

        try {
            $civiDb = Database::getCiviConnection();
            $status['civi'] = true;
        } catch (Exception $e) {
            $status['errors'][] = "CiviCRM DB Error: " . $e->getMessage();
        }

        return $status;
    }

    /**
     * Run full import/sync process from CiviCRM
     * @return array Import statistics
     * @throws Exception
     */
    public static function runSync(): array {
        $appDb = Database::getAppConnection();
        $civiDb = Database::getCiviConnection();

        $stats = [
            'plans_synced' => 0,
            'contacts_scanned' => 0,
            'settings_created' => 0,
            'settings_updated' => 0,
            'founders_flagged' => 0,
            'contributions_synced' => 0,
            'grandfathered_rates_created' => 0,
            'errors' => []
        ];

        // 0. Sync CiviCRM membership types to local subscription plans first,
        // since contacts' memberships below are matched against these plans.
        try {
            $membershipTypes = $civiDb->query("SELECT id, name, description, minimum_fee, duration_unit, duration_interval FROM civicrm_membership_type")->fetchAll();

            // Price now lives entirely on Rate, not Plan -- see the default-rate handling below.
            $upsertPlanStmt = $appDb->prepare("
                INSERT INTO tgg_subscription_plans (name, description, duration_unit, duration_interval, civicrm_membership_type_id, active)
                VALUES (:name, :description, :duration_unit, :duration_interval, :civicrm_membership_type_id, 'active')
                ON DUPLICATE KEY UPDATE
                    name = VALUES(name),
                    description = VALUES(description),
                    duration_unit = VALUES(duration_unit),
                    duration_interval = VALUES(duration_interval)
            ");

            foreach ($membershipTypes as $type) {
                $upsertPlanStmt->execute([
                    'name' => $type['name'],
                    'description' => $type['description'],
                    'duration_unit' => $type['duration_unit'],
                    'duration_interval' => $type['duration_interval'],
                    'civicrm_membership_type_id' => (int)$type['id']
                ]);
                $stats['plans_synced']++;

                // Ensure this plan's auto-managed default rate exists and reflects CiviCRM's
                // current fee/frequency, and that the plan's default_rate_id points at it. Keyed
                // on (plan_id, name) rather than "any rate for this plan" so that re-running the
                // sync (e.g. after a membership type's fee changes, during pre-launch testing)
                // refreshes this specific default rate in place instead of leaving it stale --
                // while never touching any other, differently-named rate an admin has since added
                // for the same plan.
                $planId = $appDb->query("SELECT id FROM tgg_subscription_plans WHERE civicrm_membership_type_id = " . (int)$type['id'])->fetchColumn();
                if ($planId) {
                    $freq = 'monthly';
                    if ($type['duration_unit'] === 'year') {
                        $freq = 'annual';
                    } elseif ($type['duration_unit'] === 'day') {
                        $freq = 'daily';
                    }
                    $defaultRateName = $type['name'] . ' - Standard';

                    $rateCheck = $appDb->prepare("SELECT id FROM tgg_subscription_rates WHERE plan_id = :plan_id AND name = :name LIMIT 1");
                    $rateCheck->execute(['plan_id' => $planId, 'name' => $defaultRateName]);
                    $existingRate = $rateCheck->fetch();

                    if ($existingRate) {
                        $defaultRateId = $existingRate['id'];
                        $updateRate = $appDb->prepare("
                            UPDATE tgg_subscription_rates
                            SET price = :price, billing_frequency = :billing_frequency
                            WHERE id = :id
                        ");
                        $updateRate->execute([
                            'price' => $type['minimum_fee'],
                            'billing_frequency' => $freq,
                            'id' => $defaultRateId
                        ]);
                    } else {
                        $insertRate = $appDb->prepare("
                            INSERT INTO tgg_subscription_rates (plan_id, name, price, billing_frequency, inactive, created_at)
                            VALUES (:plan_id, :name, :price, :billing_frequency, 0, NOW())
                        ");
                        $insertRate->execute([
                            'plan_id' => $planId,
                            'name' => $defaultRateName,
                            'price' => $type['minimum_fee'],
                            'billing_frequency' => $freq
                        ]);
                        $defaultRateId = $appDb->lastInsertId();
                    }

                    $appDb->prepare("UPDATE tgg_subscription_plans SET default_rate_id = :rate_id WHERE id = :id")
                        ->execute(['rate_id' => $defaultRateId, 'id' => $planId]);
                }
            }
        } catch (Exception $e) {
            $stats['errors'][] = "Failed syncing membership types: " . $e->getMessage();
        }

        // 1. Fetch all contacts from CiviCRM (including deleted status and names)
        $query = "SELECT c.id, c.display_name, c.first_name, c.last_name, e.email, p.phone, c.is_deleted, c.is_opt_out
                  FROM civicrm_contact c
                  INNER JOIN civicrm_email e ON e.contact_id = c.id AND e.is_primary = 1
                  LEFT JOIN civicrm_phone p ON p.contact_id = c.id AND p.is_primary = 1";
        
        $stmt = $civiDb->prepare($query);
        $stmt->execute();
        $contacts = $stmt->fetchAll();

        $stats['contacts_scanned'] = count($contacts);

        // Prepare statements for local contacts insertion/update
        $insertContactStmt = $appDb->prepare("
            INSERT INTO tgg_contacts (id, display_name, first_name, last_name, email, phone, is_deleted, is_opt_out)
            VALUES (:id, :display_name, :first_name, :last_name, :email, :phone, :is_deleted, :is_opt_out)
            ON DUPLICATE KEY UPDATE
                display_name = VALUES(display_name),
                first_name = VALUES(first_name),
                last_name = VALUES(last_name),
                email = VALUES(email),
                phone = VALUES(phone),
                is_deleted = VALUES(is_deleted),
                is_opt_out = VALUES(is_opt_out)
        ");

        // Prepare statements for local checking and insertion
        $checkStmt = $appDb->prepare("SELECT contact_id, role FROM tgg_member_settings WHERE contact_id = :contact_id LIMIT 1");
        $insertStmt = $appDb->prepare("INSERT INTO tgg_member_settings (contact_id, password_hash, role, is_profile_public, public_fields) 
                                       VALUES (:contact_id, :password_hash, :role, 1, :public_fields)");

        // Users must use the forgot password flow to reset their password on first login
        $defaultPublicFields = json_encode(['display_name', 'membership_type', 'membership_status']);

        foreach ($contacts as $contact) {
            $contactId = (int)$contact['id'];
            
            try {
                // A. Sync Contact table details locally
                $insertContactStmt->execute([
                    'id' => $contactId,
                    'display_name' => $contact['display_name'],
                    'first_name' => $contact['first_name'],
                    'last_name' => $contact['last_name'],
                    'email' => strtolower(trim($contact['email'])),
                    'phone' => normalize_phone((string)($contact['phone'] ?? '')) ?: null,
                    'is_deleted' => (int)$contact['is_deleted'],
                    'is_opt_out' => (int)$contact['is_opt_out']
                ]);

                // B. Sync Credentials Settings record locally
                $checkStmt->execute(['contact_id' => $contactId]);
                $localUser = $checkStmt->fetch();

                if (!$localUser) {
                    // Create local credentials record
                    $role = 'member';

                    // Generate secure random token password
                    $randomPassword = bin2hex(random_bytes(32));
                    $securePasswordHash = password_hash($randomPassword, PASSWORD_DEFAULT);

                    $insertStmt->execute([
                        'contact_id' => $contactId,
                        'password_hash' => $securePasswordHash,
                        'role' => $role,
                        'public_fields' => $defaultPublicFields
                    ]);
                    $stats['settings_created']++;
                } else {
                    $stats['settings_updated']++;
                }

                // C. Sync CiviCRM membership details to local tgg_subscriptions
                // Note: civicrm_membership_status.is_active means "this status option is enabled
                // in the CiviCRM admin UI" -- it's 1 for New/Current/Grace/Expired/Pending/Cancelled/
                // Deceased alike, so it does NOT indicate whether the membership is currently valid.
                // is_current_member is the correct column for that (0 for Expired/Pending/Cancelled/Deceased).
                // is_test excludes CiviCRM's own test-mode records (e.g. from testing Stripe's test
                // mode against production CiviCRM) -- CiviCRM's own UI filters these out by default,
                // and importing them as real memberships would misrepresent a member's actual plan.
                $memQuery = $civiDb->prepare("
                    SELECT m.id AS civi_membership_id, membership_type_id, join_date, start_date, end_date, s.is_current_member
                    FROM civicrm_membership m
                    INNER JOIN civicrm_membership_status s ON m.status_id = s.id
                    WHERE m.contact_id = :contact_id AND m.is_test = 0
                    ORDER BY m.end_date DESC
                    LIMIT 1
                ");
                $memQuery->execute(['contact_id' => $contactId]);
                $civiMem = $memQuery->fetch();

                if ($civiMem) {
                    $civiMemTypeId = (int)$civiMem['membership_type_id'];
                    $status = $civiMem['is_current_member'] ? 'active' : 'expired';

                    $joinDate  = $civiMem['join_date']  ?? $civiMem['start_date'] ?? $civiMem['end_date'];
                    $startDate = $civiMem['start_date'] ?? $joinDate;
                    $endDate   = $civiMem['end_date'];

                    if (!$joinDate || !$startDate || !$endDate) {
                        $stats['errors'][] = "Skipped subscription sync for contact #{$contactId}: membership record has null date fields (join={$civiMem['join_date']}, start={$civiMem['start_date']}, end={$civiMem['end_date']})";
                    } else {
                        // Find matching local plan
                        $planQuery = $appDb->prepare("SELECT id, name, default_rate_id FROM tgg_subscription_plans WHERE civicrm_membership_type_id = :civi_type LIMIT 1");
                        $planQuery->execute(['civi_type' => $civiMemTypeId]);
                        $localPlan = $planQuery->fetch();

                        if ($localPlan) {
                            $planId = (int)$localPlan['id'];

                            // The plan's current default rate is now a direct pointer (set above
                            // in step 0) rather than inferred from the "{plan} - Standard" name.
                            $defaultRateId = $localPlan['default_rate_id'] ?: null;
                            $currentPrice = 0.0;
                            if ($defaultRateId) {
                                $currentPriceStmt = $appDb->prepare("SELECT price FROM tgg_subscription_rates WHERE id = :id");
                                $currentPriceStmt->execute(['id' => $defaultRateId]);
                                $currentPrice = (float)($currentPriceStmt->fetchColumn() ?: 0);
                            }

                            // Detect a grandfathered rate: check what this contact was actually
                            // last billed for this specific membership (via
                            // civicrm_membership_payment -- far more reliable than assuming
                            // everyone pays the plan's current price). Only treated as a
                            // grandfathered rate if it's one of the club's known historical rates
                            // (see KNOWN_HISTORICAL_RATES) -- an unrecognized one-off amount is a
                            // one-time contribution, not evidence of a different ongoing dues
                            // rate, so it's ignored here rather than spawning a new rate for it.
                            $rateToUse = $defaultRateId;
                            $recentAmountStmt = $civiDb->prepare("
                                SELECT c.total_amount
                                FROM civicrm_membership_payment mp
                                INNER JOIN civicrm_contribution c ON c.id = mp.contribution_id
                                WHERE mp.membership_id = :membership_id AND c.contribution_status_id = 1 AND c.is_test = 0
                                ORDER BY c.receive_date DESC
                                LIMIT 1
                            ");
                            $recentAmountStmt->execute(['membership_id' => (int)$civiMem['civi_membership_id']]);
                            $recentAmount = $recentAmountStmt->fetchColumn();

                            if ($recentAmount !== false) {
                                $recentAmount = (float)$recentAmount;
                            }

                            if ($recentAmount !== false
                                && round($recentAmount, 2) !== round($currentPrice, 2)
                                && self::isKnownDuesRate($localPlan['name'], $recentAmount, $currentPrice)) {
                                $grandfatheredName = $localPlan['name'] . ' - Grandfathered $' . number_format((float)$recentAmount, 2);
                                $grRateStmt = $appDb->prepare("SELECT id FROM tgg_subscription_rates WHERE plan_id = :plan_id AND name = :name LIMIT 1");
                                $grRateStmt->execute(['plan_id' => $planId, 'name' => $grandfatheredName]);
                                $grandfatheredRateId = $grRateStmt->fetchColumn();

                                if (!$grandfatheredRateId) {
                                    // Match the default rate's billing frequency -- grandfathering
                                    // preserves the same billing cadence, just at the old price.
                                    $freqStmt = $appDb->prepare("SELECT billing_frequency FROM tgg_subscription_rates WHERE id = :id");
                                    $freqStmt->execute(['id' => $defaultRateId]);
                                    $billingFrequency = $freqStmt->fetchColumn() ?: 'monthly';

                                    // Backdate this rate's effective date to the earliest known
                                    // payment at this historical amount (across all members of this
                                    // plan), not "now" -- it reflects when the old rate actually took
                                    // effect, not when we happened to import the data.
                                    $earliestDateStmt = $civiDb->prepare("
                                        SELECT MIN(c.receive_date)
                                        FROM civicrm_contribution c
                                        INNER JOIN civicrm_membership_payment mp ON mp.contribution_id = c.id
                                        INNER JOIN civicrm_membership m ON m.id = mp.membership_id
                                        WHERE m.membership_type_id = :type_id
                                          AND ROUND(c.total_amount, 2) = ROUND(:amount, 2)
                                          AND c.contribution_status_id = 1 AND c.is_test = 0
                                    ");
                                    $earliestDateStmt->execute(['type_id' => $civiMemTypeId, 'amount' => $recentAmount]);
                                    $effectiveDate = $earliestDateStmt->fetchColumn() ?: date('Y-m-d H:i:s');

                                    $insertGrRate = $appDb->prepare("
                                        INSERT INTO tgg_subscription_rates (plan_id, name, price, billing_frequency, inactive, created_at)
                                        VALUES (:plan_id, :name, :price, :billing_frequency, 0, :created_at)
                                    ");
                                    $insertGrRate->execute([
                                        'plan_id' => $planId,
                                        'name' => $grandfatheredName,
                                        'price' => $recentAmount,
                                        'billing_frequency' => $billingFrequency,
                                        'created_at' => $effectiveDate
                                    ]);
                                    $grandfatheredRateId = $appDb->lastInsertId();
                                    $stats['grandfathered_rates_created']++;
                                }
                                $rateToUse = $grandfatheredRateId;
                            }

                            // Insert or Update local subscription
                            $subCheck = $appDb->prepare("SELECT contact_id, plan_id, rate_id FROM tgg_subscriptions WHERE contact_id = :contact_id LIMIT 1");
                            $subCheck->execute(['contact_id' => $contactId]);
                            $existingSub = $subCheck->fetch();

                            if ($existingSub) {
                                // Only auto-correct rate_id when there's no rate yet, it doesn't
                                // belong to this plan at all (e.g. left over from a plan change),
                                // or it belongs to this plan but is itself one the importer manages
                                // (named "{plan} - Standard" or "{plan} - Grandfathered $X" --
                                // recognizable by that convention). A plan can legitimately have
                                // several such rates now (Standard plus one per distinct
                                // grandfathered price), so merely "belongs to this plan" is no
                                // longer a strong enough signal on its own. Anything else -- a
                                // differently-named rate -- is assumed to be a member-specific
                                // custom rate an admin assigned through the app, and is left alone.
                                $rateId = $existingSub['rate_id'];
                                $needsRateUpdate = empty($rateId);
                                if (!$needsRateUpdate) {
                                    $currentRateStmt = $appDb->prepare("SELECT plan_id, name FROM tgg_subscription_rates WHERE id = :id");
                                    $currentRateStmt->execute(['id' => $rateId]);
                                    $currentRate = $currentRateStmt->fetch();
                                    if (!$currentRate || (int)$currentRate['plan_id'] !== $planId) {
                                        $needsRateUpdate = true;
                                    } else {
                                        $needsRateUpdate = $currentRate['name'] === $localPlan['name'] . ' - Standard'
                                            || strpos($currentRate['name'], $localPlan['name'] . ' - Grandfathered $') === 0;
                                    }
                                }
                                if ($needsRateUpdate) {
                                    $rateId = $rateToUse ?: $rateId;
                                }

                                $subUpdate = $appDb->prepare("
                                    UPDATE tgg_subscriptions
                                    SET plan_id = :plan_id, status = :status, join_date = :join_date, start_date = :start_date, end_date = :end_date, rate_id = :rate_id
                                    WHERE contact_id = :contact_id
                                ");
                                $subUpdate->execute([
                                    'plan_id'    => $planId,
                                    'status'     => $status,
                                    'join_date'  => $joinDate,
                                    'start_date' => $startDate,
                                    'end_date'   => $endDate,
                                    'rate_id'    => $rateId,
                                    'contact_id' => $contactId
                                ]);
                            } else {
                                $subInsert = $appDb->prepare("
                                    INSERT INTO tgg_subscriptions (contact_id, plan_id, status, join_date, start_date, end_date, rate_id)
                                    VALUES (:contact_id, :plan_id, :status, :join_date, :start_date, :end_date, :rate_id)
                                ");
                                $subInsert->execute([
                                    'contact_id' => $contactId,
                                    'plan_id'    => $planId,
                                    'status'     => $status,
                                    'join_date'  => $joinDate,
                                    'start_date' => $startDate,
                                    'end_date'   => $endDate,
                                    'rate_id'    => $rateToUse
                                ]);
                            }
                        }
                    }
                }

                // D. Check for an active Founder membership and permanently flag the member.
                // Once set, the flag is never cleared — it is a badge of honour.
                // Same is_active vs is_current_member distinction as section C above applies here
                // too, plus excluding CiviCRM's own test-mode records.
                try {
                    $founderQuery = $civiDb->prepare("
                        SELECT COUNT(*) FROM civicrm_membership m
                        INNER JOIN civicrm_membership_type mt ON m.membership_type_id = mt.id
                        INNER JOIN civicrm_membership_status s ON m.status_id = s.id
                        WHERE m.contact_id = :contact_id
                          AND mt.name = 'Founder'
                          AND s.is_current_member = 1
                          AND m.is_test = 0
                    ");
                    $founderQuery->execute(['contact_id' => $contactId]);
                    if ((int)$founderQuery->fetchColumn() > 0) {
                        $appDb->prepare("UPDATE tgg_member_settings SET is_founder = 1 WHERE contact_id = :contact_id AND is_founder = 0")
                              ->execute(['contact_id' => $contactId]);
                        if ($appDb->query("SELECT ROW_COUNT()")->fetchColumn() > 0) {
                            $stats['founders_flagged']++;
                        }
                    }
                } catch (Exception $e) {
                    $stats['errors'][] = "Failed checking founder status for contact #{$contactId}: " . $e->getMessage();
                }
            } catch (Exception $e) {
                $stats['errors'][] = "Failed syncing contact #{$contactId}: " . $e->getMessage();
            }
        }

        // 2. Sync CiviCRM Contributions (Payments) to local tgg_billing_ledger
        try {
            // Cache local plans two ways: by CiviCRM membership_type_id (authoritative, resolved
            // below via each contribution's actual linked membership) and by current price (a
            // last-resort fallback only for contributions with no membership link at all, e.g.
            // donations). Amount-matching alone is unreliable -- it breaks down completely for
            // grandfathered/custom rates, e.g. an old $20/month "Regular Monthly" payment doesn't
            // match that plan's current $30/month price, and would previously silently default to
            // whichever plan happened to be first in this list (misattributing real membership
            // history to the wrong plan, e.g. "Founder" for a $20 Regular Monthly payment).
            $plansRaw = $appDb->query("
                SELECT p.id, p.name, dr.price, p.civicrm_membership_type_id
                FROM tgg_subscription_plans p
                LEFT JOIN tgg_subscription_rates dr ON p.default_rate_id = dr.id
            ")->fetchAll();
            $plansByTypeId = [];
            $plansByPrice = [];
            $plansById = [];
            foreach ($plansRaw as $p) {
                $plansByTypeId[(int)$p['civicrm_membership_type_id']] = (int)$p['id'];
                $plansByPrice[(int)round((float)$p['price'])] = (int)$p['id'];
                $plansById[(int)$p['id']] = ['name' => $p['name'], 'price' => (float)$p['price']];
            }

            // Fetch completed contributions from CiviCRM, excluding CiviCRM's own test-mode
            // records (see the is_test note on the membership query above), along with the
            // specific membership (if any) each one actually paid for via
            // civicrm_membership_payment.
            $contribQuery = $civiDb->prepare("
                SELECT c.id, c.contact_id, c.receive_date, c.total_amount, c.trxn_id, m.membership_type_id
                FROM civicrm_contribution c
                LEFT JOIN civicrm_membership_payment mp ON mp.contribution_id = c.id
                LEFT JOIN civicrm_membership m ON m.id = mp.membership_id
                WHERE c.contribution_status_id = 1 AND c.is_test = 0
                ORDER BY c.receive_date ASC, c.id ASC
            ");
            $contribQuery->execute();
            $contributions = $contribQuery->fetchAll();

            $insertLedger = $appDb->prepare("
                INSERT INTO tgg_billing_ledger (contact_id, plan_id, stripe_session_id, payment_intent_id, amount, currency, payment_status, action_type, created_at)
                VALUES (:contact_id, :plan_id, :stripe_session_id, :payment_intent_id, :amount, 'usd', 'paid', :action_type, :created_at)
                ON DUPLICATE KEY UPDATE
                    plan_id = VALUES(plan_id),
                    amount = VALUES(amount),
                    action_type = VALUES(action_type),
                    created_at = VALUES(created_at)
            ");

            $contactContribsCount = [];

            foreach ($contributions as $contrib) {
                try {
                    $cid = (int)$contrib['contact_id'];
                    $amount = (float)$contrib['total_amount'];
                    $trxnId = $contrib['trxn_id'];
                    $civiId = (int)$contrib['id'];
                    $civiMembershipTypeId = $contrib['membership_type_id'] !== null ? (int)$contrib['membership_type_id'] : null;

                    // Prefer the plan this contribution was actually linked to via its membership;
                    // only fall back to amount-matching (and only on an exact current-price match,
                    // never an arbitrary default) when there's no membership link at all.
                    $planId = null;
                    if ($civiMembershipTypeId !== null && isset($plansByTypeId[$civiMembershipTypeId])) {
                        $planId = $plansByTypeId[$civiMembershipTypeId];
                    } else {
                        $amountRounded = (int)round($amount);
                        $planId = $plansByPrice[$amountRounded] ?? null;
                    }

                    if (!$planId) {
                        $stats['errors'][] = "Skipped contribution #{$civiId} (contact #{$cid}): could not determine plan (no membership link, and amount \${$amount} doesn't match any current plan price)";
                        continue;
                    }

                    // A one-time contribution (e.g. a donation or partial payment) isn't a dues
                    // cycle event -- label it distinctly rather than folding it into the join/renew
                    // sequence, and don't let it consume a slot in that sequence either, so the
                    // next genuine dues payment is still correctly identified as this contact's
                    // first ("join") or a later ("renew") one.
                    $plan = $plansById[$planId] ?? null;
                    $isOneTime = $plan !== null && !self::isKnownDuesRate($plan['name'], $amount, $plan['price']);

                    if ($isOneTime) {
                        $actionType = 'one_time';
                    } else {
                        if (!isset($contactContribsCount[$cid])) {
                            $contactContribsCount[$cid] = 0;
                        }
                        $actionType = ($contactContribsCount[$cid] > 0) ? 'renew' : 'join';
                        $contactContribsCount[$cid]++;
                    }

                    $stripeSessionId = "civi_contrib_" . $civiId;
                    $paymentIntentId = !empty($trxnId) ? $trxnId : "civi_contrib_" . $civiId;

                    $insertLedger->execute([
                        'contact_id'       => $cid,
                        'plan_id'          => $planId,
                        'stripe_session_id' => $stripeSessionId,
                        'payment_intent_id' => $paymentIntentId,
                        'amount'           => $amount,
                        'action_type'      => $actionType,
                        'created_at'       => $contrib['receive_date']
                    ]);

                    $stats['contributions_synced']++;
                } catch (Exception $e) {
                    $stats['errors'][] = "Failed syncing contribution #{$contrib['id']} (contact #{$contrib['contact_id']}): " . $e->getMessage();
                }
            }
        } catch (Exception $e) {
            $stats['errors'][] = "Failed syncing contributions: " . $e->getMessage();
        }

        return $stats;
    }
}
