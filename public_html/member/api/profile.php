<?php
/**
 * Mobile API: the authenticated member's own profile -- contact info,
 * membership status, Membership Credits, attendance, and payment history,
 * plus the self-service actions profile.php exposes to a member about their
 * own account. Always "my own profile" (contact_id from the token); viewing
 * or managing other members is a host/admin-only web feature not in the
 * mobile app. No web-form CSRF token here -- the Bearer access token is
 * itself the anti-CSRF measure, same reasoning as checkins.php.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config/bootstrap.php';

use App\ApiAuth;
use App\Auth;
use App\AuditLog;
use App\Database;
use App\MailHelper;
use App\MembershipCredits;
use App\MembershipService;

$user = ApiAuth::requireAuth();
$contactId = (int)$user['contact_id'];
$appDb = Database::getAppConnection();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    handleGet($appDb, $contactId, $user);
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    handlePost($appDb, $contactId);
} else {
    json_response(['error' => 'Method not allowed'], 405);
}

function handleGet(PDO $appDb, int $contactId, array $user): void {
    $contactStmt = $appDb->prepare("SELECT display_name, email, phone, is_opt_out FROM tgg_contacts WHERE id = :id AND is_deleted = 0 LIMIT 1");
    $contactStmt->execute(['id' => $contactId]);
    $contact = $contactStmt->fetch();
    if (!$contact) {
        json_response(['error' => 'Member not found'], 404);
    }

    $settingsStmt = $appDb->prepare("SELECT custom_display_name, auto_apply_credits FROM tgg_member_settings WHERE contact_id = :id LIMIT 1");
    $settingsStmt->execute(['id' => $contactId]);
    $settings = $settingsStmt->fetch() ?: ['custom_display_name' => $contact['display_name'], 'auto_apply_credits' => 0];
    $editableDisplayName = trim((string)($settings['custom_display_name'] ?? '')) !== '' ? $settings['custom_display_name'] : $contact['display_name'];

    $billingStmt = $appDb->prepare("SELECT auto_renew, stripe_customer_id, stripe_payment_method_id FROM tgg_subscriptions WHERE contact_id = :id LIMIT 1");
    $billingStmt->execute(['id' => $contactId]);
    $billing = $billingStmt->fetch() ?: ['auto_renew' => 0, 'stripe_customer_id' => null, 'stripe_payment_method_id' => null];

    $pendingStmt = $appDb->prepare("SELECT new_email, expires_at FROM tgg_email_change_requests WHERE contact_id = :id AND expires_at > NOW() LIMIT 1");
    $pendingStmt->execute(['id' => $contactId]);
    $pendingEmailChange = $pendingStmt->fetch() ?: null;

    // Home only shows a short teaser of each -- the full, pageable history
    // lives behind its own screen backed by attendance-history.php /
    // payment-history.php.
    $attendanceStmt = $appDb->prepare("
        SELECT checked_in_at, notes, guest_name
        FROM tgg_checkins
        WHERE contact_id = :contact_id
        ORDER BY checked_in_at DESC
        LIMIT 3
    ");
    $attendanceStmt->execute(['contact_id' => $contactId]);

    $ledgerStmt = $appDb->prepare("
        SELECT l.created_at, l.amount, l.currency, l.action_type, l.payment_status, p.name AS plan_name
        FROM tgg_billing_ledger l
        INNER JOIN tgg_subscription_plans p ON l.plan_id = p.id
        WHERE l.contact_id = :contact_id
        ORDER BY l.created_at DESC
        LIMIT 3
    ");
    $ledgerStmt->execute(['contact_id' => $contactId]);

    json_response([
        'contact' => [
            'contact_id' => $contactId,
            'display_name' => $editableDisplayName,
            'email' => $contact['email'],
            'phone' => $contact['phone'],
            'is_opt_out' => (bool)$contact['is_opt_out'],
        ],
        'roles' => $user['roles'],
        'permissions' => $user['permissions'],
        'membership' => MembershipService::getMemberMembershipDetails($contactId),
        'auto_renew' => (bool)$billing['auto_renew'],
        'can_enable_auto_renew' => !empty($billing['stripe_customer_id']) && !empty($billing['stripe_payment_method_id']),
        'auto_apply_credits' => (bool)$settings['auto_apply_credits'],
        'pending_email_change' => $pendingEmailChange,
        'credits' => MembershipCredits::getCreditSummary($contactId),
        // Home only shows a short teaser -- the full, pageable list lives
        // behind its own screen backed by credits-history.php.
        'credit_grants' => array_slice(MembershipCredits::getTransactionHistory($contactId), 0, 3),
        'recent_attendance' => $attendanceStmt->fetchAll(),
        'payment_history' => $ledgerStmt->fetchAll(),
    ]);
}

function handlePost(PDO $appDb, int $contactId): void {
    $body = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
    $action = $body['action'] ?? '';

    try {
        switch ($action) {
            case 'update_settings':
                updateSettings($appDb, $contactId, $body);
                break;
            case 'toggle_auto_renew':
                toggleAutoRenew($appDb, $contactId, (bool)($body['enabled'] ?? false));
                break;
            case 'toggle_auto_apply_credits':
                toggleAutoApplyCredits($appDb, $contactId, (bool)($body['enabled'] ?? false));
                break;
            case 'request_email_change':
                requestEmailChange($appDb, $contactId, $body);
                break;
            case 'cancel_email_change':
                $appDb->prepare("DELETE FROM tgg_email_change_requests WHERE contact_id = :id")->execute(['id' => $contactId]);
                json_response(['success' => true]);
                break;
            case 'trigger_password_reset':
                triggerPasswordReset($appDb, $contactId);
                break;
            default:
                json_response(['error' => 'Unknown action'], 400);
        }
    } catch (Exception $e) {
        json_response(['error' => safe_err('', $e)], 400);
    }
}

/** Mirrors profile.php's profile_settings_update: display name, phone, bulk-email opt-out. */
function updateSettings(PDO $appDb, int $contactId, array $body): void {
    $displayName = trim((string)($body['display_name'] ?? ''));
    if ($displayName === '') {
        json_response(['error' => 'Display name is required and cannot be left blank.'], 400);
    }

    $check = $appDb->prepare("SELECT contact_id FROM tgg_member_settings WHERE contact_id = :id");
    $check->execute(['id' => $contactId]);
    if ($check->fetch()) {
        $appDb->prepare("UPDATE tgg_member_settings SET custom_display_name = :name WHERE contact_id = :id")
            ->execute(['name' => $displayName, 'id' => $contactId]);
    } else {
        $randomToken = bin2hex(random_bytes(32));
        $appDb->prepare("INSERT INTO tgg_member_settings (contact_id, password_hash, role, custom_display_name) VALUES (:id, :hash, 'member', :name)")
            ->execute(['id' => $contactId, 'hash' => password_hash($randomToken, PASSWORD_DEFAULT), 'name' => $displayName]);
    }

    $isOptOut = !empty($body['is_opt_out']) ? 1 : 0;
    $appDb->prepare("UPDATE tgg_contacts SET is_opt_out = :opt WHERE id = :id")->execute(['opt' => $isOptOut, 'id' => $contactId]);

    $rawPhone = trim((string)($body['phone'] ?? ''));
    if ($rawPhone === '') {
        $appDb->prepare("UPDATE tgg_contacts SET phone = NULL WHERE id = :id")->execute(['id' => $contactId]);
    } else {
        $digits = normalize_phone($rawPhone);
        if (strlen($digits) !== 10) {
            json_response(['error' => 'Please enter a 10-digit US phone number.'], 400);
        }
        $appDb->prepare("UPDATE tgg_contacts SET phone = :phone WHERE id = :id")->execute(['phone' => $digits, 'id' => $contactId]);
    }

    json_response(['success' => true]);
}

