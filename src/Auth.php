<?php
namespace App;

use Exception;
use PDO;

/**
 * Authentication and Session Management
 * Authenticates users against CiviCRM emails and local credentials.
 */
class Auth {
    
    /**
     * Authenticate a user by email and password
     * @param string $email
     * @param string $password
     * @return array|bool User details array on success, false on failure
     * @throws Exception
     */
    public static function login(string $email, string $password): array|bool {
        $email = trim(strtolower($email));
        if (empty($email) || empty($password)) {
            return false;
        }

        $appDb = Database::getAppConnection();

        // 1. Find the contact ID from local contacts using the email
        $emailQuery = "SELECT id FROM tgg_contacts WHERE email = :email AND is_deleted = 0 LIMIT 1";
        $stmt = $appDb->prepare($emailQuery);
        $stmt->execute(['email' => $email]);
        $emailRow = $stmt->fetch();

        if (!$emailRow) {
            return false; // Email not found
        }

        $contactId = (int)$emailRow['id'];

        // 2. Fetch the credentials from local tgg_member_settings
        $authQuery = "SELECT password_hash, role, is_profile_public, failed_login_attempts, locked_until FROM tgg_member_settings WHERE contact_id = :contact_id LIMIT 1";
        $stmt = $appDb->prepare($authQuery);
        $stmt->execute(['contact_id' => $contactId]);
        $authRow = $stmt->fetch();

        if (!$authRow) {
            return false; // Account settings/password not set for this contact yet
        }

        // Check if account is locked
        if (!empty($authRow['locked_until'])) {
            $lockedUntil = strtotime($authRow['locked_until']);
            if (time() < $lockedUntil) {
                $secondsLeft = $lockedUntil - time();
                throw new Exception("Account is temporarily locked due to too many failed login attempts. Please try again in " . ceil($secondsLeft / 60) . " minute(s).", 423);
            }
        }

        // 3. Verify the password hash
        if (!password_verify($password, $authRow['password_hash'])) {
            $attempts = (int)$authRow['failed_login_attempts'] + 1;
            $lockedUntil = null;
            if ($attempts >= 5) {
                $lockoutTime = 900; // 15 mins
                if ($attempts > 5) {
                    $lockoutTime = 900 * pow(2, min($attempts - 5, 4)); // max 15 mins * 16 = 4 hours
                }
                $lockedUntil = date('Y-m-d H:i:s', time() + $lockoutTime);
            }
            
            $updateStmt = $appDb->prepare("UPDATE tgg_member_settings SET failed_login_attempts = :attempts, locked_until = :locked_until WHERE contact_id = :contact_id");
            $updateStmt->execute([
                'attempts' => $attempts,
                'locked_until' => $lockedUntil,
                'contact_id' => $contactId
            ]);
            
            if ($lockedUntil) {
                throw new Exception("Incorrect password. Too many failed attempts. Account is now locked for " . ($lockoutTime / 60) . " minutes.", 423);
            }
            return false;
        }

        // Reset failed attempts on successful login
        if ($authRow['failed_login_attempts'] > 0 || $authRow['locked_until'] !== null) {
            $resetStmt = $appDb->prepare("UPDATE tgg_member_settings SET failed_login_attempts = 0, locked_until = NULL WHERE contact_id = :contact_id");
            $resetStmt->execute(['contact_id' => $contactId]);
        }

        // 4. Fetch the user's display name according to privacy preferences
        $displayName = CiviCRMImporter::getFormattedName($contactId);

        // Fetch all roles assigned to this contact
        $rolesStmt = $appDb->prepare("SELECT role_name FROM tgg_member_roles WHERE contact_id = :contact_id");
        $rolesStmt->execute(['contact_id' => $contactId]);
        $roles = $rolesStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if (empty($roles)) {
            $roles = [$authRow['role']];
        }

        // Fetch permissions for these roles (union of permissions)
        $permissions = [];
        if (!empty($roles)) {
            $placeholders = implode(',', array_fill(0, count($roles), '?'));
            $permStmt = $appDb->prepare("
                SELECT DISTINCT p.name 
                FROM tgg_permissions p
                JOIN tgg_role_permissions rp ON rp.permission_id = p.id
                JOIN tgg_roles r ON r.id = rp.role_id
                WHERE r.name IN ($placeholders)
            ");
            $permStmt->execute($roles);
            $permissions = $permStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        }

        // 5. Store in Session
        $_SESSION['user'] = [
            'contact_id' => $contactId,
            'email' => $email,
            'display_name' => $displayName,
            'roles' => $roles,
            'role' => $roles[0] ?? $authRow['role'], // for legacy string compatibility
            'is_profile_public' => (int)$authRow['is_profile_public'],
            'permissions' => $permissions
        ];

        // Regenerate session ID to prevent session fixation attacks
        session_regenerate_id(true);

        // Rotate CSRF token on successful login to prevent pre-auth token hijacking
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

        return $_SESSION['user'];
    }

    /**
     * Register or update a user's password locally
     * @param int $contactId
     * @param string $password
     * @param string $role
     * @return bool
     * @throws Exception
     */
    public static function registerPassword(int $contactId, string $password, string $role = 'member'): bool {
        if (!is_password_complex($password, $error)) {
            throw new Exception($error);
        }

        $appDb = Database::getAppConnection();
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        // Check if settings record already exists
        $checkQuery = "SELECT contact_id FROM tgg_member_settings WHERE contact_id = :contact_id LIMIT 1";
        $stmt = $appDb->prepare($checkQuery);
        $stmt->execute(['contact_id' => $contactId]);
        $exists = $stmt->fetch();

        if ($exists) {
            $updateQuery = "UPDATE tgg_member_settings SET password_hash = :password_hash, role = :role WHERE contact_id = :contact_id";
            $stmt = $appDb->prepare($updateQuery);
            return $stmt->execute([
                'password_hash' => $passwordHash,
                'role' => $role,
                'contact_id' => $contactId
            ]);
        } else {
            $insertQuery = "INSERT INTO tgg_member_settings (contact_id, password_hash, role, is_profile_public, public_fields) 
                            VALUES (:contact_id, :password_hash, :role, 1, :public_fields)";
            $stmt = $appDb->prepare($insertQuery);
            return $stmt->execute([
                'contact_id' => $contactId,
                'password_hash' => $passwordHash,
                'role' => $role,
                'public_fields' => json_encode(['display_name', 'membership_type', 'membership_status'])
            ]);
        }
    }

    /**
     * Generate a password setup/reset token for an email address, storing its hash in
     * tgg_password_resets, and return the raw token to embed in an emailed link.
     * Used both for "forgot password" requests and for "set up your portal password"
     * links sent after a new member joins (members aren't required to have a password).
     * @param string $email
     * @param string $expiresIn A strtotime()-compatible relative expiry, e.g. '+1 hour'
     * @return string The raw (unhashed) token
     */
    public static function createPasswordSetupToken(string $email, string $expiresIn = '+1 hour'): string {
        $appDb = Database::getAppConnection();
        $email = trim(strtolower($email));

        $rawToken = bin2hex(random_bytes(32));
        $hashedToken = hash('sha256', $rawToken);
        $expiresAt = date('Y-m-d H:i:s', strtotime($expiresIn));

        $stmt = $appDb->prepare("
            INSERT INTO tgg_password_resets (email, token, expires_at)
            VALUES (:email, :token, :expires_at)
            ON DUPLICATE KEY UPDATE token = :token2, expires_at = :expires_at2
        ");
        $stmt->execute([
            'email' => $email,
            'token' => $hashedToken,
            'expires_at' => $expiresAt,
            'token2' => $hashedToken,
            'expires_at2' => $expiresAt
        ]);

        return $rawToken;
    }

    /**
     * Log out the current user
     */
    public static function logout(): void {
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params["path"],
                $params["domain"],
                $params["secure"],
                $params["httponly"]
            );
        }
        session_destroy();
    }

