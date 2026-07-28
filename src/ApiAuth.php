<?php
namespace App;

use Exception;

/**
 * Token-based auth for the mobile API, parallel to the session-based Auth
 * class used by the web app. Two token types:
 *  - Access token: short-lived (15 min), self-verified via an HMAC signature
 *    plus embedded expiry -- no DB lookup needed per request, so it never
 *    touches tgg_api_tokens.
 *  - Refresh token: long-lived (30 days, matching the web session cookie),
 *    stored hashed in tgg_api_tokens (same hash-only-never-raw precedent as
 *    tgg_password_resets/tgg_trial_verifications) and revocable. Rotated on
 *    every use so a stolen refresh token has a one-time window.
 */
class ApiAuth {
    private const ACCESS_TOKEN_TTL = 900; // 15 minutes
    private const REFRESH_TOKEN_TTL = '+30 days';

    /**
     * Verify credentials and issue a new access/refresh token pair.
     * @return array{access_token: string, refresh_token: string, expires_in: int, token_type: string}|false
     * @throws Exception On a locked account (code 423), same as Auth::login()
     */
    public static function login(string $email, string $password, ?string $deviceLabel = null): array|false {
        $user = Auth::authenticate($email, $password);
        if ($user === false) {
            return false;
        }

        AuditLog::log('security', 'api_login', ['email' => $email, 'device_label' => $deviceLabel], $user['contact_id'], $user['contact_id']);

        return self::issueTokenPair($user, $deviceLabel);
    }

    /**
     * Exchange a valid, unrevoked refresh token for a new token pair. Rotates
     * the refresh token (old one is revoked) and reloads roles/permissions
     * fresh from the DB, so a long-lived mobile session picks up role changes
     * without needing a full re-login.
     * @return array{access_token: string, refresh_token: string, expires_in: int, token_type: string}|false
     */
    public static function refresh(string $rawRefreshToken): array|false {
        $appDb = Database::getAppConnection();
        $hash = hash('sha256', $rawRefreshToken);

        $stmt = $appDb->prepare("SELECT id, contact_id, device_label, expires_at, revoked_at FROM tgg_api_tokens WHERE token_hash = :hash LIMIT 1");
        $stmt->execute(['hash' => $hash]);
        $row = $stmt->fetch();

        if (!$row || $row['revoked_at'] !== null || strtotime($row['expires_at']) < time()) {
            return false;
        }

        $contactId = (int)$row['contact_id'];
        $settingsStmt = $appDb->prepare("SELECT role FROM tgg_member_settings WHERE contact_id = :contact_id LIMIT 1");
        $settingsStmt->execute(['contact_id' => $contactId]);
        $settingsRow = $settingsStmt->fetch();
        if (!$settingsRow) {
            return false; // account no longer has portal access
        }

        $rolesAndPermissions = Auth::loadRolesAndPermissions($contactId, $settingsRow['role']);
        $user = [
            'contact_id' => $contactId,
            'display_name' => MembershipService::getFormattedName($contactId),
            'roles' => $rolesAndPermissions['roles'],
            'role' => $rolesAndPermissions['role'],
            'permissions' => $rolesAndPermissions['permissions'],
        ];

        $appDb->prepare("UPDATE tgg_api_tokens SET revoked_at = NOW() WHERE id = :id")->execute(['id' => $row['id']]);

        return self::issueTokenPair($user, $row['device_label']);
    }

    /**
     * Revoke a refresh token (mobile "log out" / "log out this device").
     */
    public static function revoke(string $rawRefreshToken): void {
        $appDb = Database::getAppConnection();
        $hash = hash('sha256', $rawRefreshToken);
        $appDb->prepare("UPDATE tgg_api_tokens SET revoked_at = NOW() WHERE token_hash = :hash AND revoked_at IS NULL")
            ->execute(['hash' => $hash]);
    }

    /**
     * Verify an access token's signature and expiry, and return the embedded
     * user context. Purely computational -- no DB access, so it's cheap to
     * call on every API request.
     * @return array{contact_id: int, display_name: string, roles: array, role: ?string, permissions: array}|false
     */
    public static function verifyAccessToken(string $token): array|false {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            return false;
        }
        [$payloadB64, $sigB64] = $parts;

        if (!hash_equals(self::sign($payloadB64), $sigB64)) {
            return false;
        }

        $payloadJson = self::base64UrlDecode($payloadB64);
        $payload = $payloadJson !== false ? json_decode($payloadJson, true) : null;
        if (!is_array($payload) || !isset($payload['exp'], $payload['user']) || $payload['exp'] < time()) {
            return false;
        }