function toggleAutoRenew(PDO $appDb, int $contactId, bool $enabled): void {
    if ($enabled) {
        $billingStmt = $appDb->prepare("SELECT stripe_customer_id, stripe_payment_method_id FROM tgg_subscriptions WHERE contact_id = :id LIMIT 1");
        $billingStmt->execute(['id' => $contactId]);
        $billing = $billingStmt->fetch();
        if (!$billing || empty($billing['stripe_customer_id']) || empty($billing['stripe_payment_method_id'])) {
            json_response(['error' => 'No card on file to enable auto-renew.'], 400);
        }
    }
    $appDb->prepare("UPDATE tgg_subscriptions SET auto_renew = :v WHERE contact_id = :id")->execute(['v' => $enabled ? 1 : 0, 'id' => $contactId]);
    AuditLog::log('membership', 'auto_renew_toggled', ['enabled' => $enabled], $contactId);
    json_response(['success' => true]);
}

function toggleAutoApplyCredits(PDO $appDb, int $contactId, bool $enabled): void {
    $appDb->prepare("UPDATE tgg_member_settings SET auto_apply_credits = :v WHERE contact_id = :id")->execute(['v' => $enabled ? 1 : 0, 'id' => $contactId]);
    AuditLog::log('membership', 'auto_apply_credits_toggled', ['enabled' => $enabled], $contactId);
    json_response(['success' => true]);
}

