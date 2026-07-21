<?php
namespace App;

use Exception;
use PDO;

/**
 * Membership Credits bookkeeping.
 *
 * Hosting is the first way to earn Membership Credits; more will follow, so this
 * class -- not "VolunteerCredits" -- owns the shared bank: FIFO earned/applied/
 * expired accounting (against tgg_volunteer_credit_transactions, still named after
 * its original single source), the whole-number redemption math, and the new
 * attendance-confirmation earning action. Spending (extending a membership) is
 * BillingHelper's job -- see BillingHelper::applyMembershipCreditsToMembership().
 */
class MembershipCredits {

    /**
     * All tgg_volunteer_credits rows as credit_key => credits.
     */
    public static function getConfigMap(): array {
        $appDb = Database::getAppConnection();
        $stmt = $appDb->query("SELECT credit_key, credits FROM tgg_volunteer_credits");
        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    /**
     * Whole Membership Credits required to redeem one month of membership.
     */
    public static function getConversionRate(): int {
        $map = self::getConfigMap();
        $rate = (int)round((float)($map['credits_per_month'] ?? 4));
        return $rate > 0 ? $rate : 4;
    }

    /**
     * Days after which an unspent Membership Credit grant expires.
     */
    public static function getExpirationDays(): float {
        $map = self::getConfigMap();
        return (float)($map['credit_expiration_days'] ?? 365.0);
    }

    /**
     * FIFO-walk a member's earn/apply transactions once, returning both the
     * per-grant detail (used by getTransactionHistory()) and the aggregate
     * totals (used by getCreditSummary()). Shared so the two views can never
     * drift out of sync with each other.
     */
    private static function computeFifo(int $contactId, float $expirationDays): array {
        $appDb = Database::getAppConnection();
        $stmt = $appDb->prepare("
            SELECT id, event_id, slot_id, volunteer_date, shift, credits_earned, credits_applied
            FROM tgg_volunteer_credit_transactions
            WHERE contact_id = :contact_id
            ORDER BY volunteer_date ASC, id ASC
        ");
        $stmt->execute(['contact_id' => $contactId]);
        $allTx = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $earned = [];
        $applied = [];
        $totalEarned = 0;
        $totalApplied = 0;

        foreach ($allTx as $tx) {
            $valEarned = (int)round((float)$tx['credits_earned']);
            $valApplied = (int)round((float)$tx['credits_applied']);
            if ($valEarned > 0) {
                $earned[] = [
                    'id' => (int)$tx['id'],
                    'event_id' => $tx['event_id'] !== null ? (int)$tx['event_id'] : null,
                    'shift' => $tx['shift'],
                    'date' => $tx['volunteer_date'],
                    'granted' => $valEarned,
                    'remaining' => $valEarned,
                ];
                $totalEarned += $valEarned;
            }
            if ($valApplied > 0) {
                $applied[] = ['date' => $tx['volunteer_date'], 'amount' => $valApplied];
                $totalApplied += $valApplied;
            }
        }

        $today = date('Y-m-d');
        $expDays = (int)$expirationDays;

        if ($expDays > 0) {
            foreach ($applied as $appTx) {
                $appAmount = $appTx['amount'];
                $appDate = $appTx['date'];

                foreach ($earned as &$earnTx) {
                    if ($earnTx['remaining'] <= 0 || $appAmount <= 0) {
                        continue;
                    }
                    $expireDate = date('Y-m-d', strtotime($earnTx['date'] . " + {$expDays} days"));
                    if ($expireDate < $appDate) {
                        continue; // already expired at the time this application happened
                    }
                    if ($appAmount >= $earnTx['remaining']) {
                        $appAmount -= $earnTx['remaining'];
                        $earnTx['remaining'] = 0;
                    } else {
                        $earnTx['remaining'] -= $appAmount;
                        $appAmount = 0;
                    }
                }
                unset($earnTx);
            }
        }

        $totalExpired = 0;
        $nextExpirationDate = null;
        $nextExpirationCredits = 0;
        $expiringCandidates = [];

        foreach ($earned as &$earnTx) {
            $earnTx['expires_on'] = $expDays > 0
                ? date('Y-m-d', strtotime($earnTx['date'] . " + {$expDays} days"))
                : null;
            $earnTx['used'] = $earnTx['granted'] - $earnTx['remaining'];
            $earnTx['is_expired'] = $expDays > 0 && $earnTx['remaining'] > 0 && $earnTx['expires_on'] < $today;

            if ($earnTx['is_expired']) {
                $totalExpired += $earnTx['remaining'];
            } elseif ($earnTx['remaining'] > 0 && $earnTx['expires_on'] !== null) {
                $expiringCandidates[] = ['expire_date' => $earnTx['expires_on'], 'amount' => $earnTx['remaining']];
            }

            if ($earnTx['remaining'] <= 0) {
                $earnTx['status'] = 'fully_used';
            } elseif ($earnTx['is_expired']) {
                $earnTx['status'] = 'expired';
            } elseif ($earnTx['used'] > 0) {
                $earnTx['status'] = 'partially_used';
            } else {
                $earnTx['status'] = 'available';
            }
        }
        unset($earnTx);

        if (!empty($expiringCandidates)) {
            usort($expiringCandidates, fn($a, $b) => strcmp($a['expire_date'], $b['expire_date']));
            $nextExpirationDate = $expiringCandidates[0]['expire_date'];
            foreach ($expiringCandidates as $cand) {
                if ($cand['expire_date'] === $nextExpirationDate) {
                    $nextExpirationCredits += $cand['amount'];
                }
            }
        }

        $available = max(0, $totalEarned - $totalApplied - $totalExpired);

        return [
            'grants' => array_reverse($earned), // most recent first for display
            'earned' => $totalEarned,
            'applied' => $totalApplied,
            'expired' => $totalExpired,
            'available' => $available,
            'next_expiration_date' => $nextExpirationDate,
            'next_expiration_credits' => $nextExpirationCredits,
        ];
    }

    /**
     * Aggregate Membership Credits summary for a member, persisting the cached
     * totals on tgg_member_settings (same side effect the old duplicated copies
     * of this logic already had, so nothing else that reads those columns
     * directly needs to change).
     */
    public static function getCreditSummary(int $contactId): array {
        $result = self::computeFifo($contactId, self::getExpirationDays());

        $appDb = Database::getAppConnection();
        $check = $appDb->prepare("SELECT contact_id FROM tgg_member_settings WHERE contact_id = :contact_id LIMIT 1");
        $check->execute(['contact_id' => $contactId]);
        if ($check->fetch()) {
            $update = $appDb->prepare("
                UPDATE tgg_member_settings
                SET credits_earned = :earned, credits_applied = :applied, expired_credits = :expired
                WHERE contact_id = :contact_id
            ");
            $update->execute([
                'earned' => $result['earned'],
                'applied' => $result['applied'],
                'expired' => $result['expired'],
                'contact_id' => $contactId,
            ]);
        }

        unset($result['grants']);
        return $result;
    }

    /**
     * Itemized per-grant view for member-facing display: each row shows how
     * much of that specific grant has been used vs. is still remaining, so a
     * partially-spent grant (e.g. "5 granted, 3 used, 2 remaining") is visible
     * instead of only a lifetime aggregate.
     */
    public static function getTransactionHistory(int $contactId): array {
        return self::computeFifo($contactId, self::getExpirationDays())['grants'];
    }

    public static function getAvailableCredits(int $contactId): int {
        return self::getCreditSummary($contactId)['available'];
    }

    /**
     * Whole membership-months redeemable right now from banked, unexpired credits.
     */
    public static function getRedeemableMonths(int $contactId): int {
        $available = self::getAvailableCredits($contactId);
        return intdiv($available, self::getConversionRate());
    }

    /**
     * Grant Membership Credits for a shift a member signed up and was confirmed
     * for -- this is the ONLY way credits get earned. It never touches
     * tgg_subscriptions or tgg_billing_ledger; spending is a fully separate step
     * that only happens at renewal time.
     *
     * Normally invoked automatically by bin/autorenew.php via
     * autoConfirmEligibleAttendance() once a shift clears its grace period, with
     * $actorContactId left null (recorded as the "System (auto-renew)" actor).
     * Kept as a standalone building block in case a manual/support grant is ever
     * needed again.
     *
     * @throws Exception if the slot/signup doesn't exist, isn't confirmed, the
     *                    event hasn't happened yet, or this shift was already
     *                    confirmed (a member can't be credited twice for the
     *                    same shift).
     */
    public static function confirmAttendance(int $slotId, ?int $actorContactId = null): array {
        $appDb = Database::getAppConnection();

        $stmt = $appDb->prepare("
            SELECT s.contact_id, s.status, sl.event_id, sl.slot_label, sl.slot_type, e.start_time, e.title AS event_title
            FROM tgg_volunteer_signups s
            INNER JOIN tgg_event_slots sl ON sl.id = s.slot_id
            INNER JOIN tgg_events e ON e.id = sl.event_id
            WHERE s.slot_id = :slot_id
            LIMIT 1
        ");
        $stmt->execute(['slot_id' => $slotId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new Exception("No signup found for this slot.");
        }
        if ($row['status'] !== 'confirmed') {
            throw new Exception("Cannot confirm attendance for a signup that was never confirmed.");
        }
        if (strtotime($row['start_time']) > time()) {
            throw new Exception("This event hasn't happened yet.");
        }

        $dupeCheck = $appDb->prepare("SELECT id FROM tgg_volunteer_credit_transactions WHERE slot_id = :slot_id AND contact_id = :contact_id LIMIT 1");
        $dupeCheck->execute(['slot_id' => $slotId, 'contact_id' => $row['contact_id']]);
        if ($dupeCheck->fetch()) {
            throw new Exception("Attendance for this shift was already confirmed.");
        }

        $configMap = self::getConfigMap();
        $creditKey = EventSlot::creditKey($row['slot_type'], $row['start_time']);
        $creditsEarned = (int)round((float)($configMap[$creditKey] ?? 0));

        $actorCols = AuditLog::actorColumns($actorContactId);
        $insert = $appDb->prepare("
            INSERT INTO tgg_volunteer_credit_transactions
                (contact_id, event_id, slot_id, volunteer_date, shift, credits_earned, credits_applied, created_by, impersonator_id, source)
            VALUES
                (:contact_id, :event_id, :slot_id, :volunteer_date, :shift, :credits_earned, 0, :created_by, :impersonator_id, :source)
        ");
        $insert->execute([
            'contact_id' => $row['contact_id'],
            'event_id' => $row['event_id'],
            'slot_id' => $slotId,
            'volunteer_date' => date('Y-m-d', strtotime($row['start_time'])),
            'shift' => $row['slot_label'],
            'credits_earned' => $creditsEarned,
            'created_by' => $actorCols['created_by'],
            'impersonator_id' => $actorCols['impersonator_id'],
            'source' => $actorCols['source'],
        ]);

        self::getCreditSummary((int)$row['contact_id']); // refresh cached totals

        AuditLog::log('volunteer_config', 'membership_credits_earned', [
            'slot_id' => $slotId,
            'event_id' => (int)$row['event_id'],
            'shift' => $row['slot_label'],
            'credits_earned' => $creditsEarned,
        ], (int)$row['contact_id'], $actorContactId);

        return [
            'slot_id' => $slotId,
            'contact_id' => (int)$row['contact_id'],
            'event_id' => (int)$row['event_id'],
            'event_title' => $row['event_title'],
            'slot_label' => $row['slot_label'],
            'credits_earned' => $creditsEarned,
        ];
    }

    /**
     * Grant Membership Credits for every confirmed signup whose shift happened
     * at least $graceDays days ago and hasn't been credited yet. Called daily by
     * bin/autorenew.php -- the grace period gives a Hosting Manager time to fix
     * an incorrect volunteer list before credits go out.
     */
    public static function autoConfirmEligibleAttendance(int $graceDays = 3): array {
        $appDb = Database::getAppConnection();

        $stmt = $appDb->prepare("
            SELECT s.slot_id
            FROM tgg_volunteer_signups s
            INNER JOIN tgg_event_slots sl ON sl.id = s.slot_id
            INNER JOIN tgg_events e ON e.id = sl.event_id
            LEFT JOIN tgg_volunteer_credit_transactions t ON t.slot_id = s.slot_id AND t.contact_id = s.contact_id
            WHERE s.status = 'confirmed'
              AND e.start_time <= :cutoff
              AND t.id IS NULL
            ORDER BY e.start_time ASC, sl.sort_order ASC
        ");
        $stmt->execute(['cutoff' => date('Y-m-d H:i:s', strtotime("-{$graceDays} days"))]);
        $slotIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $confirmed = [];
        $failed = [];
        foreach ($slotIds as $slotId) {
            try {
                $confirmed[] = self::confirmAttendance((int)$slotId, null);
            } catch (Exception $e) {
                $failed[] = "Slot #{$slotId}: " . $e->getMessage();
            }
        }
        return ['confirmed' => $confirmed, 'failed' => $failed];
    }
}
