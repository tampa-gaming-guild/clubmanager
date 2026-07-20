<?php
/**
 * Member Profile Page
 * Handles public profile display and private details (email, phone, credentials, privacy preferences) for owners/admins.
 */
require_once dirname(dirname(__DIR__)) . '/config/bootstrap.php';

use App\Database;
use App\EventSlot;
use App\MembershipCredits;
use App\MembershipService;
use App\Auth;
use App\AuditLog;
use App\MailHelper;
use App\BillingHelper;

$errorMsg = null;
$successMsg = null;

// PRG: read flash messages passed back via GET after a redirect
if (isset($_GET['success'])) $successMsg = trim($_GET['success']);
if (isset($_GET['error']))   $errorMsg   = trim($_GET['error']);

// 0. Auth Gate: this is a member-only page, never public.
Auth::requireAuth();

// 1. Get Target Profile ID
$profileId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($profileId <= 0 && Auth::check()) {
    $profileId = $_SESSION['user']['contact_id']; // Default to logged-in user
}

if ($profileId <= 0) {
    redirect('index.php');
}

$civiDb = null;
$appDb = null;
$contact = null;
$membership = null;
$settings = null;

try {
    $appDb = Database::getAppConnection();

    // A. Fetch Contact Info from local contacts
    $contactStmt = $appDb->prepare("
        SELECT id, display_name, first_name, last_name, email, phone, is_opt_out
        FROM tgg_contacts
        WHERE id = :id AND is_deleted = 0 LIMIT 1
    ");
    $contactStmt->execute(['id' => $profileId]);
    $contact = $contactStmt->fetch();

        if ($contact) {
        // B. Fetch Membership Details
        $membership = MembershipService::getMemberMembershipDetails($profileId);

        // B1. Fetch auto-renew / saved-card status
        $subBillingStmt = $appDb->prepare("SELECT auto_renew, stripe_customer_id, stripe_payment_method_id FROM tgg_subscriptions WHERE contact_id = :id LIMIT 1");
        $subBillingStmt->execute(['id' => $profileId]);
        $subBilling = $subBillingStmt->fetch() ?: ['auto_renew' => 0, 'stripe_customer_id' => null, 'stripe_payment_method_id' => null];

        // C. Fetch Local Settings / Privacy Info
        $settingsStmt = $appDb->prepare("SELECT role, custom_display_name, is_founder, auto_apply_credits FROM tgg_member_settings WHERE contact_id = :id LIMIT 1");
        $settingsStmt->execute(['id' => $profileId]);
        $settings = $settingsStmt->fetch();

        // D. Fetch all roles list
        $rolesList = $appDb->query("SELECT * FROM `tgg_roles` ORDER BY sort_order ASC, id ASC")->fetchAll();
    }
} catch (Exception $e) {
    $errorMsg = safe_err("Database Connection Error: ", $e);
}

if (!$contact) {
    die("Member Profile not found.");
}

// Default settings if they don't exist yet locally
if (!$settings) {
    $settings = [
        'role' => 'member',
        'custom_display_name' => $contact['display_name'],
        'is_founder' => 0,
        'auto_apply_credits' => 0
    ];
}

// Fetch all roles assigned to this member
$memberRoles = [];
if ($contact) {
    try {
        $rolesStmt = $appDb->prepare("SELECT role_name FROM tgg_member_roles WHERE contact_id = :id");
        $rolesStmt->execute(['id' => $profileId]);
        $memberRoles = $rolesStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Exception $e) {
        // Fallback or ignore
    }
}
if (empty($memberRoles) && isset($settings['role'])) {
    $memberRoles = [$settings['role']];
}

// 2. Check Viewer Relationship
$isOwner = Auth::check() && $_SESSION['user']['contact_id'] === $profileId;
$isAdmin = has_permission('admin panel');
$hasPrivateAccess = $isOwner || $isAdmin;
$canViewBilling = $isOwner || has_permission('process payments');
$canManageContact = $isOwner || $isAdmin || has_permission('process payments');

// Fetch any pending (unexpired) email change request for display
$pendingEmailChange = null;
if ($canManageContact && $appDb) {
    try {
        $pendingStmt = $appDb->prepare("SELECT new_email, expires_at FROM tgg_email_change_requests WHERE contact_id = :id AND expires_at > NOW() LIMIT 1");
        $pendingStmt->execute(['id' => $profileId]);
        $pendingEmailChange = $pendingStmt->fetch() ?: null;
    } catch (Exception $e) {
        $errorMsg = safe_err(($errorMsg ? $errorMsg . " | " : "") . "Failed to load pending email change: ", $e);
    }
}

// 3. Access Gate: only the owner, admin-panel staff, or payment-processing staff
// may view this profile at all.
if (!$canManageContact) {
    $profileHidden = true;
} else {
    $profileHidden = false;
}

// Actor attribution on history tables is admin-panel-only information.
$showActorCol = has_permission('admin panel');

// Fetch Billing Transactions if Viewer has Private Access
$transactions = [];
if ($canViewBilling && $appDb) {
    try {
        $transStmt = $appDb->prepare("
            SELECT l.created_at, l.amount, l.currency, l.action_type, l.payment_status, l.payment_intent_id as trxn_id, p.name as plan_name,
                   l.created_by, l.impersonator_id, l.source,
                   cb.display_name AS created_by_name, ci.display_name AS impersonator_name
            FROM tgg_billing_ledger l
            INNER JOIN tgg_subscription_plans p ON l.plan_id = p.id
            LEFT JOIN tgg_contacts cb ON cb.id = l.created_by
            LEFT JOIN tgg_contacts ci ON ci.id = l.impersonator_id
            WHERE l.contact_id = :contact_id
            ORDER BY l.created_at DESC
        ");
        $transStmt->execute(['contact_id' => $profileId]);
        $transactions = $transStmt->fetchAll();
    } catch (Exception $e) {
        $errorMsg = safe_err(($errorMsg ? $errorMsg . " | " : "") . "Failed to load billing history: ", $e);
    }
}

// Fetch Membership Credits details if Viewer has Private Access
$volunteerShifts = [];
$creditGrants = [];
$totalEarned = 0;
$totalApplied = 0;
$totalExpired = 0;
$availableCredits = 0;
$pendingCredits = 0;
$nextExpirationDate = 'Never';
$nextExpirationCredits = 0;
$redeemableMonths = 0;

if ($hasPrivateAccess && $appDb) {
    try {
        $configMap = MembershipCredits::getConfigMap();

        // 1. Past shifts still awaiting Hosting Manager attendance confirmation
        // (confirming is what grants the credit -- see admin/volunteer_credits.php)
        $stmtShifts = $appDb->prepare("
            SELECT sl.slot_label AS role, sl.slot_type, e.title as event_title, e.start_time, t.id as processed_id,
                   t.created_by, t.impersonator_id, t.source,
                   cb.display_name AS created_by_name, ci.display_name AS impersonator_name
            FROM tgg_volunteer_signups s
            INNER JOIN tgg_event_slots sl ON sl.id = s.slot_id
            INNER JOIN tgg_events e ON sl.event_id = e.id
            LEFT JOIN tgg_volunteer_credit_transactions t ON t.slot_id = s.slot_id AND t.contact_id = s.contact_id
            LEFT JOIN tgg_contacts cb ON cb.id = t.created_by
            LEFT JOIN tgg_contacts ci ON ci.id = t.impersonator_id
            WHERE s.contact_id = :contact_id AND e.start_time < :now
            ORDER BY e.start_time DESC
        ");
        $stmtShifts->execute([
            'contact_id' => $profileId,
            'now' => date('Y-m-d H:i:s')
        ]);
        $completedShifts = $stmtShifts->fetchAll();

        foreach ($completedShifts as $shift) {
            $key = EventSlot::creditKey($shift['slot_type'], $shift['start_time']);
            $creditsVal = (int)round((float)($configMap[$key] ?? 0));

            if (!$shift['processed_id']) {
                $pendingCredits += $creditsVal;
            }

            $shiftNames = [];
            if ($shift['created_by'] !== null) {
                $shiftNames[(int)$shift['created_by']] = $shift['created_by_name'] ?? "Member #{$shift['created_by']}";
            }
            if ($shift['impersonator_id'] !== null) {
                $shiftNames[(int)$shift['impersonator_id']] = $shift['impersonator_name'] ?? "Member #{$shift['impersonator_id']}";
            }

            $volunteerShifts[] = [
                'date' => date('Y-m-d', strtotime($shift['start_time'])),
                'event_title' => $shift['event_title'],
                'shift' => $shift['role'],
                'credits' => $creditsVal,
                'status' => $shift['processed_id'] ? 'Confirmed' : 'Awaiting Confirmation',
                'processed_by' => $shift['processed_id']
                    ? AuditLog::describeActor(
                        $shift['created_by'] !== null ? (int)$shift['created_by'] : null,
                        $shift['impersonator_id'] !== null ? (int)$shift['impersonator_id'] : null,
                        $shift['source'],
                        $shiftNames
                    )
                    : '—'
            ];
        }

        // 2. Membership Credits bank: aggregate summary + itemized grant history
        // (shared FIFO math lives in MembershipCredits so this can never drift
        // from what renewal-time spending actually sees)
        $summary = MembershipCredits::getCreditSummary($profileId);
        $totalEarned = $summary['earned'];
        $totalApplied = $summary['applied'];
        $totalExpired = $summary['expired'];
        $availableCredits = $summary['available'];
        $nextExpirationDate = $summary['next_expiration_date'] ?? 'Never';
        $nextExpirationCredits = $summary['next_expiration_credits'];
        $redeemableMonths = MembershipCredits::getRedeemableMonths($profileId);
        $creditGrants = MembershipCredits::getTransactionHistory($profileId);
    } catch (Exception $e) {
        $errorMsg = safe_err(($errorMsg ? $errorMsg . " | " : "") . "Failed to load Membership Credits: ", $e);
    }
}

// Fetch Attendance Records if Viewer has Private Access
$attendanceRecords = [];
if ($hasPrivateAccess && $appDb) {
    try {
        $attStmt = $appDb->prepare("
            SELECT id AS checkin_id, checked_in_at, notes, guest_name
            FROM tgg_checkins
            WHERE contact_id = :contact_id
            ORDER BY checked_in_at DESC
        ");
        $attStmt->execute(['contact_id' => $profileId]);
        $attendanceRecords = $attStmt->fetchAll();
    } catch (Exception $e) {
        $errorMsg = safe_err(($errorMsg ? $errorMsg . " | " : "") . "Failed to load attendance records: ", $e);
    }
}

// 4. Handle Settings Updates (Owner, admin, or contact-managing staff)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($hasPrivateAccess || $canManageContact)) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errorMsg = "Invalid security token. Please try again.";
    } else {
        // A. Handle Profile Settings Update (display name, email opt-out, phone --
        // owner, admin, or process-payments staff)
        if (isset($_POST['profile_settings_update']) && $canManageContact) {
            $anySuccess = false;

            // A1. Display name (required)
            $customDisplayName = trim($_POST['custom_display_name'] ?? '');
            try {
                if (empty($customDisplayName)) {
                    throw new Exception("Display name is required and cannot be left blank.");
                }

                // Ensure row exists
                $check = $appDb->prepare("SELECT contact_id FROM tgg_member_settings WHERE contact_id = :id");
                $check->execute(['id' => $profileId]);

                if ($check->fetch()) {
                    $update = $appDb->prepare("UPDATE tgg_member_settings SET custom_display_name = :custom_name WHERE contact_id = :id");
                    $update->execute(['custom_name' => $customDisplayName, 'id' => $profileId]);
                } else {
                    $randomToken = bin2hex(random_bytes(32));
                    $insert = $appDb->prepare("INSERT INTO tgg_member_settings (contact_id, password_hash, role, custom_display_name) VALUES (:id, :hash, 'member', :custom_name)");
                    $insert->execute([
                        'id' => $profileId,
                        'hash' => password_hash($randomToken, PASSWORD_DEFAULT),
                        'custom_name' => $customDisplayName
                    ]);
                }

                $settings['custom_display_name'] = $customDisplayName;
                if ($isOwner) {
                    $_SESSION['user']['display_name'] = $customDisplayName;
                }
                $anySuccess = true;
            } catch (Exception $e) {
                $errorMsg = safe_err(($errorMsg ? $errorMsg . " | " : "") . "Failed to save display name: ", $e);
            }

            // A2. Bulk email opt-out
            $newOptOut = isset($_POST['is_opt_out']) ? 1 : 0;
            try {
                $update = $appDb->prepare("UPDATE tgg_contacts SET is_opt_out = :is_opt_out WHERE id = :id");
                $update->execute(['is_opt_out' => $newOptOut, 'id' => $profileId]);
                $contact['is_opt_out'] = $newOptOut;
                $anySuccess = true;
            } catch (Exception $e) {
                $errorMsg = safe_err(($errorMsg ? $errorMsg . " | " : "") . "Failed to update email preference: ", $e);
            }

            // A3. Phone (blank clears)
            $rawPhone = trim($_POST['phone'] ?? '');
            try {
                if ($rawPhone === '') {
                    $update = $appDb->prepare("UPDATE tgg_contacts SET phone = NULL WHERE id = :id");
                    $update->execute(['id' => $profileId]);
                    $contact['phone'] = null;
                } else {
                    $digits = normalize_phone($rawPhone);
                    if (strlen($digits) !== 10) {
                        throw new Exception("Please enter a 10-digit US phone number.");
                    }
                    $update = $appDb->prepare("UPDATE tgg_contacts SET phone = :phone WHERE id = :id");
                    $update->execute(['phone' => $digits, 'id' => $profileId]);
                    $contact['phone'] = $digits;
                }
                $anySuccess = true;
            } catch (Exception $e) {
                $errorMsg = safe_err(($errorMsg ? $errorMsg . " | " : "") . "Failed to update phone number: ", $e);
            }

            if ($anySuccess && !$errorMsg) {
                $successMsg = "Profile settings saved successfully.";
            }
        }

        // A0. Handle Auto-Renew Toggle
        if (isset($_POST['auto_renew_update']) && $hasPrivateAccess) {
            $newAutoRenew = isset($_POST['auto_renew']) ? 1 : 0;
            try {
                if (empty($subBilling['stripe_customer_id']) || empty($subBilling['stripe_payment_method_id'])) {
                    throw new Exception("No card on file to enable auto-renew.");
                }
                $update = $appDb->prepare("UPDATE tgg_subscriptions SET auto_renew = :auto_renew WHERE contact_id = :id");
                $update->execute(['auto_renew' => $newAutoRenew, 'id' => $profileId]);
                $subBilling['auto_renew'] = $newAutoRenew;
                AuditLog::log('membership', 'auto_renew_toggled', ['enabled' => (bool)$newAutoRenew], $profileId);
                $successMsg = $newAutoRenew ? "Auto-renew enabled." : "Auto-renew disabled.";
            } catch (Exception $e) {
                $errorMsg = safe_err("Failed to update auto-renew setting: ", $e);
            }
        }

        // A0c. Handle Auto-Apply Membership Credits Toggle
        if (isset($_POST['auto_apply_credits_update']) && $hasPrivateAccess) {
            $newAutoApply = isset($_POST['auto_apply_credits']) ? 1 : 0;
            try {
                $update = $appDb->prepare("UPDATE tgg_member_settings SET auto_apply_credits = :v WHERE contact_id = :id");
                $update->execute(['v' => $newAutoApply, 'id' => $profileId]);
                $settings['auto_apply_credits'] = $newAutoApply;
                AuditLog::log('membership', 'auto_apply_credits_toggled', ['enabled' => (bool)$newAutoApply], $profileId);
                $successMsg = $newAutoApply ? "Auto-apply Membership Credits enabled." : "Auto-apply Membership Credits disabled.";
            } catch (Exception $e) {
                $errorMsg = safe_err("Failed to update auto-apply Membership Credits setting: ", $e);
            }
        }

        // A0d. Handle Email Change Request (Owner only -- requires verification
        // from the new address before anything changes, since email is the login)
        if (isset($_POST['request_email_change']) && $isOwner) {
            $newEmail = trim(strtolower($_POST['new_email'] ?? ''));
            try {
                if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL) || strlen($newEmail) > 254) {
                    throw new Exception("Please enter a valid email address.");
                }
                if ($newEmail === trim(strtolower($contact['email'] ?? ''))) {
                    throw new Exception("That is already your current email address.");
                }

                // Re-authenticate: changing the login identifier must not be
                // possible from an unattended session alone
                $pwStmt = $appDb->prepare("SELECT password_hash FROM tgg_member_settings WHERE contact_id = :id LIMIT 1");
                $pwStmt->execute(['id' => $profileId]);
                $pwRow = $pwStmt->fetch();
                if (!$pwRow || !password_verify($_POST['current_password'] ?? '', $pwRow['password_hash'])) {
                    throw new Exception("Current password is incorrect.");
                }

                // No unique index on tgg_contacts.email, so enforce at app level
                // (checked again at verification time to close the race window)
                $dupStmt = $appDb->prepare("SELECT id FROM tgg_contacts WHERE email = :email AND is_deleted = 0 AND id != :id LIMIT 1");
                $dupStmt->execute(['email' => $newEmail, 'id' => $profileId]);
                if ($dupStmt->fetch()) {
                    throw new Exception("That email address is already in use by another member.");
                }

                $rawToken = bin2hex(random_bytes(32));
                $rawCancelToken = bin2hex(random_bytes(32));
                $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));
                $oldEmail = trim(strtolower($contact['email']));

                $stmtReq = $appDb->prepare("
                    INSERT INTO tgg_email_change_requests (contact_id, new_email, old_email, token, cancel_token, expires_at)
                    VALUES (:contact_id, :new_email, :old_email, :token, :cancel_token, :expires_at)
                    ON DUPLICATE KEY UPDATE new_email = :new_email2, old_email = :old_email2, token = :token2, cancel_token = :cancel_token2, expires_at = :expires_at2
                ");
                $stmtReq->execute([
                    'contact_id' => $profileId,
                    'new_email' => $newEmail,
                    'old_email' => $oldEmail,
                    'token' => hash('sha256', $rawToken),
                    'cancel_token' => hash('sha256', $rawCancelToken),
                    'expires_at' => $expiresAt,
                    'new_email2' => $newEmail,
                    'old_email2' => $oldEmail,
                    'token2' => hash('sha256', $rawToken),
                    'cancel_token2' => hash('sha256', $rawCancelToken),
                    'expires_at2' => $expiresAt
                ]);

                $baseUrl = rtrim($_ENV['BASE_URL'] ?? 'http://localhost/member', '/');
                $displayName = !empty(trim($settings['custom_display_name'] ?? '')) ? trim($settings['custom_display_name']) : $contact['display_name'];

                // Verification link to the NEW address -- a send failure here
                // must surface, the flow is dead without it
                MailHelper::sendTemplate($newEmail, 'email_change_verification', [
                    'display_name' => $displayName,
                    'old_email' => $oldEmail,
                    'new_email' => $newEmail,
                    'verify_link' => $baseUrl . '/verify-email-change.php?token=' . $rawToken,
                    'expires_in' => '1 hour'
                ], $profileId, $_SESSION['user']['contact_id']);

                // Takeover alarm to the OLD address -- best-effort
                try {
                    MailHelper::sendTemplate($oldEmail, 'email_change_requested', [
                        'display_name' => $displayName,
                        'new_email' => $newEmail,
                        'expires_in' => '1 hour',
                        'cancel_link' => $baseUrl . '/cancel-email-change.php?token=' . $rawCancelToken,
                        'reset_link' => $baseUrl . '/forgot-password.php'
                    ], $profileId, $_SESSION['user']['contact_id']);
                } catch (Exception $alarmEx) {
                    error_log("Failed to send email-change alarm to old address for contact {$profileId}: " . $alarmEx->getMessage());
                }

                AuditLog::log('security', 'email_change_requested', [
                    'old_email' => $oldEmail,
                    'new_email' => $newEmail
                ], $profileId);

                $successMsg = "Verification link sent to {$newEmail}. Your email will not change until you click it (expires in 1 hour).";
            } catch (Exception $e) {
                $errorMsg = safe_err("Failed to request email change: ", $e);
            }
        }

        // A0e. Handle Cancel Pending Email Change (Owner or contact-managing staff)
        if (isset($_POST['cancel_email_change']) && $canManageContact) {
            try {
                $del = $appDb->prepare("DELETE FROM tgg_email_change_requests WHERE contact_id = :id");
                $del->execute(['id' => $profileId]);
                $successMsg = "Pending email change cancelled.";
            } catch (Exception $e) {
                $errorMsg = safe_err("Failed to cancel email change: ", $e);
            }
        }

        // A0f. Handle Staff Direct Email Update (superadmin only, on someone
        // else's profile -- immediate, no verification; both the old and new
        // addresses are notified). Email is the login identifier, so changing
        // it for someone else is account takeover of that login -- deliberately
        // gated to the superadmin role rather than 'process payments'/'admin
        // panel' permissions.
        if (isset($_POST['staff_contact_update']) && has_role('superadmin') && !$isOwner) {
            $newEmail = trim(strtolower($_POST['new_email'] ?? ''));
            try {
                if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL) || strlen($newEmail) > 254) {
                    throw new Exception("Please enter a valid email address.");
                }

                $oldEmail = trim(strtolower($contact['email'] ?? ''));
                if ($newEmail === $oldEmail) {
                    throw new Exception("That is already this member's email address.");
                }

                $dupStmt = $appDb->prepare("SELECT id FROM tgg_contacts WHERE email = :email AND is_deleted = 0 AND id != :id LIMIT 1");
                $dupStmt->execute(['email' => $newEmail, 'id' => $profileId]);
                if ($dupStmt->fetch()) {
                    throw new Exception("That email address is already in use by another member.");
                }

                $update = $appDb->prepare("UPDATE tgg_contacts SET email = :email WHERE id = :id");
                $update->execute(['email' => $newEmail, 'id' => $profileId]);

                // A staff fix supersedes any in-flight member request AND any
                // outstanding revert token -- neither may later undo it
                $appDb->prepare("DELETE FROM tgg_email_change_requests WHERE contact_id = :id")->execute(['id' => $profileId]);
                $appDb->prepare("DELETE FROM tgg_email_change_reverts WHERE contact_id = :id")->execute(['id' => $profileId]);

                $contact['email'] = $newEmail;
                BillingHelper::syncStripeCustomerEmail($profileId, $newEmail);

                $displayName = !empty(trim($settings['custom_display_name'] ?? '')) ? trim($settings['custom_display_name']) : $contact['display_name'];
                try {
                    MailHelper::sendTemplate($oldEmail, 'email_change_staff_notice', [
                        'display_name' => $displayName,
                        'old_email' => $oldEmail,
                        'new_email' => $newEmail
                    ], $profileId, $_SESSION['user']['contact_id']);
                } catch (Exception $mailEx) {
                    error_log("Failed to send staff email-change notice to old address for contact {$profileId}: " . $mailEx->getMessage());
                }
                try {
                    MailHelper::sendTemplate($newEmail, 'email_change_admin_notice', [
                        'display_name' => $displayName,
                        'new_email' => $newEmail
                    ], $profileId, $_SESSION['user']['contact_id']);
                } catch (Exception $mailEx) {
                    error_log("Failed to send staff email-change notice to new address for contact {$profileId}: " . $mailEx->getMessage());
                }

                AuditLog::log('security', 'email_change_staff', [
                    'old_email' => $oldEmail,
                    'new_email' => $newEmail
                ], $profileId);

                $successMsg = "Email address updated. Both the old and new addresses have been notified.";
            } catch (Exception $e) {
                $errorMsg = safe_err("Failed to update email address: ", $e);
            }
        }

        // A1. Handle Role Updates (Only Admin/Superadmin)
        if (isset($_POST['role_update']) && $isAdmin) {
            $newRoles = $_POST['roles'] ?? [];
            if (!is_array($newRoles)) {
                $newRoles = [];
            }
            // Sanitize input roles
            $newRoles = array_map('trim', $newRoles);
            
            try {
                $viewerIsSuperadmin = has_role('superadmin');
                $targetHasSuperadmin = in_array('superadmin', $memberRoles, true);
                
                // 1. Standard admin cannot modify roles for a superadmin user
                if ($targetHasSuperadmin && !$viewerIsSuperadmin) {
                    throw new Exception("Standard admins cannot modify roles for a superadmin user.");
                }
                
                // 2. Only superadmins can assign or remove the superadmin role
                $newHasSuperadmin = in_array('superadmin', $newRoles, true);
                if ($newHasSuperadmin !== $targetHasSuperadmin && !$viewerIsSuperadmin) {
                    throw new Exception("Only superadmins can add or delete the superadmin role.");
                }

                // 2b. The system must always retain at least one superadmin
                if ($targetHasSuperadmin && !$newHasSuperadmin) {
                    if (Auth::countSuperadmins($appDb, $profileId) < 1) {
                        throw new Exception("Cannot remove the superadmin role: at least one superadmin must remain. Grant another user the superadmin role first.");
                    }
                }

                // 3. Verify all selected roles exist in tgg_roles
                if (!empty($newRoles)) {
                    $placeholders = implode(',', array_fill(0, count($newRoles), '?'));
                    $checkStmt = $appDb->prepare("SELECT COUNT(*) FROM `tgg_roles` WHERE name IN ($placeholders)");
                    $checkStmt->execute($newRoles);
                    $foundCount = (int)$checkStmt->fetchColumn();
                    if ($foundCount !== count(array_unique($newRoles))) {
                        throw new Exception("One or more selected roles do not exist.");
                    }
                } else {
                    // Users must have at least one role. Default to 'member'.
                    $newRoles = ['member'];
                }
                
                // 4. Update the database
                $rolesBefore = $memberRoles;
                $appDb->beginTransaction();

                // Delete existing roles
                $deleteStmt = $appDb->prepare("DELETE FROM tgg_member_roles WHERE contact_id = :id");
                $deleteStmt->execute(['id' => $profileId]);
                
                // Insert new roles
                $insertStmt = $appDb->prepare("INSERT INTO tgg_member_roles (contact_id, role_name) VALUES (:id, :role)");
                foreach ($newRoles as $roleName) {
                    $insertStmt->execute(['id' => $profileId, 'role' => $roleName]);
                }
                
                // Update legacy column in tgg_member_settings
                $primaryRole = $newRoles[0] ?? 'member';
                $updateSettingsStmt = $appDb->prepare("UPDATE tgg_member_settings SET role = :role WHERE contact_id = :id");
                $updateSettingsStmt->execute(['role' => $primaryRole, 'id' => $profileId]);
                
                $appDb->commit();

                AuditLog::log('roles', 'member_roles_updated', [
                    'before' => array_values($rolesBefore),
                    'after' => array_values($newRoles)
                ], $profileId);

                $successMsg = "Member roles updated successfully.";
                $memberRoles = $newRoles;
                $settings['role'] = $primaryRole;
                
                // Refresh session if self
                if ($profileId === (int)$_SESSION['user']['contact_id']) {
                    Auth::refreshPermissions();
                }
            } catch (Exception $e) {
                if ($appDb->inTransaction()) {
                    $appDb->rollBack();
                }
                $errorMsg = safe_err("Failed to update role: ", $e);
            }
        }

        // A2. Start Impersonating User (Only Superadmin)
        if (isset($_POST['impersonate_user']) && $isAdmin) {
            try {
                Auth::impersonate($profileId);
                redirect('index.php');
            } catch (Exception $e) {
                $errorMsg = safe_err("Impersonation failed: ", $e);
            }
        }

        // A2b. Handle Subscription Rate Adjustment (Only Admin/Superadmin)
        if (isset($_POST['update_member_rate']) && $isAdmin) {
            $rateId = (int)($_POST['rate_id'] ?? 0);
            try {
                if (!$membership) {
                    throw new Exception("Member must have a subscription to adjust their rate.");
                }
                
                // Verify the rate is active and belongs to the member's plan
                $rateQuery = $appDb->prepare("
                    SELECT id FROM tgg_subscription_rates 
                    WHERE id = :id AND plan_id = :plan_id 
                      AND inactive = 0 
                      AND (expiration_date IS NULL OR expiration_date >= CURRENT_DATE()) 
                    LIMIT 1
                ");
                $rateQuery->execute(['id' => $rateId, 'plan_id' => $membership['membership_id']]);
                $rateExists = $rateQuery->fetchColumn();
                
                if (!$rateExists) {
                    throw new Exception("Invalid, inactive, or expired rate selected.");
                }

                $oldRateStmt = $appDb->prepare("SELECT rate_id FROM tgg_subscriptions WHERE contact_id = :contact_id LIMIT 1");
                $oldRateStmt->execute(['contact_id' => $profileId]);
                $oldRateId = $oldRateStmt->fetchColumn();

                $updateRateStmt = $appDb->prepare("UPDATE tgg_subscriptions SET rate_id = :rate_id WHERE contact_id = :contact_id");
                $updateRateStmt->execute(['rate_id' => $rateId, 'contact_id' => $profileId]);

                AuditLog::log('rates', 'member_rate_overridden', [
                    'old_rate_id' => $oldRateId !== false ? (int)$oldRateId : null,
                    'new_rate_id' => $rateId
                ], $profileId);

                $successMsg = "Subscription rate adjusted successfully.";
                // Refresh membership info
                $membership = MembershipService::getMemberMembershipDetails($profileId);
            } catch (Exception $e) {
                $errorMsg = safe_err("Failed to update rate: ", $e);
            }
        }

        // B. Handle Trigger Password Reset (Only owner or user with password resets permission)
        if (isset($_POST['trigger_password_reset'])) {
            try {
                if (!$isOwner && !has_permission('password resets')) {
                    throw new Exception("You do not have permission to trigger password resets for other members.");
                }
                $email = trim(strtolower($contact['email'] ?? ''));
                if (empty($email)) {
                    throw new Exception("This member does not have a registered email address.");
                }

                // 1. Generate secure token + 6-digit code and save to password resets table
                $reset = Auth::createPasswordSetupToken($email, '+1 hour');

                // 2. Send Email using Template
                $resetLink = rtrim($_ENV['BASE_URL'] ?? 'http://localhost/member', '/') . '/reset-password.php?token=' . $reset['token'];
                $displayName = !empty(trim($settings['custom_display_name'] ?? '')) ? trim($settings['custom_display_name']) : $contact['display_name'];
                $placeholders = [
                    'display_name' => $displayName,
                    'reset_link' => $resetLink,
                    'reset_code' => $reset['code'],
                    'expires_in' => '1 hour'
                ];

                MailHelper::sendTemplate($email, 'password_reset_link', $placeholders, $profileId, $_SESSION['user']['contact_id'] ?? null);

                AuditLog::log('security', 'password_reset_requested', [
                    'email' => $email,
                    'via' => $isOwner ? 'self' : 'profile'
                ], $profileId);

                // Redirect to code entry page
                redirect('enter-code.php?sent=1');

            } catch (Exception $e) {
                $errorMsg = safe_err("Failed to send password reset: ", $e);
            }
        }
    }

    // PRG: redirect so a page refresh doesn't resubmit the form
    $qp = ['id' => $profileId];
    if ($successMsg) $qp['success'] = $successMsg;
    if ($errorMsg)   $qp['error']   = $errorMsg;
    redirect('profile.php?' . http_build_query($qp));
}

