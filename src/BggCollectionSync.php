<?php
namespace App;

use Exception;

/**
 * BoardGameGeek collection write sync (UNOFFICIAL -- there is no public write
 * API). Verified by live browser recon 2026-07-21 (Chrome devtools against
 * the real TampaGamingGuild account): BGG's modern frontend is a REST-ish
 * JSON API under boardgamegeek.com/api/, not the older geekcollection.php
 * form-POST endpoint community write-ups describe -- that older endpoint is
 * behind a Cloudflare bot challenge that a plain curl client cannot pass.
 * These endpoints worked from curl once a realistic desktop User-Agent and a
 * same-site Referer were added (see USER_AGENT/REFERER below); no explicit
 * CSRF token is required beyond the session cookies.
 *
 *   GET    /api/collections?objectid={id}&objecttype=thing&userid={userid}
 *          -> {"items":[{...,"collid":"...", "status":{...}, "textfield":{"comment":{"value":...}}}]}
 *          (empty items array if the game isn't in the collection yet)
 *   POST   /api/collectionitem
 *          body: {"item":{"collid":0,"pp_currency":"USD","cv_currency":"USD",
 *                 "objecttype":"thing","objectid":"{id}","status":{"own":true},
 *                 "objectname":"{name}","acquisitiondate":null,"invdate":null}}
 *          -- creates a new collection entry, does not accept a comment
 *   PUT    /api/collectionitem/{collid}
 *          body: {"item": <the full item fetched from the GET above, with
 *                 status.own and textfield.comment.value mutated>}
 *          -- BGG's own frontend always fetches-then-PUTs the full
 *          representation rather than sending a partial diff; a synthetic
 *          minimal body was not verified and is not used here.
 *   DELETE /api/collectionitem/{collid}
 *          -- removes the item entirely (not just unsets "own")
 *
 * Defensive by design: every public method returns ['success' => bool, 'message'
 * => ?string] rather than throwing for any BGG-side failure (bad login, changed
 * endpoint, network error) so a push failure can never block or corrupt the
 * caller's local database write. Only a missing BGG_USERNAME/BGG_PASSWORD
 * configuration throws, matching StripeHelper's convention.
 */
class BggCollectionSync {

    private const LOGIN_URL = 'https://boardgamegeek.com/login/api/v1';
    private const API_BASE = 'https://boardgamegeek.com/api';
    // A real desktop browser UA + a same-site Referer, matching what BGG's own
    // frontend sends -- these endpoints 403'd via Cloudflare without them, and
    // worked once added (verified live 2026-07-21).
    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';
    private const REFERER = 'https://boardgamegeek.com/collection/user/TampaGamingGuild';

    /**
     * Add or update a game in the live BGG collection: marks it owned and
     * sets the public comment field, creating the collection entry first if
     * it doesn't already exist.
     * @param array{bgg_id: int, name: string, comment: ?string} $game
     * @return array{success: bool, message: ?string}
     */
    public static function pushGame(array $game): array {
        return self::withSession(function (string $cookies) use ($game) {
            $userId = self::resolveUserId();
            $existing = self::findCollectionItem($cookies, $userId, $game['bgg_id']);

            if ($existing === null) {
                self::apiRequest($cookies, 'POST', '/collectionitem', [
                    'item' => [
                        'collid' => 0,
                        'pp_currency' => 'USD',
                        'cv_currency' => 'USD',
                        'objecttype' => 'thing',
                        'objectid' => (string)$game['bgg_id'],
                        'status' => ['own' => true],
                        'objectname' => $game['name'],
                        'acquisitiondate' => null,
                        'invdate' => null,
                    ],
                ]);
                // The create response doesn't echo the new collid, so look the
                // item back up to get it -- needed to set the comment below.
                $existing = self::findCollectionItem($cookies, $userId, $game['bgg_id']);
                if ($existing === null) {
                    throw new Exception("item created but could not be found afterward");
                }
            }

            $existing['status']['own'] = true;
            if (!isset($existing['textfield']) || !is_array($existing['textfield'])) {
                $existing['textfield'] = [];
            }
            $existing['textfield']['comment'] = ['value' => $game['comment'] ?? null];

            self::apiRequest($cookies, 'PUT', '/collectionitem/' . $existing['collid'], ['item' => $existing]);

            return ['success' => true, 'message' => null];
        });
    }

    /**
     * Remove a game from the live BGG collection entirely (not just unmark
     * "own" -- matches the "Delete from Collection" action in BGG's own UI).
     * A game not currently in the collection is treated as already-removed.
     * @param array{bgg_id: int} $game
     * @return array{success: bool, message: ?string}
     */
    public static function removeGame(array $game): array {
        return self::withSession(function (string $cookies) use ($game) {
            $userId = self::resolveUserId();
            $existing = self::findCollectionItem($cookies, $userId, $game['bgg_id']);
            if ($existing === null) {
                return ['success' => true, 'message' => null];
            }

            self::apiRequest($cookies, 'DELETE', '/collectionitem/' . $existing['collid'], null);
            return ['success' => true, 'message' => null];
        });
    }

