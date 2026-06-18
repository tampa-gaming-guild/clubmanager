<?php
namespace App;

use PDO;
use PDOException;
use Exception;

/**
 * Database Connection Manager
 * Handles PDO connections to local application database and WordPress CiviCRM database.
 */
class Database {
    private static ?PDO $appPdo = null;
    private static ?PDO $civiPdo = null;

    /**
     * Get local application database connection
     * @return PDO
     * @throws Exception
     */
    public static function getAppConnection(): PDO {
        if (self::$appPdo === null) {
            $host = $_ENV['DB_HOST'] ?? '127.0.0.1';
            $port = $_ENV['DB_PORT'] ?? '3306';
            $db   = $_ENV['DB_NAME'] ?? 'tgg_members';
            $user = $_ENV['DB_USER'] ?? 'root';
            $pass = $_ENV['DB_PASS'] ?? '';
            $charset = 'utf8mb4';

            $dsn = "mysql:host={$host};port={$port};dbname={$db};charset={$charset}";
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];

            try {
                self::$appPdo = new PDO($dsn, $user, $pass, $options);
                self::$appPdo->exec("SET time_zone = '" . self::getEasternOffset() . "'");
            } catch (PDOException $e) {
                throw new Exception("Local Database connection failed: " . $e->getMessage());
            }
        }
        return self::$appPdo;
    }

    /**
     * Current UTC offset for the club's US Eastern timezone (handles DST), e.g. "-04:00".
     * Computed via PHP's tzdata rather than relying on MySQL's named timezone tables being loaded.
     */
    private static function getEasternOffset(): string {
        $now = new \DateTime('now', new \DateTimeZone('America/New_York'));
        return $now->format('P');
    }

    /**
     * Get CiviCRM database connection
     * @return PDO
     * @throws Exception
     */
    public static function getCiviConnection(): PDO {
        if (self::$civiPdo === null) {
            $host = $_ENV['CIVI_DB_HOST'] ?? '127.0.0.1';
            $port = $_ENV['CIVI_DB_PORT'] ?? '3306';
            $db   = $_ENV['CIVI_DB_NAME'] ?? 'wordpress_civicrm';
            $user = $_ENV['CIVI_DB_USER'] ?? 'root';
            $pass = $_ENV['CIVI_DB_PASS'] ?? '';
            $charset = 'utf8mb4';

            $dsn = "mysql:host={$host};port={$port};dbname={$db};charset={$charset}";
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];

            try {
                self::$civiPdo = new PDO($dsn, $user, $pass, $options);
                self::$civiPdo->exec("SET time_zone = '" . self::getEasternOffset() . "'");
            } catch (PDOException $e) {
                throw new Exception("CiviCRM Database connection failed: " . $e->getMessage());
            }
        }
        return self::$civiPdo;
    }
}
