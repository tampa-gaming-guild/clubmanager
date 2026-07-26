<?php
namespace App;

use Exception;

/**
 * Shared logic behind pay-entrance.php's Card/Cash + tier-selection flow,
 * factored out for the mobile API's native equivalent (api/payment-*.php
 * and api/host/payment-*.php) -- both self-service check-in and host
 * check-in use this on mobile, instead of loading pay-entrance.php itself
 * in a webview. pay-entrance.php (the web kiosk page) is untouched and
 * still owns this logic for the web; this mirrors it, not replaces it.
 */
class PaymentFlow {

    /** True if the contact already has a cash payment awaiting host approval. */
    public static function hasPendingPayment(int $contactId): bool {
        $appDb = Database::getAppConnection();
        $stmt = $appDb->prepare("SELECT COUNT(*) FROM tgg_pending_payments WHERE contact_id = :contact_id AND status = 'pending'");
        $stmt->execute(['contact_id' => $contactId]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Context for the 'entrance_fee' reason: the contact's current
     * membership's price -- mirrors pay-entrance.php's load_payment_context().
     * @throws Exception if the contact/membership can't be resolved
     */
    public static function entranceFeeContext(int $contactId): array {
        $membership = MembershipService::getMemberMembershipDetails($contactId);
        if (!$membership) {
            throw new Exception("Member or membership could not be found.");
        }

        $appDb = Database::getAppConnection();
        $planId = (int)$membership['membership_id'];
        $planStmt = $appDb->prepare("SELECT civicrm_membership_type_id FROM tgg_subscription_plans WHERE id = :id LIMIT 1");
        $planStmt->execute(['id' => $planId]);
        $civicrmTypeId = (int)$planStmt->fetchColumn();

        return [
            'reason' => 'entrance_fee',
            'plan_id' => $planId,
            'civicrm_type_id' => $civicrmTypeId,
            'amount' => (float)$membership['price'],
            'membership_name' => $membership['membership_name'],
        ];
    }

    /**
     * Context for the 'renewal' reason: the renewable tier list (Trial
     * excluded) plus which one (if any) matches the contact's current plan
     * -- mirrors pay-entrance.php's get_renewable_tiers() plus its
     * current-tier/Trial-lapsed/brand-new-member checks.
     */
    public static function renewalContext(int $contactId): array {
        $tiers = array_values(array_filter(
            BillingHelper::getSubscriptionPlans(true),
            fn($tier) => !BillingHelper::isTrialPlan($tier)
        ));

        $currentMembership = MembershipService::getMemberMembershipDetails($contactId);
        $currentTierId = null;
        $priorPlanWasTrial = false;
        $isBrandNewMember = false;
        if ($currentMembership) {
            if (BillingHelper::isTrialPlan(['name' => $currentMembership['membership_name']])) {
                $priorPlanWasTrial = true;
            } else {
                $currentTierId = (int)$currentMembership['membership_id'];
            }
        } else {
            $isBrandNewMember = true;
        }

        return [
            'reason' => 'renewal',
            'tiers' => array_map(fn($t) => [
                'id' => (int)$t['id'],
                'name' => $t['name'],
                'minimum_fee' => (float)$t['minimum_fee'],
                'duration_interval' => (int)$t['duration_interval'],
                'duration_unit' => $t['duration_unit'],
                // A session-billed plan is free to join/renew -- selecting one
                // activates it immediately and pivots to owing today's entrance
                // fee instead, same as pay-entrance.php's redirect. Flagged here
                // so the app can say so before the member picks it.
                'is_session' => BillingHelper::isSessionPlan($t),
            ], $tiers),
            'current_tier_id' => $currentTierId,
            'prior_plan_was_trial' => $priorPlanWasTrial,
            'is_brand_new_member' => $isBrandNewMember,
        ];
    }

    /**
     * Resolves a renewal tier choice into what to actually charge. For a
     * session-billed plan, this activates the membership for free right
     * here (mirroring pay-entrance.php's immediate redirect-into-
     * entrance_fee behavior) and returns the resulting entrance_fee context
     * instead -- the caller should charge/pend THAT amount, not the tier's
     * own price (which is never charged for a session plan).
     * @return array{reason: string, plan_id: int, civicrm_type_id: int, amount: float, membership_name: string, pivoted_to_entrance_fee: bool}
     * @throws Exception on an invalid/Trial tier
     */
    public static function resolveRenewalCharge(int $contactId, int $tierId): array {
        $tiers = BillingHelper::getSubscriptionPlans(true);
        $tierIndex = array_search($tierId, array_column($tiers, 'id'));
        if ($tierIndex === false || BillingHelper::isTrialPlan($tiers[$tierIndex])) {
            throw new Exception("Invalid membership level.");
        }
        $tier = $tiers[$tierIndex];

        if (BillingHelper::isSessionPlan($tier)) {
            $appDb = Database::getAppConnection();
            $subCheck = $appDb->prepare("SELECT COUNT(*) FROM tgg_subscriptions WHERE contact_id = :contact_id");
            $subCheck->execute(['contact_id' => $contactId]);
            $action = ((int)$subCheck->fetchColumn() > 0) ? 'renew' : 'join';

            BillingHelper::activateSessionMembership($contactId, $tierId, $action);

            return array_merge(self::entranceFeeContext($contactId), ['pivoted_to_entrance_fee' => true]);
        }

        return [
            'reason' => 'renewal',
            'plan_id' => $tierId,
            'civicrm_type_id' => (int)$tier['civicrm_membership_type_id'],
            'amount' => (float)$tier['minimum_fee'],
            'membership_name' => $tier['name'],
            'pivoted_to_entrance_fee' => false,
        ];
    }
}