        return $payload['user'];
    }

    /**
     * Resolve the current request's Bearer access token to a user context, or
     * respond 401 and exit -- the API-endpoint equivalent of Auth::requireAuth().
     */
    public static function requireAuth(): array {
        $header = self::authorizationHeader();
        if (!preg_match('/^Bearer\s+(\S+)$/i', $header, $matches)) {
            json_response(['error' => 'Missing or malformed Authorization header'], 401);
        }

        $user = self::verifyAccessToken($matches[1]);
        if ($user === false) {
            json_response(['error' => 'Invalid or expired access token'], 401);
        }

        return $user;
    }

    /**
     * Require that the current Bearer token's user carries $permission, in
     * addition to being authenticated -- the API equivalent of
     * Auth::requirePermission().
     */
    public static function requirePermission(string $permission): array {
        $user = self::requireAuth();
        $permissions = $user['permissions'] ?? [];
        if (!in_array('all', $permissions, true) && !in_array($permission, $permissions, true)) {
            json_response(['error' => 'Forbidden'], 403);
        }
        return $user;
    }

    /**
     * PHP under Apache's mod_php strips the Authorization header from
     * $_SERVER by default (it's reserved for Basic/Digest auth handling), so
     * $_SERVER['HTTP_AUTHORIZATION'] is unreliable -- getallheaders() (built
     * into PHP for every SAPI since 7.3) is the portable way to read it.
     *
     * Under FPM/CGI even getallheaders() comes up empty, because the header is
     * dropped before PHP is invoked at all. The .htaccess in
     * public_html/member/api/ puts it back, and depending on which mechanism
     * fires there it lands in HTTP_AUTHORIZATION or, when a rewrite was
     * involved, REDIRECT_HTTP_AUTHORIZATION -- so check both before giving up.
     */
    private static function authorizationHeader(): string {
        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
            if (!empty($_SERVER[$key])) {
                return $_SERVER[$key];
            }
        }
        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                if (strcasecmp($name, 'Authorization') === 0) {
                    return $value;
                }
            }
        }
        return '';
    }

    private static function issueTokenPair(array $user, ?string $deviceLabel): array {
        $accessToken = self::buildAccessToken($user);

        $rawRefreshToken = bin2hex(random_bytes(32));
        $appDb = Database::getAppConnection();
        $appDb->prepare("
            INSERT INTO tgg_api_tokens (contact_id, token_hash, device_label, expires_at)
            VALUES (:contact_id, :token_hash, :device_label, :expires_at)
        ")->execute([
            'contact_id' => $user['contact_id'],
            'token_hash' => hash('sha256', $rawRefreshToken),
            'device_label' => $deviceLabel,
            'expires_at' => date('Y-m-d H:i:s', strtotime(self::REFRESH_TOKEN_TTL)),
        ]);

        return [
            'access_token' => $accessToken,
            'refresh_token' => $rawRefreshToken,
            'expires_in' => self::ACCESS_TOKEN_TTL,
            'token_type' => 'Bearer',
            // Convenience copy of the access token's own claims, so clients
            // don't need to decode the token to show who's logged in / what
            // they can do -- keeps the client decoupled from the token format.
            'user' => [
                'contact_id' => $user['contact_id'],
                'display_name' => $user['display_name'],
                'roles' => $user['roles'],
                'permissions' => $user['permissions'],
            ],
        ];
    }

    private static function buildAccessToken(array $user): string {
        $payload = [
            'user' => [
                'contact_id' => $user['contact_id'],
                'display_name' => $user['display_name'],
                'roles' => $user['roles'],
                'role' => $user['role'],
                'permissions' => $user['permissions'],
            ],
            'exp' => time() + self::ACCESS_TOKEN_TTL,
        ];
        $payloadB64 = self::base64UrlEncode(json_encode($payload));
        return $payloadB64 . '.' . self::sign($payloadB64);
    }

    private static function sign(string $payloadB64): string {
        $secret = $_ENV['API_TOKEN_SECRET'] ?? '';
        if (empty($secret)) {
            throw new Exception('API_TOKEN_SECRET is not configured');
        }
        return self::base64UrlEncode(hash_hmac('sha256', $payloadB64, $secret, true));
    }

    private static function base64UrlEncode(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string|false {
        $data = strtr($data, '-_', '+/');
        $padLength = 4 - (strlen($data) % 4);
        if ($padLength < 4) {
            $data .= str_repeat('=', $padLength);
        }
        return base64_decode($data, true);
    }
}
