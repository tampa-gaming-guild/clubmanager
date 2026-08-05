<?php
/**
 * Retired: Host Portal functionality merged into index.php (Hosting View).
 * Kept as a redirect so old bookmarks/links don't 404.
 */
require_once (function() {
    $dir = dirname(__DIR__);
    if (file_exists($dir . '/.env') && $lines = @file($dir . '/.env')) {
        foreach ($lines as $line) {
            if (preg_match('/^\s*BOOTSTRAP_PATH\s*=\s*["\']?(.*?)["\']?\s*$/', $line, $m)) {
                return $m[1];
            }
        }
    }
    return $dir . '/config/bootstrap.php';
})();

redirect('index.php');