/** Mirrors profile.php's request_email_change: re-authenticates with current password, emails a verification link. */
function requestEmailChange(PDO $appDb, int $contactId, array $body): void {
    $newEmail = trim(strtolower((string)($body['new_email'] ?? '')));
    if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL) || strlen($newEmail) > 254) {
        json_response(['error' => 'Please enter a valid email address.'], 400);
    }

    $contactStmt = $appDb->prepare("SELECT email FROM tgg_contacts WHERE id = :id AND is_deleted = 0 LIMIT 1");
    $contactStmt->execute(['id' => $contactId]);
    $oldEmail = trim(strtolower($contactStmt->fetchColumn() ?: ''));
    if ($newEmail === $oldEmail) {
        json_response(['error' => 'That is already your current email address.'], 400);
    }

    $pwStmt = $appDb->prepare("SELECT password_hash FROM tgg_member_settings WHERE contact_id = :id LIMIT 1");
    $pwStmt->execute(['id' => $contactId]);
    $pwRow = $pwStmt->fetch();
    if (!$pwRow || !password_verify((string)($body['current_password'] ?? ''), $pwRow['password_hash'])) {
        json_response(['error' => 'Current password is incorrect.'], 401);
    }

    $dupStmt = $appDb->prepare("SELECT id FROM tgg_contacts WHERE email = :email AND is_deleted = 0 AND id != :id LIMIT 1");
    $dupStmt->execute(['email' => $newEmail, 'id' => $contactId]);
    if ($dupStmt->fetch()) {
        json_response(['error' => 'That email address is already in use by another member.'], 409);
    }

    $rawToken = bin2hex(random_bytes(32));
    $rawCancelToken = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));

    $appDb->prepare("
        INSERT INTO tgg_email_change_requests (contact_id, new_email, old_email, token, cancel_token, expires_at)
        VALUES (:contact_id, :new_email, :old_email, :token, :cancel_token, :expires_at)
        ON DUPLICATE KEY UPDATE new_email = :new_email2, old_email = :old_email2, token = :token2, cancel_token = :cancel_token2, expires_at = :expires_at2
    ")->execute([
        'contact_id' => $contactId,
        'new_email' => $newEmail,
        'old_email' => $oldEmail,
        'token' => hash('sha256', $rawToken),
        'cancel_token' => hash('sha256', $rawCancelToken),
        'expires_at' => $expiresAt,
        'new_email2' => $newEmail,
        'old_email2' => $oldEmail,
        'token2' => hash('sha256', $rawToken),
        'cancel_token2' => hash('sha256', $rawCancelToken),
        'expires_at2' => $expiresAt,
    ]);

    $baseUrl = rtrim($_ENV['BASE_URL'] ?? 'http://localhost/member', '/');
    $displayName = MembershipService::getFormattedName($contactId);

    MailHelper::sendTemplate($newEmail, 'email_change_verification', [
        'display_name' => $displayName,
        'old_email' => $oldEmail,
        'new_email' => $newEmail,
        'verify_link' => $baseUrl . '/verify-email-change.php?token=' . $rawToken,
        'expires_in' => '1 hour',
    ], $contactId, $contactId);

    try {
        MailHelper::sendTemplate($oldEmail, 'email_change_requested', [
            'display_name' => $displayName,
            'new_email' => $newEmail,
            'expires_in' => '1 hour',
            'cancel_link' => $baseUrl . '/cancel-email-change.php?token=' . $rawCancelToken,
            'reset_link' => $baseUrl . '/forgot-password.php',
        ], $contactId, $contactId);
    } catch (Exception $alarmEx) {
        error_log("Failed to send email-change alarm to old address for contact {$contactId}: " . $alarmEx->getMessage());
    }

    AuditLog::log('security', 'email_change_requested', ['old_email' => $oldEmail, 'new_email' => $newEmail], $contactId);

    json_response(['success' => true, 'message' => "Verification link sent to {$newEmail}. Your email will not change until you click it (expires in 1 hour)."]);
}

/** Mirrors profile.php's trigger_password_reset: same email+code flow as forgot-password.php, not an in-place change. */
function triggerPasswordReset(PDO $appDb, int $contactId): void {
    $contactStmt = $appDb->prepare("SELECT email FROM tgg_contacts WHERE id = :id AND is_deleted = 0 LIMIT 1");
    $contactStmt->execute(['id' => $contactId]);
    $email = trim(strtolower($contactStmt->fetchColumn() ?: ''));
    if ($email === '') {
        json_response(['error' => 'This account does not have a registered email address.'], 400);
    }

    $reset = Auth::createPasswordSetupToken($email, '+1 hour');
    $resetLink = rtrim($_ENV['BASE_URL'] ?? 'http://localhost/member', '/') . '/reset-password.php?token=' . $reset['token'];
    $displayName = MembershipService::getFormattedName($contactId);

    MailHelper::sendTemplate($email, 'password_reset_link', [
        'display_name' => $displayName,
        'reset_link' => $resetLink,
        'reset_code' => $reset['code'],
        'expires_in' => '1 hour',
    ], $contactId, $contactId);

    AuditLog::log('security', 'password_reset_requested', ['email' => $email, 'via' => 'mobile'], $contactId);

    json_response(['success' => true, 'message' => "A password reset email with a code was sent to {$email}."]);
}
