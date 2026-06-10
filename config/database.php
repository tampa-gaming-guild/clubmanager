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
    die("Database Initialization Error: " . $e->getMessage());
}