// Resolve the custom display name to show
$displayNameToPublic = !empty(trim($settings['custom_display_name'] ?? '')) ? trim($settings['custom_display_name']) : $contact['display_name'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($displayNameToPublic); ?> - Member Profile</title>
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <link rel="icon" type="image/png" href="favicon.png">
    <link rel="apple-touch-icon" href="favicon.png">
    <link rel="manifest" href="manifest.json">
    <link rel="stylesheet" href="assets/css/style.css<?php echo asset_version('assets/css/style.css'); ?>">
    <style>
    /* Tabs Navigation */
    .profile-tabs {
        display: flex;
        gap: 10px;
        margin-bottom: 25px;
        border-bottom: 1px solid var(--border-glass);
        padding-bottom: 10px;
    }
    .tab-button {
        background: transparent;
        border: none;
        color: var(--color-text-secondary);
        font-size: 0.95rem;
        font-weight: 600;
        padding: 10px 20px;
        cursor: pointer;
        border-radius: 6px;
        transition: all 0.2s ease;
        outline: none;
    }
    .tab-button:hover {
        color: #fff;
        background: rgba(255, 255, 255, 0.05);
    }
    .tab-button.active {
        color: #fff;
        background: rgba(34, 197, 94, 0.15); /* success tint */
        border: 1px solid rgba(34, 197, 94, 0.3);
    }

    /* Tab Contents */
    .tab-content {
        display: none;
    }
    .tab-content.active {
        display: block;
    }

    /* Expanded UI layouts */
    .full-width-section {
        width: 100% !important;
    }
    </style>
</head>
<body>
    <div class="app-container">
        <?php $navActive = $isOwner ? 'dashboard' : ''; $navGuestCheckin = false; include __DIR__ . '/partials/navbar.php'; ?>

        <main class="main-content centered-content">
            <?php if ($errorMsg): ?>
                <div class="alert alert-danger" style="max-width: 650px; margin: 10px auto;"><?php echo e($errorMsg); ?></div>
            <?php endif; ?>

            <?php if ($successMsg): ?>
                <div class="alert alert-success" style="max-width: 650px; margin: 10px auto;"><?php echo e($successMsg); ?></div>
            <?php endif; ?>

            <?php if ($profileHidden): ?>
                <div class="auth-panel glass-panel">
                    <h2>Access Denied</h2>
                    <p class="description-text">You don't have permission to view this profile.</p>
                    <a href="index.php" class="btn btn-primary mt-10">Back to Dashboard</a>
                </div>
            <?php else: ?>
                <div class="profile-container glass-panel">
                    <div class="profile-header-section">
                        <div class="avatar-placeholder">
                            <?php 
                            $initials = strtoupper(substr($contact['first_name'] ?? 'M', 0, 1) . substr($contact['last_name'] ?? '', 0, 1));
                            echo e($initials);
                            ?>
                        </div>
                        <div class="profile-title">
                            <h2><?php echo e($displayNameToPublic); ?> <span style="font-size: 1.1rem; color: var(--color-text-secondary); font-weight: normal; margin-left: 8px;">(Member ID: <?php echo $profileId; ?>)</span></h2>
                            <span class="badge badge-role"><?php
                                $roleHierarchy = ['superadmin', 'admin', 'host', 'member', 'guest'];
                                usort($memberRoles, function($a, $b) use ($roleHierarchy) {
                                    $posA = array_search($a, $roleHierarchy, true);
                                    $posB = array_search($b, $roleHierarchy, true);
                                    $posA = ($posA === false) ? 999 : $posA;
                                    $posB = ($posB === false) ? 999 : $posB;
                                    return $posA <=> $posB;
                                });
                                $capitalizedRoles = array_map(function($r) { return ucfirst($r); }, $memberRoles);
                                echo e(implode(' | ', $capitalizedRoles));
                            ?></span>
                            <?php if (!empty($settings['is_founder'])): ?>
                            <span class="badge badge-founder">Founder</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($hasPrivateAccess || $canViewBilling): ?>
                        <div class="profile-tabs">
                            <?php if ($hasPrivateAccess): ?>
                                <button class="tab-button active" onclick="switchTab('profile')">Profile</button>
                                <button class="tab-button" onclick="switchTab('volunteering')">Membership Credits</button>
                                <button class="tab-button" onclick="switchTab('attendance')">Attendance</button>
                            <?php endif; ?>
                            <?php if ($canViewBilling): ?>
                                <button class="tab-button <?php echo !$hasPrivateAccess ? 'active' : ''; ?>" onclick="switchTab('billing')">Payment History</button>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($hasPrivateAccess): ?>
                    <div id="tab-profile" class="tab-content active">
                    <?php endif; ?>
                    <div class="profile-body-grid">
                        <!-- Left Panel: Profile Details -->
                        <div class="profile-details-column">
                            
                            <!-- DETAILS SECTION -->
                            <div class="detail-section">
                                <h3 class="section-title">Details</h3>
                                <table class="profile-data-table">
                                    <tr>
                                        <td><strong>Name:</strong></td>
                                        <td><?php echo e($displayNameToPublic); ?></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Email:</strong></td>
                                        <td>
                                            <a href="mailto:<?php echo e($contact['email']); ?>"><?php echo e($contact['email']); ?></a>
                                            <?php if ($pendingEmailChange): ?>
                                                <div style="font-size: 0.8rem; color: var(--color-text-muted); margin-top: 4px;">
                                                    Pending change to <strong><?php echo e($pendingEmailChange['new_email']); ?></strong> &mdash; confirm from that inbox (expires <?php echo date('g:i A, M j', strtotime($pendingEmailChange['expires_at'])); ?>)
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td><strong>Phone:</strong></td>
                                        <td><?php echo e($contact['phone'] ? format_phone($contact['phone']) : 'None registered'); ?></td>
                                    </tr>

                                    <?php if ($membership): ?>
                                        <tr>
                                            <td><strong>Membership Level:</strong></td>
                                            <td style="font-size: 0.85rem;">
                                                <?php
                                                echo e($membership['membership_name']);
                                                $showRate = $isOwner || has_permission('admin panel');
                                                if ($showRate && isset($membership['minimum_fee'])) {
                                                    $formattedPrice = '$' . number_format($membership['minimum_fee'], 2);
                                                    $intervalText = '';
                                                    if (isset($membership['duration_unit'])) {
                                                        $unit = strtolower($membership['duration_unit']);
                                                        if ($unit === 'year') $unit = 'annual';
                                                        elseif ($unit === 'month') $unit = 'monthly';
                                                        elseif ($unit === 'day') $unit = 'daily';

                                                        $intervalText = ' / ' . $unit;
                                                    }
                                                    echo ' <span style="color: var(--color-text-muted); font-size: 0.95em;">' . e("({$formattedPrice}{$intervalText})") . '</span>';
                                                }
                                                ?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td><strong>Status:</strong></td>
                                            <td>
                                                <span class="badge badge-status <?php echo $membership['is_active'] ? 'badge-active' : 'badge-expired'; ?>">
                                                    <?php echo e($membership['status_label']); ?>
                                                </span>
                                                <?php if (!empty($subBilling['auto_renew'])): ?>
                                                <span class="badge badge-active" style="display: inline-flex; align-items: center; gap: 6px; margin-left: 6px;">
                                                    Auto-Renew
                                                    <form action="profile.php?id=<?php echo $profileId; ?>" method="POST" style="display: inline; margin: 0;" data-confirm="Turn off auto-renew for this membership? Future renewals will require manual payment.">
                                                        <input type="hidden" name="csrf_token" value="<?php echo e(get_csrf_token()); ?>">
                                                        <button type="submit" name="auto_renew_update" title="Remove auto-renew" style="background: none; border: none; color: inherit; cursor: pointer; padding: 0; font-weight: bold; line-height: 1; font-size: 1em;">&times;</button>
                                                    </form>
                                                </span>
                                                <?php elseif (!empty($subBilling['stripe_customer_id']) && !empty($subBilling['stripe_payment_method_id'])): ?>
                                                <span class="badge badge-status" style="display: inline-flex; align-items: center; margin-left: 6px; opacity: 0.85;">
                                                    <form action="profile.php?id=<?php echo $profileId; ?>" method="POST" style="display: inline; margin: 0;">
                                                        <input type="hidden" name="csrf_token" value="<?php echo e(get_csrf_token()); ?>">
                                                        <input type="hidden" name="auto_renew" value="1">
                                                        <button type="submit" name="auto_renew_update" title="Enable auto-renew" style="background: none; border: none; color: inherit; cursor: pointer; padding: 0; font-size: 0.85em;">Enable Auto-Renew</button>
                                                    </form>
                                                </span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td><strong>Join Date:</strong></td>
                                            <td><?php echo date('F j, Y', strtotime($membership['join_date'])); ?></td>
                                        </tr>
                                        <tr>
                                            <td><strong>Expiration Date:</strong></td>
                                            <td><?php echo date('F j, Y', strtotime($membership['end_date'])); ?></td>
                                        </tr>
                                        <tr>
                                            <td></td>
                                            <td>
                                                <a href="renew.php?contact_id=<?php echo $profileId; ?>" class="btn btn-success btn-small" style="margin-top: 5px; display: inline-block;">Renew/Extend Membership</a>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <tr>
                                            <td><strong>Membership:</strong></td>
                                            <td>No active membership records.</td>
                                        </tr>
                                        <tr>
                                            <td></td>
                                            <td>
                                                <a href="renew.php?contact_id=<?php echo $profileId; ?>" class="btn btn-primary btn-small" style="margin-top: 5px; display: inline-block;">Purchase Membership</a>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </table>
                            </div>

                            <?php if ($hasPrivateAccess): ?>
                            <!-- Password Reset Panel -->
                            <div class="management-card mt-20">
                                <h4>Portal Password Reset</h4>
                                <p style="font-size: 0.85rem; color: rgba(255, 255, 255, 0.75); margin-bottom: 15px; line-height: 1.4;">
                                    To change your portal password, click the button below to receive a secure password reset link at your registered email address (<strong><?php echo e($contact['email']); ?></strong>).
                                </p>
                                <form action="profile.php?id=<?php echo $profileId; ?>" method="POST">
                                    <input type="hidden" name="csrf_token" value="<?php echo e(get_csrf_token()); ?>">
                                    <button type="submit" name="trigger_password_reset" class="btn btn-warning btn-block">Send Password Reset Email</button>
                                </form>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- Right Panel: Management (Owner, admin, or contact-managing staff) -->
                        <?php if ($hasPrivateAccess || $canManageContact): ?>
                            <div class="profile-actions-column">
                                <?php if ($canManageContact): ?>
                                <!-- Profile Settings Panel (display name, email opt-out, phone) -->
                                <div class="management-card">
                                    <h4>Profile Settings</h4>
                                    <form id="profileSettingsForm" action="profile.php?id=<?php echo $profileId; ?>" method="POST" class="settings-form">
                                        <input type="hidden" name="csrf_token" value="<?php echo e(get_csrf_token()); ?>">

                                        <div class="form-group" style="margin-bottom: 15px;">
                                            <label for="custom_display_name" style="display: block; font-size: 0.9rem; font-weight: 500; margin-bottom: 5px; color: #fff;">Preferred Display Name (Required)</label>
                                            <input type="text" id="custom_display_name" name="custom_display_name" value="<?php echo e($settings['custom_display_name'] ?? $contact['display_name']); ?>" required data-dirty-field>
                                        </div>

                                        <div class="form-group checkbox-group">
                                            <input type="checkbox" id="is_opt_out" name="is_opt_out" value="1"
                                                <?php echo !empty($contact['is_opt_out']) ? 'checked' : ''; ?> data-dirty-field>
                                            <label for="is_opt_out">Opt out of bulk emails (newsletters, announcements)</label>
                                        </div>

                                        <div class="form-group" style="margin-top: 15px;">
                                            <label for="phone" style="display: block; font-size: 0.85rem; margin-bottom: 5px; color: rgba(255,255,255,0.85);">Phone Number</label>
                                            <input type="tel" id="phone" name="phone" value="<?php echo e($contact['phone'] ? format_phone($contact['phone']) : ''); ?>" placeholder="(813) 555-0123" data-dirty-field>
                                            <p style="font-size: 0.75rem; color: rgba(255,255,255,0.6); margin-top: 5px;">US 10-digit number; leave blank to remove.</p>
                                        </div>

                                        <button type="submit" name="profile_settings_update" id="profileSettingsSaveBtn" class="btn btn-success btn-block mt-15">Save Changes</button>
                                    </form>
                                </div>
                                <?php endif; ?>

                                <!-- Contact Details Panel (Owner, admin, or contact-managing staff) -->
                                <div class="management-card mt-20">
                                    <h4>Email Address</h4>

                                    <?php if ($pendingEmailChange): ?>
                                        <div class="alert alert-warning" style="font-size: 0.85rem;">
                                            Pending change to <strong><?php echo e($pendingEmailChange['new_email']); ?></strong>, awaiting confirmation from that inbox (expires <?php echo date('g:i A, M j', strtotime($pendingEmailChange['expires_at'])); ?>).
                                        </div>
                                        <form action="profile.php?id=<?php echo $profileId; ?>" method="POST" style="margin-bottom: 15px;">
                                            <input type="hidden" name="csrf_token" value="<?php echo e(get_csrf_token()); ?>">
                                            <button type="submit" name="cancel_email_change" class="btn btn-secondary btn-block">Cancel Pending Email Change</button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if ($isOwner): ?>
                                        <p style="font-size: 0.85rem; color: rgba(255, 255, 255, 0.75); margin-bottom: 15px; line-height: 1.4;">
                                            To change your login email, enter the new address and your current password. A verification link will be sent to the new address &mdash; your email won't change until you click it.<?php if ($pendingEmailChange): ?> Submitting again replaces the pending request.<?php endif; ?>
                                        </p>
                                        <form action="profile.php?id=<?php echo $profileId; ?>" method="POST" class="settings-form">
                                            <input type="hidden" name="csrf_token" value="<?php echo e(get_csrf_token()); ?>">
                                            <div class="form-group">
                                                <label for="new_email" style="display: block; font-size: 0.85rem; margin-bottom: 5px; color: rgba(255,255,255,0.85);">New Email Address</label>
                                                <input type="email" id="new_email" name="new_email" required placeholder="new@example.com" autocomplete="off">
                                            </div>
                                            <div class="form-group" style="margin-top: 10px;">
                                                <label for="current_password" style="display: block; font-size: 0.85rem; margin-bottom: 5px; color: rgba(255,255,255,0.85);">Confirm Current Password</label>
                                                <input type="password" id="current_password" name="current_password" required placeholder="••••••••" autocomplete="current-password">
                                            </div>
                                            <button type="submit" name="request_email_change" class="btn btn-warning btn-block mt-15">Send Verification Link</button>
                                        </form>
                                    <?php elseif (has_role('superadmin')): ?>
                                        <p style="font-size: 0.85rem; color: rgba(255, 255, 255, 0.75); margin-bottom: 15px; line-height: 1.4;">
                                            <strong>Superadmin:</strong> this changes the member's login email immediately; both the old and new addresses will be notified.
                                        </p>
                                        <form action="profile.php?id=<?php echo $profileId; ?>" method="POST" class="settings-form" data-confirm="Change this member's login email immediately? Both addresses will be notified.">
                                            <input type="hidden" name="csrf_token" value="<?php echo e(get_csrf_token()); ?>">
                                            <div class="form-group">
                                                <label for="new_email" style="display: block; font-size: 0.85rem; margin-bottom: 5px; color: rgba(255,255,255,0.85);">Email Address</label>
                                                <input type="email" id="new_email" name="new_email" required value="<?php echo e($contact['email']); ?>">
                                            </div>
                                            <button type="submit" name="staff_contact_update" class="btn btn-warning btn-block mt-15">Update Email Address</button>
                                        </form>
                                    <?php else: ?>
                                        <p style="font-size: 0.85rem; color: rgba(255, 255, 255, 0.75); line-height: 1.4;">
                                            Only the member and superadmins can change this email address. Members can update it themselves from this page; ask a superadmin if direct assistance is needed.
                                        </p>
                                    <?php endif; ?>
                                </div>

                                <!-- Admin Role Assignment Card -->
                                <?php if ($isAdmin): ?>
                                <div class="management-card mt-20">
                                    <h4>Portal Role Assignment</h4>
                                    <form action="profile.php?id=<?php echo $profileId; ?>" method="POST" class="settings-form">
                                        <input type="hidden" name="csrf_token" value="<?php echo e(get_csrf_token()); ?>">
                                        <?php
                                            $targetHasSuperadmin = in_array('superadmin', $memberRoles, true);
                                            $viewerIsSuperadmin = has_role('superadmin');
                                            $isLastSuperadmin = $targetHasSuperadmin && Auth::countSuperadmins($appDb, $profileId) < 1;
                                        ?>
                                        <div class="form-group">
                                            <label style="display: block; font-size: 0.85rem; margin-bottom: 8px; color: rgba(255,255,255,0.85);">Assign Roles</label>
                                            <?php if ($isLastSuperadmin): ?>
                                                <p style="font-size: 0.75rem; color: rgba(255,255,255,0.6); margin-bottom: 8px;">This is the only superadmin account -- the superadmin role can't be removed until another user is granted it.</p>
                                            <?php endif; ?>
                                            <div style="display: flex; flex-direction: column; gap: 10px; margin-top: 5px;">
                                                <?php foreach ($rolesList as $roleOption):
                                                    $isSuperadminRole = ($roleOption['name'] === 'superadmin');

                                                    $disabled = '';
                                                    if ($isSuperadminRole && !$viewerIsSuperadmin) {
                                                        $disabled = 'disabled';
                                                    } elseif ($targetHasSuperadmin && !$viewerIsSuperadmin) {
                                                        $disabled = 'disabled';
                                                    }

                                                    // Not disabled even when this is the last superadmin: a
                                                    // disabled+checked checkbox is omitted from the POST entirely,
                                                    // which would look like an attempt to remove the role. The
                                                    // informational message above plus the server-side guard (see
                                                    // 2b in the role_update handler) are the actual enforcement.
                                                    $checked = in_array($roleOption['name'], $memberRoles, true) ? 'checked' : '';
                                                ?>
                                                    <label style="display: inline-flex; align-items: center; gap: 8px; color: #fff; cursor: <?php echo $disabled ? 'not-allowed' : 'pointer'; ?>; opacity: <?php echo $disabled ? '0.5' : '1'; ?>;">
                                                        <input type="checkbox" name="roles[]" value="<?php echo e($roleOption['name']); ?>" <?php echo $checked; ?> <?php echo $disabled; ?> style="width: auto;">
                                                        <?php echo e(ucfirst($roleOption['name'])); ?>
                                                    </label>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                        <button type="submit" name="role_update" class="btn btn-primary btn-block mt-15">Update Roles</button>
                                        <?php 
                                        $originalRoles = $_SESSION['impersonator']['roles'] ?? $_SESSION['user']['roles'] ?? [];
                                        $originalRole = $_SESSION['impersonator']['role'] ?? $_SESSION['user']['role'] ?? '';
                                        $isOriginalSuperadmin = in_array('superadmin', $originalRoles, true) || $originalRole === 'superadmin';
                                        if ($isOriginalSuperadmin && $profileId !== (int)$_SESSION['user']['contact_id']): 
                                        ?>
                                            <button type="submit" name="impersonate_user" class="btn btn-warning btn-block mt-10">Login As User</button>
                                        <?php endif; ?>
                                    </form>
                                </div>
                                
                                <!-- Admin Adjust Subscription Rate Card -->
                                <?php
                                if ($membership):
                                    // Fetch active rates for this plan, newest first, alongside the
                                    // plan's default_rate_id so the dropdown can label the current
                                    // rate distinctly from earlier ones (by their effective date)
                                    // instead of showing raw, importer-generated rate names.
                                    $activeRates = [];
                                    $planDefaultRateId = null;
                                    try {
                                        $planStmt = $appDb->prepare("SELECT default_rate_id FROM tgg_subscription_plans WHERE id = :id LIMIT 1");
                                        $planStmt->execute(['id' => $membership['membership_id']]);
                                        $planDefaultRateId = (int)($planStmt->fetchColumn() ?: 0);

                                        $ratesStmt = $appDb->prepare("
                                            SELECT * FROM tgg_subscription_rates
                                            WHERE plan_id = :plan_id
                                              AND inactive = 0
                                              AND (expiration_date IS NULL OR expiration_date >= CURRENT_DATE())
                                            ORDER BY created_at DESC, id DESC
                                        ");
                                        $ratesStmt->execute(['plan_id' => $membership['membership_id']]);
                                        $activeRates = $ratesStmt->fetchAll(PDO::FETCH_ASSOC);
                                    } catch (Exception $e) {
                                        $activeRates = [];
                                    }
                                ?>
                                <div class="management-card mt-20">
                                    <h4>Adjust Subscription Rate</h4>
                                    <form action="profile.php?id=<?php echo $profileId; ?>" method="POST" class="settings-form">
                                        <input type="hidden" name="csrf_token" value="<?php echo e(get_csrf_token()); ?>">
                                        <div class="form-group">
                                            <label for="rate_id" style="display: block; font-size: 0.85rem; margin-bottom: 8px; color: rgba(255,255,255,0.85);">Select Active Rate</label>
                                            <select name="rate_id" id="rate_id" required>
                                                <?php foreach ($activeRates as $rate):
                                                    $isCurrentRate = $planDefaultRateId === (int)$rate['id'];
                                                    $rateLabel = $isCurrentRate
                                                        ? 'Current'
                                                        : 'Previous ' . ($rate['created_at'] ? date('M j, Y', strtotime($rate['created_at'])) : '');
                                                ?>
                                                    <option value="<?php echo (int)$rate['id']; ?>" <?php echo (isset($membership['rate_id']) && (int)$membership['rate_id'] === (int)$rate['id']) ? 'selected' : ''; ?>>
                                                        <?php echo e($rateLabel); ?> - $<?php echo number_format($rate['price'], 2); ?> / <?php echo e($rate['billing_frequency']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <button type="submit" name="update_member_rate" class="btn btn-primary btn-block mt-15">Update Rate</button>
                                    </form>
                                </div>
                                <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php if ($hasPrivateAccess): ?>
                    </div>
                    <?php endif; ?>

                    <?php if ($hasPrivateAccess): ?>
                    <div id="tab-volunteering" class="tab-content">
                        <!-- MEMBERSHIP CREDITS SECTION -->
                        <div class="detail-section private-detail-section full-width-section">
                            <div class="section-header">
                                <h3 class="section-title">Membership Credits</h3>
                                <span class="private-badge">🔒 Owner & Admins Only</span>
                            </div>

                            <div class="volunteer-summary-grid" style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px; margin-bottom: 20px; background: rgba(255, 255, 255, 0.02); padding: 15px; border-radius: 8px; border: 1px solid var(--border-glass);">
                                <div>
                                    <p style="margin: 0; font-size: 0.85rem; color: var(--color-text-secondary);">Lifetime Earned:</p>
                                    <h4 style="margin: 5px 0 0 0; color: #fff; font-size: 1.2rem;">
                                        <?php echo (int)$totalEarned; ?>
                                        <?php if ($pendingCredits > 0): ?>
                                            <span style="font-size: 0.8rem; color: var(--color-text-secondary); font-weight: normal;">
                                                (+<?php echo (int)$pendingCredits; ?> pending confirmation)
                                            </span>
                                        <?php endif; ?>
                                    </h4>
                                </div>
                                <div>
                                    <p style="margin: 0; font-size: 0.85rem; color: var(--color-text-secondary);">Lifetime Applied:</p>
                                    <h4 style="margin: 5px 0 0 0; color: #fff; font-size: 1.2rem;"><?php echo (int)$totalApplied; ?></h4>
                                </div>
                                <div>
                                    <p style="margin: 0; font-size: 0.85rem; color: var(--color-text-secondary);">Outstanding Balance:</p>
                                    <h4 style="margin: 5px 0 0 0; color: var(--color-success); font-size: 1.2rem;">
                                        <?php echo (int)$availableCredits; ?>
                                        <?php if ($pendingCredits > 0): ?>
                                            <span style="font-size: 0.8rem; color: var(--color-text-secondary); font-weight: normal;">
                                                (+<?php echo (int)$pendingCredits; ?> pending confirmation)
                                            </span>
                                        <?php endif; ?>
                                    </h4>
                                </div>
                                <div>
                                    <p style="margin: 0; font-size: 0.85rem; color: var(--color-text-secondary);">Expired Credits:</p>
                                    <h4 style="margin: 5px 0 0 0; color: var(--color-danger); font-size: 1.2rem;"><?php echo (int)$totalExpired; ?></h4>
                                </div>
                            </div>

                            <div style="margin-bottom: 20px; background: rgba(255, 255, 255, 0.02); padding: 12px 15px; border-radius: 8px; border: 1px solid var(--border-glass);">
                                <p style="margin: 0 0 5px 0; font-size: 0.85rem; color: var(--color-text-secondary);">Next Expiration:</p>
                                <strong style="color: #fff;">
                                    <?php if ($nextExpirationDate === 'Never'): ?>
                                        Never
                                    <?php else: ?>
                                        <?php echo date('F j, Y', strtotime($nextExpirationDate)); ?>
                                        <span style="color: var(--color-danger); font-weight: normal; font-size: 0.85rem; margin-left: 10px;">
                                            (<?php echo (int)$nextExpirationCredits; ?> credit<?php echo $nextExpirationCredits > 1 ? 's' : ''; ?> will expire)
                                        </span>
                                    <?php endif; ?>
                                </strong>
                            </div>

                            <div style="margin-bottom: 20px; background: rgba(255, 255, 255, 0.02); padding: 12px 15px; border-radius: 8px; border: 1px solid var(--border-glass); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                                <div>
                                    <p style="margin: 0 0 3px 0; font-size: 0.9rem; color: #fff; font-weight: 600;">Auto-Apply Membership Credits</p>
                                    <p style="margin: 0; font-size: 0.8rem; color: var(--color-text-secondary);">When enabled, banked credits are used automatically at auto-renewal (instead of charging your card) whenever there's enough for at least one month.</p>
                                </div>
                                <form action="profile.php?id=<?php echo $profileId; ?>" method="POST" style="display: inline; margin: 0;">
                                    <input type="hidden" name="csrf_token" value="<?php echo e(get_csrf_token()); ?>">
                                    <input type="hidden" name="auto_apply_credits" value="<?php echo !empty($settings['auto_apply_credits']) ? '0' : '1'; ?>">
                                    <button type="submit" name="auto_apply_credits_update" class="btn <?php echo !empty($settings['auto_apply_credits']) ? 'btn-success' : 'btn-secondary'; ?> btn-small">
                                        <?php echo !empty($settings['auto_apply_credits']) ? 'Enabled — Turn Off' : 'Disabled — Turn On'; ?>
                                    </button>
                                </form>
                            </div>

                            <h4 style="margin: 20px 0 10px 0; color: #fff; font-size: 0.95rem;">Membership Credits Earned</h4>
                            <?php if (empty($creditGrants)): ?>
                                <p class="private-locked-msg">No Membership Credits earned yet.</p>
                            <?php else: ?>
                                <div class="admin-table-container" style="margin-bottom: 20px;">
                                    <table class="admin-table" style="font-size: 0.85rem; width: 100%;">
                                        <thead>
                                            <tr>
                                                <th style="padding: 8px 10px;">Date</th>
                                                <th style="padding: 8px 10px;">Shift</th>
                                                <th style="padding: 8px 10px; text-align: center;">Granted</th>
                                                <th style="padding: 8px 10px; text-align: center;">Used</th>
                                                <th style="padding: 8px 10px; text-align: center;">Remaining</th>
                                                <th style="padding: 8px 10px;">Expires</th>
                                                <th style="padding: 8px 10px; text-align: center;">Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($creditGrants as $grant):
                                                $statusBadges = [
                                                    'available' => ['class' => 'badge-active', 'style' => '', 'label' => 'Available'],
                                                    'partially_used' => ['class' => 'badge-active', 'style' => 'background: rgba(59, 130, 246, 0.15); color: #3b82f6; border: 1px solid rgba(59, 130, 246, 0.3);', 'label' => 'Partially Used'],
                                                    'fully_used' => ['class' => 'badge-status', 'style' => '', 'label' => 'Fully Used'],
                                                    'expired' => ['class' => 'badge-expired', 'style' => '', 'label' => 'Expired'],
                                                ];
                                                $badge = $statusBadges[$grant['status']] ?? $statusBadges['available'];
                                            ?>
                                                <tr>
                                                    <td style="padding: 8px 10px;"><span class="table-datetime"><?php echo date('Y-m-d', strtotime($grant['date'])); ?></span></td>
                                                    <td style="padding: 8px 10px;"><?php echo e($grant['shift']); ?></td>
                                                    <td style="padding: 8px 10px; text-align: center; font-weight: bold; color: var(--color-primary);">+<?php echo (int)$grant['granted']; ?></td>
                                                    <td style="padding: 8px 10px; text-align: center;"><?php echo (int)$grant['used']; ?></td>
                                                    <td style="padding: 8px 10px; text-align: center; font-weight: bold;"><?php echo (int)$grant['remaining']; ?></td>
                                                    <td style="padding: 8px 10px;"><?php echo $grant['expires_on'] ? date('Y-m-d', strtotime($grant['expires_on'])) : '—'; ?></td>
                                                    <td style="padding: 8px 10px; text-align: center;">
                                                        <span class="badge <?php echo $badge['class']; ?>" style="font-size: 0.75rem; padding: 2px 6px; display: inline-block; <?php echo $badge['style']; ?>"><?php echo $badge['label']; ?></span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>

                            <h4 style="margin: 20px 0 10px 0; color: #fff; font-size: 0.95rem;">Shifts Awaiting Confirmation</h4>
                            <?php if (empty($volunteerShifts)): ?>
                                <p class="private-locked-msg">No shifts found.</p>
                            <?php else: ?>
                                <div class="admin-table-container">
                                    <table class="admin-table" style="font-size: 0.85rem; width: 100%;">
                                        <thead>
                                            <tr>
                                                <th style="padding: 8px 10px;">Date</th>
                                                <th style="padding: 8px 10px;">Event</th>
                                                <th style="padding: 8px 10px;">Shift</th>
                                                <th style="padding: 8px 10px; text-align: center;">Credits</th>
                                                <th style="padding: 8px 10px; text-align: center;">Status</th>
                                                <?php if ($showActorCol): ?>
                                                    <th style="padding: 8px 10px;">Confirmed By</th>
                                                <?php endif; ?>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($volunteerShifts as $tx): ?>
                                                <tr>
                                                    <td style="padding: 8px 10px;"><span class="table-datetime"><?php echo date('Y-m-d', strtotime($tx['date'])); ?></span></td>
                                                    <td style="padding: 8px 10px; font-weight: bold; color: #fff;"><?php echo e($tx['event_title'] ?: 'Volunteer Event'); ?></td>
                                                    <td style="padding: 8px 10px;"><?php echo e($tx['shift']); ?></td>
                                                    <td style="padding: 8px 10px; text-align: center; font-weight: bold; color: var(--color-primary);">+<?php echo (int)$tx['credits']; ?></td>
                                                    <td style="padding: 8px 10px; text-align: center;">
                                                        <?php if ($tx['status'] === 'Confirmed'): ?>
                                                            <span class="badge badge-active" style="font-size: 0.75rem; padding: 2px 6px; display: inline-block;">Confirmed</span>
                                                        <?php else: ?>
                                                            <span class="badge badge-expired" style="font-size: 0.75rem; padding: 2px 6px; display: inline-block; background: rgba(234, 179, 8, 0.15); color: #eab308; border: 1px solid rgba(234, 179, 8, 0.3);">Awaiting Confirmation</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <?php if ($showActorCol): ?>
                                                        <td style="padding: 8px 10px;"><?php echo e($tx['processed_by']); ?></td>
                                                    <?php endif; ?>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($hasPrivateAccess): ?>
                    <div id="tab-attendance" class="tab-content">
                        <div class="detail-section private-detail-section full-width-section">
                            <div class="section-header">
                                <h3 class="section-title">Attendance History</h3>
                                <span class="private-badge">🔒 Owner & Staff Only</span>
                            </div>
                            <?php if (empty($attendanceRecords)): ?>
                                <p class="private-locked-msg">No attendance records found.</p>
                            <?php else: ?>
                                <div class="admin-table-container">
                                    <table class="admin-table" style="font-size: 0.85rem; width: 100%;">
                                        <thead>
                                            <tr>
                                                <th style="padding: 8px 10px;">Date</th>
                                                <th style="padding: 8px 10px;">Time</th>
                                                <th style="padding: 8px 10px;">Notes</th>
                                                <th style="padding: 8px 10px; text-align: center;">+1</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($attendanceRecords as $ar): ?>
                                                <?php $isGuest = !empty($ar['guest_name']); ?>
                                                <tr>
                                                    <td style="padding: 8px 10px;"><span class="table-datetime"><?php echo date('Y-m-d', strtotime($ar['checked_in_at'])); ?></span></td>
                                                    <td style="padding: 8px 10px;"><span class="table-datetime"><?php echo date('g:i A', strtotime($ar['checked_in_at'])); ?></span></td>
                                                    <td style="padding: 8px 10px;"><?php echo $isGuest ? 'Guest: ' . e($ar['guest_name']) : e($ar['notes'] ?: 'Regular Visit'); ?></td>
                                                    <td style="padding: 8px 10px; text-align: center;">
                                                        <?php if ($isGuest): ?>
                                                            <span title="Guest: <?php echo e($ar['guest_name']); ?>">&check;</span>
                                                        <?php else: ?>
                                                            <span style="color: var(--color-text-muted);">-</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($canViewBilling): ?>
                    <div id="tab-billing" class="tab-content <?php echo !$hasPrivateAccess ? 'active' : ''; ?>">
                        <!-- BILLING HISTORY SECTION -->
                        <div class="detail-section private-detail-section full-width-section">
                            <div class="section-header">
                                <h3 class="section-title">Payment History</h3>
                                <span class="private-badge">🔒 Owner & Staff Only</span>
                            </div>
                            
                            <?php if (empty($transactions)): ?>
                                <p class="private-locked-msg">No billing transactions found.</p>
                            <?php else: ?>
                                <div class="admin-table-container">
                                    <table class="admin-table" style="font-size: 0.85rem; width: 100%;">
                                        <thead>
                                            <tr>
                                                <th style="padding: 8px 10px;">Date</th>
                                                <th style="padding: 8px 10px;">Plan</th>
                                                <th style="padding: 8px 10px;">Amount</th>
                                                <th style="padding: 8px 10px;">Status</th>
                                                <?php if ($showActorCol): ?>
                                                    <th style="padding: 8px 10px;">Recorded By</th>
                                                <?php endif; ?>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($transactions as $tx): ?>
                                                 <?php
                                                 $badgeClass = 'badge-expired';
                                                 $badgeLabel = ucfirst($tx['payment_status']);
                                                 if ($tx['payment_status'] === 'paid') {
                                                     $trxnId = $tx['trxn_id'] ?? '';
                                                     if (strpos($trxnId, 'trial_') === 0) {
                                                         $badgeClass = 'badge-free';
                                                         $badgeLabel = 'Email Verified';
                                                     } elseif (strpos($trxnId, 'credit_redeem_') === 0) {
                                                         $badgeClass = 'badge-volunteer';
                                                         $badgeLabel = 'Membership Credits Redeemed';
                                                     } elseif (strpos($trxnId, 'offline_volunteer_credit_') === 0) {
                                                         $badgeClass = 'badge-volunteer';
                                                         $badgeLabel = 'Volunteer';
                                                     } elseif (strpos($trxnId, 'offline_complimentary_') === 0) {
                                                         $badgeClass = 'badge-free';
                                                         $badgeLabel = 'Free';
                                                     } elseif (strpos($trxnId, 'offline_cash_') === 0) {
                                                         $badgeClass = 'badge-active';
                                                         $badgeLabel = 'Paid (Cash)';
                                                     } elseif (strpos($trxnId, 'offline_check_') === 0) {
                                                         $badgeClass = 'badge-active';
                                                         $badgeLabel = 'Paid (Check)';
                                                     } elseif (($tx['action_type'] ?? '') === 'auto_renew') {
                                                         $badgeClass = 'badge-active';
                                                         $badgeLabel = 'Paid (Auto-Renew)';
                                                     } else {
                                                         $badgeClass = 'badge-active';
                                                         $badgeLabel = 'Paid (Credit Card)';
                                                     }
                                                 }
                                                 ?>
                                                 <tr>
                                                     <td style="padding: 8px 10px;"><span class="table-datetime"><?php echo date('Y-m-d', strtotime($tx['created_at'])); ?></span></td>
                                                     <td style="padding: 8px 10px;"><strong><?php echo e($tx['plan_name']); ?></strong></td>
                                                     <td style="padding: 8px 10px;">$<?php echo number_format($tx['amount'], 2); ?></td>
                                                     <td style="padding: 8px 10px;">
                                                         <span class="badge <?php echo $badgeClass; ?>" style="font-size: 0.75rem; padding: 2px 6px; display: inline-block;">
                                                             <?php echo e($badgeLabel); ?>
                                                         </span>
                                                     </td>
                                                     <?php if ($showActorCol): ?>
                                                         <td style="padding: 8px 10px;">
                                                             <?php
                                                             $txNames = [];
                                                             if ($tx['created_by'] !== null) {
                                                                 $txNames[(int)$tx['created_by']] = $tx['created_by_name'] ?? "Member #{$tx['created_by']}";
                                                             }
                                                             if ($tx['impersonator_id'] !== null) {
                                                                 $txNames[(int)$tx['impersonator_id']] = $tx['impersonator_name'] ?? "Member #{$tx['impersonator_id']}";
                                                             }
                                                             echo e(AuditLog::describeActor(
                                                                 $tx['created_by'] !== null ? (int)$tx['created_by'] : null,
                                                                 $tx['impersonator_id'] !== null ? (int)$tx['impersonator_id'] : null,
                                                                 $tx['source'],
                                                                 $txNames
                                                             ));
                                                             ?>
                                                         </td>
                                                     <?php endif; ?>
                                                 </tr>
                                             <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </main>

        <?php include __DIR__ . '/partials/footer.php'; ?>

    <script>
    function switchTab(tabId) {
        document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
        document.querySelectorAll('.tab-button').forEach(el => el.classList.remove('active'));
        
        const content = document.getElementById('tab-' + tabId);
        if (content) content.classList.add('active');
        
        const btn = document.querySelector(`.tab-button[onclick*="'${tabId}'"]`);
        if (btn) btn.classList.add('active');
    }

    (function() {
        var form = document.getElementById('profileSettingsForm');
        var saveBtn = document.getElementById('profileSettingsSaveBtn');
        if (!form || !saveBtn) return;

        var fields = form.querySelectorAll('[data-dirty-field]');
        var initial = {};
        fields.forEach(function(el) {
            initial[el.name] = (el.type === 'checkbox') ? el.checked : el.value;
        });

        function checkDirty() {
            var dirty = false;
            fields.forEach(function(el) {
                var current = (el.type === 'checkbox') ? el.checked : el.value;
                if (current !== initial[el.name]) dirty = true;
            });
            saveBtn.disabled = !dirty;
        }

        saveBtn.disabled = true;
        fields.forEach(function(el) {
            el.addEventListener('input', checkDirty);
            el.addEventListener('change', checkDirty);
        });
    })();

    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('sw.js')
                .then(reg => console.log('Service Worker registered'))
                .catch(err => console.error('Service Worker registration failed', err));
        });
    }
    </script>
</body>
</html>
