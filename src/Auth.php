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

        // 5. Store in Session
        $_SESSION['user'] = [
            'contact_id' => $contactId,
            'email' => $email,
            'display_name' => $displayName,
            'role' => $authRow['role'],
            'is_profile_public' => (int)$authRow['is_profile_public']
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
        if (strlen($password) < 8) {
            throw new Exception("Password must be at least 8 characters long.");
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
     * Require administrative role for a page
     */
    public static function requireAdmin(): void {
        self::requireAuth();
        if ($_SESSION['user']['role'] !== 'admin') {
            redirect('index.php?error=unauthorized');
        }
    }
}
