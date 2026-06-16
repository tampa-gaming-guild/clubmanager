<?php
/**
 * Database Config Wrapper
 * Requires bootstrap and returns Database helper connections.
 */
require_once __DIR__ . '/bootstrap.php';

use App\Database;

try {
    $db = Database::getAppConnection();
    $civiDb = Database::getCiviConnection();
} catch (Exception $e) {
    error_log("Database Initialization Error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    if (($_ENV['APP_ENV'] ?? 'production') === 'development') {
        die("Database Initialization Error: " . $e->getMessage());
    } else {
        die("Database Initialization Error. An unexpected error occurred. Please try again or contact support.");
    }
}
