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
     * @param ?string $checkedInAt Explicit 'Y-m-d H:i:s' timestamp for a
     *        backdated/missed check-in entered by a host after the fact.
     *        Null (the default) uses NOW(), the live check-in path. Passing
     *        this logs a 'checkins'/'checkin_added' audit event, since this
     *        is a correction rather than an ordinary live visit.
     * @param bool $skipMembershipCheck ONLY for the admin/checkins.php "Add
     *        Missed Check-In" feature -- skips the membership-active/
     *        entrance-fee gating below entirely, since that gating exists to
     *        stop an inactive member from checking themself in live without
     *        paying, and doesn't apply to a host-verified correction of a
     *        visit that already happened (whose membership status *today*
     *        may not even match what it was on that date). Every other
     *        caller (self-service kiosk, host terminal, mobile API) must
     *        leave this false.
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
        bool $suppressRedirectIfPendingPayment = false,
        ?string $checkedInAt = null,
        bool $skipMembershipCheck = false
    ): array {
        $appDb = Database::getAppConnection();
        $isBackdated = $checkedInAt !== null;
        $checkedInAt = $checkedInAt ?? date('Y-m-d H:i:s');
        $onDate = date('Y-m-d', strtotime($checkedInAt));

        $contactStmt = $appDb->prepare("SELECT id FROM tgg_contacts WHERE id = :id AND is_deleted = 0 LIMIT 1");
        $contactStmt->execute(['id' => $contactId]);
        if (!$contactStmt->fetch()) {
            return self::errorResult('Member not found.');
        }
        $contactName = MembershipService::getFormattedName($contactId);

        $hasCheckedInToday = self::hasCheckedInToday($contactId, $onDate);
        if ($hasCheckedInToday && empty($guestNames)) {
            $dayLabel = $isBackdated ? 'on ' . date('M j, Y', strtotime($onDate)) : 'today';
            return self::errorResult("Check-in Denied: {$contactName} has already checked in {$dayLabel}.");
        }

        $membership = MembershipService::getMemberMembershipDetails($contactId);

        // Only the "Add Missed Check-In" admin feature sets $skipMembershipCheck --
        // it's an admin correcting the record of a visit that already happened, so
        // it must not be blocked by the member's membership/entrance-fee status
        // *today*, which is irrelevant to (and may have since changed from) their
        // status on the date being corrected. Every other caller (live self-service/
        // host check-ins) always runs this gating.
        if (!$skipMembershipCheck) {
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
        }

        if (!empty($guestNames)) {
            $passes = BillingHelper::getGuestPassesRemaining($contactId, $membership);
            if (count($guestNames) > $passes['remaining']) {
                return self::errorResult("Only {$passes['remaining']} guest pass(es) remaining this month for {$contactName}.");
            }
        }

        $newCheckinId = null;
        if (!$hasCheckedInToday) {
            $appDb->prepare("INSERT INTO tgg_checkins (contact_id, checked_in_at, notes) VALUES (:contact_id, :checked_in_at, :notes)")
                ->execute(['contact_id' => $contactId, 'checked_in_at' => $checkedInAt, 'notes' => $notes]);
            $newCheckinId = (int)$appDb->lastInsertId();
        }

        if (!empty($guestNames)) {
            $insertGuest = $appDb->prepare("INSERT INTO tgg_checkins (contact_id, checked_in_at, guest_name) VALUES (:contact_id, :checked_in_at, :guest_name)");
            foreach ($guestNames as $guestName) {
                $insertGuest->execute(['contact_id' => $contactId, 'checked_in_at' => $checkedInAt, 'guest_name' => $guestName]);
            }
        }

        // A backdated add is a correction, not an ordinary live visit -- audit it.
        // Live self-service/host check-ins stay unaudited, matching today's volume.
        if ($isBackdated) {
            AuditLog::log('checkins', 'checkin_added', [
                'checkin_id' => $newCheckinId,
                'checked_in_at' => $checkedInAt,
                'notes' => $notes,
                'guest_names' => $guestNames,
            ], $contactId);
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
                'membership' => $membership['membership_name'] ?? null,
                'expires' => $membership ? date('M d, Y', strtotime($membership['end_date'])) : null,
            ],
        ];
    }

    /** True if $contactId already has a non-guest check-in row for $onDate (default today). */
    public static function hasCheckedInToday(int $contactId, ?string $onDate = null): bool {
        $appDb = Database::getAppConnection();
        $stmt = $appDb->prepare("SELECT COUNT(*) FROM tgg_checkins WHERE contact_id = :contact_id AND guest_name IS NULL AND DATE(checked_in_at) = :on_date");
        $stmt->execute(['contact_id' => $contactId, 'on_date' => $onDate ?? date('Y-m-d')]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Correct an existing check-in's time/notes/guest name after the fact.
     * @return array{ok: bool, error: ?string, contact_id: ?int}
     */
    public static function updateCheckin(int $checkinId, string $checkedInAt, ?string $notes, ?string $guestName): array {
        $appDb = Database::getAppConnection();

        $before = self::fetchCheckin($appDb, $checkinId);
        if (!$before) {
            return ['ok' => false, 'error' => 'Check-in record not found.', 'contact_id' => null];
        }

        $appDb->prepare("UPDATE tgg_checkins SET checked_in_at = :checked_in_at, notes = :notes, guest_name = :guest_name WHERE id = :id")
            ->execute([
                'checked_in_at' => $checkedInAt,
                'notes' => $notes,
                'guest_name' => $guestName,
                'id' => $checkinId,
            ]);

        AuditLog::log('checkins', 'checkin_edited', [
            'checkin_id' => $checkinId,
            'before' => $before,
            'after' => ['checked_in_at' => $checkedInAt, 'notes' => $notes, 'guest_name' => $guestName],
        ], (int)$before['contact_id']);

        return ['ok' => true, 'error' => null, 'contact_id' => (int)$before['contact_id']];
    }

    /**
     * Delete a check-in record, with the audit logging none of the three
     * existing raw-DELETE call sites (admin/checkins.php, portal.php,
     * api/host/delete-checkin.php) had before this method centralized them.
     * @return array{ok: bool, error: ?string, contact_id: ?int}
     */
    public static function deleteCheckin(int $checkinId): array {
        $appDb = Database::getAppConnection();

        $before = self::fetchCheckin($appDb, $checkinId);
        if (!$before) {
            return ['ok' => false, 'error' => 'Check-in record not found.', 'contact_id' => null];
        }

        $appDb->prepare("DELETE FROM tgg_checkins WHERE id = :id")->execute(['id' => $checkinId]);

        AuditLog::log('checkins', 'checkin_deleted', [
            'checkin_id' => $checkinId,
            'before' => $before,
        ], (int)$before['contact_id']);

        return ['ok' => true, 'error' => null, 'contact_id' => (int)$before['contact_id']];
    }

    private static function fetchCheckin(\PDO $appDb, int $checkinId): ?array {
        $stmt = $appDb->prepare("SELECT id, contact_id, checked_in_at, notes, guest_name FROM tgg_checkins WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $checkinId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
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