    /**
     * Look up the current collection entry for a game, if any.
     * @return array|null The decoded item object (with 'collid', 'status', 'textfield', ...), or null if not in the collection
     * @throws Exception
     */
    private static function findCollectionItem(string $cookies, int $userId, int $bggId): ?array {
        $data = self::apiRequest($cookies, 'GET', "/collections?objectid={$bggId}&objecttype=thing&userid={$userId}", null);
        return $data['items'][0] ?? null;
    }

    /** BGG's numeric user id for the club account, resolved via the official read API (Bearer-token authenticated, not session-based). */
    private static function resolveUserId(): int {
        $username = $_ENV['BGG_USERNAME'] ?? '';
        return BggClient::resolveUserId($username);
    }

    /**
     * Log in, run $callback with the session cookie header, and normalize any
     * exception into the ['success' => false, ...] contract. Re-authenticates
     * on every call rather than persisting cookies, to avoid stale-session
     * failures -- this is a low-frequency operation (one push per librarian
     * edit), not worth the complexity of session reuse/expiry tracking.
     */
    private static function withSession(callable $callback): array {
        $username = $_ENV['BGG_USERNAME'] ?? '';
        $password = $_ENV['BGG_PASSWORD'] ?? '';
        if (empty($username) || empty($password)) {
            throw new Exception("BGG_USERNAME/BGG_PASSWORD are not configured in environment.");
        }

        try {
            $cookies = self::login($username, $password);
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'BGG login failed: ' . $e->getMessage()];
        }

        try {
            return $callback($cookies);
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * @return string Cookie header value to attach to subsequent requests
     * @throws Exception
     */
    private static function login(string $username, string $password): string {
        $ch = curl_init(self::LOGIN_URL);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'credentials' => ['username' => $username, 'password' => $password],
        ]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'User-Agent: ' . self::USER_AGENT,
            'Referer: ' . self::REFERER,
        ]);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new Exception("login request failed: " . $curlErr);
        }
        // 204 (No Content) is the observed success response for this endpoint --
        // it sets session cookies and returns no body. The real success signal
        // is the presence of session cookies below, not the status code alone.
        if ($httpCode !== 200 && $httpCode !== 204) {
            $body = trim(substr($response, $headerSize));
            throw new Exception("login returned HTTP {$httpCode}" . ($body !== '' ? ': ' . substr($body, 0, 300) : ''));
        }

        $headers = substr($response, 0, $headerSize);
        $cookies = self::extractCookies($headers);
        if (empty($cookies)) {
            throw new Exception("login response contained no session cookies");
        }

        return $cookies;
    }

    /** Parse Set-Cookie response headers into a single "k=v; k2=v2" Cookie header value. */
    private static function extractCookies(string $headers): string {
        $pairs = [];
        foreach (explode("\r\n", $headers) as $line) {
            if (stripos($line, 'Set-Cookie:') !== 0) {
                continue;
            }
            $cookieStr = trim(substr($line, strlen('Set-Cookie:')));
            $firstPart = explode(';', $cookieStr, 2)[0];
            if (strpos($firstPart, '=') !== false) {
                $pairs[] = trim($firstPart);
            }
        }
        return implode('; ', $pairs);
    }

    /**
     * GET/POST/PUT/DELETE against BGG's api.boardgamegeek.com-style JSON API.
     * @return array Decoded JSON response (empty array for a bodyless DELETE response)
     * @throws Exception
     */
    private static function apiRequest(string $cookies, string $method, string $path, ?array $jsonBody): array {
        $ch = curl_init(self::API_BASE . $path);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

        $headers = [
            'Accept: application/json, text/plain, */*',
            'Cookie: ' . $cookies,
            'User-Agent: ' . self::USER_AGENT,
            'Referer: ' . self::REFERER,
        ];
        if ($jsonBody !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($jsonBody, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $headers[] = 'Content-Type: application/json;charset=UTF-8';
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new Exception("{$method} {$path} request failed: " . $curlErr);
        }

        // A 200 with an HTML login form embedded means the session cookie
        // didn't actually authenticate -- these endpoints don't reliably
        // signal that via HTTP status alone.
        if (stripos($response, '<form') !== false && stripos($response, 'password') !== false) {
            throw new Exception("{$method} {$path} response looks like a login page -- session not authenticated");
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new Exception("{$method} {$path} returned HTTP {$httpCode}: " . substr($response, 0, 300));
        }

        if (trim($response) === '') {
            return [];
        }
        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new Exception("{$method} {$path} returned unparseable JSON: " . substr($response, 0, 300));
        }
        return $decoded;
    }
}