    /**
     * Check if a user is authenticated
     */
    public static function check(): bool {
        return isset($_SESSION['user']['contact_id']);
    }

    /**
     * Require authentication for a page
     */
    public static function requireAuth(): void {
        if (!self::check()) {
            $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
            redirect('index.php?action=login');
        }
    }

    /**
     * Require any staff-level permission (non-empty permissions set).
     * Grants access to the admin section for all privileged roles.
     * Members have no permissions so are naturally excluded.
     */
    public static function requireStaff(): void {
        self::requireAuth();
        if (empty($_SESSION['user']['permissions'] ?? [])) {
            redirect('index.php?error=unauthorized');
        }
    }

    /**
     * Require the 'admin panel' permission.
     * Used internally for admin-only inline UI capabilities.
     * @deprecated Use Auth::requirePermission('admin panel') or Auth::requireStaff() directly.
     */
    public static function requireAdmin(): void {
        self::requireAuth();
        if (!has_permission('admin panel')) {
            redirect('index.php?error=unauthorized');
        }
    }

    /**
     * Require a specific permission for a page
     */
    public static function requirePermission(string $permission): void {
        self::requireAuth();
        if (!has_permission($permission)) {
            redirect('index.php?error=unauthorized');
        }
    }

    /**
     * Refresh the current user's role and permissions in the session
     */
    public static function refreshPermissions(): void {
        if (!self::check()) {
            return;
        }
        $contactId = (int)$_SESSION['user']['contact_id'];
        $appDb = Database::getAppConnection();
        
        $stmt = $appDb->prepare("SELECT role, is_profile_public FROM tgg_member_settings WHERE contact_id = :contact_id LIMIT 1");
        $stmt->execute(['contact_id' => $contactId]);
        $row = $stmt->fetch();
        if ($row) {
            // Fetch multiple roles
            $rolesStmt = $appDb->prepare("SELECT role_name FROM tgg_member_roles WHERE contact_id = :contact_id");
            $rolesStmt->execute(['contact_id' => $contactId]);
            $roles = $rolesStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
            if (empty($roles)) {
                $roles = [$row['role']];
            }

            $_SESSION['user']['roles'] = $roles;
            $_SESSION['user']['role'] = $roles[0] ?? $row['role']; // for legacy string compatibility
            $_SESSION['user']['is_profile_public'] = (int)$row['is_profile_public'];
            
            $permissions = [];
            if (!empty($roles)) {
                $placeholders = implode(',', array_fill(0, count($roles), '?'));
                $permStmt = $appDb->prepare("
                    SELECT DISTINCT p.name 
                    FROM tgg_permissions p
                    JOIN tgg_role_permissions rp ON rp.permission_id = p.id
                    JOIN tgg_roles r ON r.id = rp.role_id
                    WHERE r.name IN ($placeholders)
                ");
                $permStmt->execute($roles);
                $permissions = $permStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
            }
            $_SESSION['user']['permissions'] = $permissions;
        }
    }

    /**
     * Impersonate a user by their contact ID.
     * @param int $targetContactId
     * @return bool
     * @throws Exception
     */
    public static function impersonate(int $targetContactId): bool {
        // Only superadmins (original identity) can initiate impersonation
        $originalRoles = $_SESSION['impersonator']['roles'] ?? $_SESSION['user']['roles'] ?? [];
        $originalRole = $_SESSION['impersonator']['role'] ?? $_SESSION['user']['role'] ?? '';
        $isOriginalSuperadmin = in_array('superadmin', $originalRoles, true) || $originalRole === 'superadmin';
        if (!$isOriginalSuperadmin) {
            throw new Exception("Only superadmins can impersonate other users.");
        }

        // Cannot impersonate oneself
        $originalContactId = (int)($_SESSION['impersonator']['contact_id'] ?? $_SESSION['user']['contact_id']);
        if ($targetContactId === $originalContactId) {
            throw new Exception("You cannot impersonate yourself.");
        }

        $appDb = Database::getAppConnection();

        // 1. Fetch the target user details from local contacts and settings
        $contactQuery = "SELECT id, email, display_name FROM tgg_contacts WHERE id = :id AND is_deleted = 0 LIMIT 1";
        $stmt = $appDb->prepare($contactQuery);
        $stmt->execute(['id' => $targetContactId]);
        $contactRow = $stmt->fetch();

        if (!$contactRow) {
            throw new Exception("Target contact not found.");
        }

        $authQuery = "SELECT role, is_profile_public FROM tgg_member_settings WHERE contact_id = :contact_id LIMIT 1";
        $stmt = $appDb->prepare($authQuery);
        $stmt->execute(['contact_id' => $targetContactId]);
        $authRow = $stmt->fetch();

        $roleName = $authRow ? $authRow['role'] : 'member';
        $isProfilePublic = $authRow ? (int)$authRow['is_profile_public'] : 1;

        // 2. Fetch target user's roles
        $rolesStmt = $appDb->prepare("SELECT role_name FROM tgg_member_roles WHERE contact_id = :contact_id");
        $rolesStmt->execute(['contact_id' => $targetContactId]);
        $roles = $rolesStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if (empty($roles)) {
            $roles = [$roleName];
        }

        // 3. Fetch target user's permissions
        $permissions = [];
        if (!empty($roles)) {
            $placeholders = implode(',', array_fill(0, count($roles), '?'));
            $permStmt = $appDb->prepare("
                SELECT DISTINCT p.name 
                FROM tgg_permissions p
                JOIN tgg_role_permissions rp ON rp.permission_id = p.id
                JOIN tgg_roles r ON r.id = rp.role_id
                WHERE r.name IN ($placeholders)
            ");
            $permStmt->execute($roles);
            $permissions = $permStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        }

        // 4. Save original session as impersonator if not already impersonating
        if (!isset($_SESSION['impersonator'])) {
            $_SESSION['impersonator'] = $_SESSION['user'];
        }

        // 5. Overwrite user session with target user details
        $_SESSION['user'] = [
            'contact_id' => $targetContactId,
            'email' => $contactRow['email'],
            'display_name' => CiviCRMImporter::getFormattedName($targetContactId),
            'roles' => $roles,
            'role' => $roles[0] ?? $roleName,
            'is_profile_public' => $isProfilePublic,
            'permissions' => $permissions
        ];

        return true;
    }

    /**
     * Stop impersonating another user and restore the original superadmin session.
     * @return bool True on success, false if not impersonating
     */
    public static function stopImpersonating(): bool {
        if (!isset($_SESSION['impersonator'])) {
            return false;
        }
        $_SESSION['user'] = $_SESSION['impersonator'];
        unset($_SESSION['impersonator']);
        return true;
    }
}
