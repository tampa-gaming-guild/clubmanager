<?php
namespace App;

/**
 * Core check-in processing shared by the self-service kiosk (checkin.php),
 * the host override terminal (host_checkin.php), and the mobile API. Callers
 * own everything context-specific: resolving $contactId, deciding whether the
 * check-in window is open right now (self-service and host terminals use
 * different windows -- see Event::isCheckinWindowOpen() vs
 * Event::getActiveSession()), CSRF/origin validation, geolocation, and any
 * host-only pre-steps like in-person Trial activation.
 */
class CheckinService {
    /**
     * Process a check-in for an already-resolved, existing contact: dup-check,
     * membership/entrance-fee gating, guest-pass limit enforcement, and the
     * tgg_checkins insert(s).
     *
     * @param array $guestNames Pre-sanitized guest names (see sanitizeGuestNames())
     * @param bool $suppressRedirectIfPendingPayment Self-service only: if the
     *        member already has a pending cash payment on file, return an
     *        error pointing them to the host instead of a payment redirect --
     *        avoids a duplicate pending-payment request from an unaccompanied
     *        kiosk visitor. Hosts handle this in person, so host-initiated
     *        check-ins should leave this false.
     * @return array{
     *   ok: bool,
     *   error: ?string,
     *   redirect_reason: ?string,
     *   message: ?string,
     *   details: ?array{name: string, membership: string, expires: string}
     * } redirect_reason is 'renewal'|'entrance_fee' when set -- the caller
     *   builds the actual pay-entrance.php URL, since the return path differs
     *   (checkin.php vs host_checkin.php).
     */
    public static function checkIn(
        int $contactId,
        string $notes,
        array $guestNames = [],
        bool $suppressRedirectIfPendingPayment = false
    ): array {
        $appDb = Database::getAppConnection();

        $contactStmt = $appDb->prepare("SELECT id FROM tgg_contacts WHERE id = :id AND is_deleted = 0 LIMIT 1");
        $contactStmt->execute(['id' => $contactId]);
        if (!$contactStmt->fetch()) {
            return self::errorResult('Member not found.');
        }
        $contactName = MembershipService::getFormattedName($contactId);

        $hasCheckedInToday = self::hasCheckedInToday($contactId);
        if ($hasCheckedInToday && empty($guestNames)) {
            return self::errorResult("Check-in Denied: {$contactName} has already checked in today.");
        }

        $membership = MembershipService::getMemberMembershipDetails($contactId);

        if (!$membership || !$membership['is_active']) {
            if ($suppressRedirectIfPendingPayment && self::hasPendingPayment($contactId)) {
                return self::errorResult("You already have a pending payment with the host. Please see the host to complete your check-in.");
            }
            return self::redirectResult('renewal');
        }

        if (BillingHelper::entranceFeeOwed($membership)) {
            if ($suppressRedirectIfPendingPayment && self::hasPendingPayment($contactId)) {
                return self::errorResult("You already have a pending payment with the host. Please see the host to complete your check-in.");
            }
            return self::redirectResult('entrance_fee');
        }

        if (!empty($guestNames)) {
            $passes = BillingHelper::getGuestPassesRemaining($contactId, $membership);
            if (count($guestNames) > $passes['remaining']) {
                return self::errorResult("Only {$passes['remaining']} guest pass(es) remaining this month for {$contactName}.");
            }
        }

        if (!$hasCheckedInToday) {
            $appDb->prepare("INSERT INTO tgg_checkins (contact_id, checked_in_at, notes) VALUES (:contact_id, NOW(), :notes)")
                ->execute(['contact_id' => $contactId, 'notes' => $notes]);
        }

        if (!empty($guestNames)) {
            $insertGuest = $appDb->prepare("INSERT INTO tgg_checkins (contact_id, checked_in_at, guest_name) VALUES (:contact_id, NOW(), :guest_name)");
            foreach ($guestNames as $guestName) {
                $insertGuest->execute(['contact_id' => $contactId, 'guest_name' => $guestName]);
            }
        }

        $message = "Check-In Successful! Welcome, {$contactName}.";
        if (!empty($guestNames)) {
            $message .= " Checked in with " . count($guestNames) . " guest(s).";
        }

        return [
            'ok' => true,
            'error' => null,
            'redirect_reason' => null,
            'message' => $message,
            'details' => [
                'name' => $contactName,
                'membership' => $membership['membership_name'],
                'expires' => date('M d, Y', strtotime($membership['end_date'])),
            ],
        ];
    }

    /** True if $contactId already has a non-guest check-in row for today. */
    public static function hasCheckedInToday(int $contactId): bool {
        $appDb = Database::getAppConnection();
        $stmt = $appDb->prepare("SELECT COUNT(*) FROM tgg_checkins WHERE contact_id = :contact_id AND guest_name IS NULL AND DATE(checked_in_at) = CURDATE()");
        $stmt->execute(['contact_id' => $contactId]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /** Trim to 100 chars, drop blanks, cap at 10 -- the rule shared by every check-in entry point. */
    public static function sanitizeGuestNames(array $rawNames): array {
        $guestNames = [];
        foreach ($rawNames as $guestName) {
            $guestName = trim(mb_substr((string)$guestName, 0, 100));
            if ($guestName !== '') {
                $guestNames[] = $guestName;
            }
            if (count($guestNames) >= 10) {
                break;
            }
        }
        return $guestNames;
    }

    private static function hasPendingPayment(int $contactId): bool {
        $appDb = Database::getAppConnection();
        $stmt = $appDb->prepare("SELECT COUNT(*) FROM tgg_pending_payments WHERE contact_id = :contact_id AND status = 'pending'");
        $stmt->execute(['contact_id' => $contactId]);
        return (int)$stmt->fetchColumn() > 0;
    }

    private static function errorResult(string $message): array {
        return ['ok' => false, 'error' => $message, 'redirect_reason' => null, 'message' => null, 'details' => null];
    }

    private static function redirectResult(string $reason): array {
        return ['ok' => false, 'error' => null, 'redirect_reason' => $reason, 'message' => null, 'details' => null];
    }
}
