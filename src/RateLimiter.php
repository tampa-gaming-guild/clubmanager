<?php
namespace App;

/**
 * Fixed-window per-key attempt limiter backed by tgg_api_rate_limits.
 * Used to throttle the public API token endpoint by IP, since (unlike the
 * web login form) it's reachable directly by any HTTP client and the
 * per-account lockout in tgg_member_settings isn't sufficient on its own.
 */
class RateLimiter {
    /**
     * Record an attempt against $bucketKey and report whether it's allowed.
     * The window resets (count starts over at 1) once $windowSeconds has
     * elapsed since it started, rather than sliding.
     *
     * @return bool true if this attempt is within the limit, false if the caller should be rejected
     */
    public static function attempt(string $bucketKey, int $maxAttempts, int $windowSeconds): bool {
        $appDb = Database::getAppConnection();

        $stmt = $appDb->prepare("SELECT window_started_at, attempts FROM tgg_api_rate_limits WHERE bucket_key = :key LIMIT 1");
        $stmt->execute(['key' => $bucketKey]);
        $row = $stmt->fetch();

        $now = time();
        if (!$row || strtotime($row['window_started_at']) <= $now - $windowSeconds) {
            // New or expired window: reset to 1.
            $appDb->prepare("
                INSERT INTO tgg_api_rate_limits (bucket_key, window_started_at, attempts)
                VALUES (:key, :started, 1)
                ON DUPLICATE KEY UPDATE window_started_at = :started2, attempts = 1
            ")->execute(['key' => $bucketKey, 'started' => date('Y-m-d H:i:s', $now), 'started2' => date('Y-m-d H:i:s', $now)]);
            return true;
        }

        if ((int)$row['attempts'] >= $maxAttempts) {
            return false;
        }

        $appDb->prepare("UPDATE tgg_api_rate_limits SET attempts = attempts + 1 WHERE bucket_key = :key")
            ->execute(['key' => $bucketKey]);
        return true;
    }
}
