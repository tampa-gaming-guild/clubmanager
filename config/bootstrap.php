<?php
/**
 * Application Bootstrap
 * Initializes environment, autoloader, security headers, sessions, and core helpers.
 */

// 1. Error Reporting Configuration (Production should log to file, not display)
// Configured dynamically below after loading environment variables.

// 2. Load Environment Variables from .env
function loadEnv($dir) {
    $path = $dir . '/.env';
    if (!file_exists($path)) {
        return;
    }
    
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        // Skip comments
        if (empty($line) || strpos($line, '#') === 0) {
            continue;
        }
        
        // Split by first equals sign
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $key = trim($parts[0]);
            $val = trim($parts[1]);
            
            // Remove wrapping quotes if present
            if (preg_match('/^"(.*)"$/', $val, $matches) || preg_match('/^\'(.*)\'$/', $val, $matches)) {
                $val = $matches[1];
            }
            
            if (!array_key_exists($key, $_SERVER) && !array_key_exists($key, $_ENV)) {
                putenv("{$key}={$val}");
                $_ENV[$key] = $val;
                $_SERVER[$key] = $val;
            }
        }
    }
}
loadEnv(dirname(__DIR__));

// Configure Error Reporting based on Environment
if (($_ENV['APP_ENV'] ?? 'production') !== 'development') {
    ini_set('display_errors', 0);
    error_reporting(0);
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
}

// Load Composer Autoloader if it exists
if (file_exists(dirname(__DIR__) . '/vendor/autoload.php')) {
    require_once dirname(__DIR__) . '/vendor/autoload.php';
}

// 3. PSR-4 Autoloader
spl_autoload_register(function ($class) {
    $prefix = 'App\\';
    $base_dir = dirname(__DIR__) . '/src/';
    
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    
    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
    
    if (file_exists($file)) {
        require $file;
    }
});

// 4. Security Headers
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");

// Detect proxy-terminated HTTPS (e.g., from Caddy reverse proxy)
if ((isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
    (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on')) {
    $_SERVER['HTTPS'] = 'on';
}

// 5. Secure Session Setup
if (session_status() === PHP_SESSION_NONE) {
    // Dynamically determine cookie path from BASE_URL to support subdirectories and localhost root
    $cookiePath = '/';
    if (isset($_ENV['BASE_URL'])) {
        $parsedUrl = parse_url($_ENV['BASE_URL']);
        $cookiePath = isset($parsedUrl['path']) ? rtrim($parsedUrl['path'], '/') . '/' : '/';
    }

    // Session Cookie Settings
    $cookieParams = [
        'lifetime' => 86400, // 1 day
        'path' => $cookiePath, // Dynamic path matching BASE_URL
        'domain' => '', // Empty lets browser default to host (ignoring port number)
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on', // HTTPS only when enabled
        'httponly' => true, // Inaccessible to JavaScript
        'samesite' => 'Lax' // Lax allows session persistence across external redirects (e.g. Stripe Checkout)
    ];
    
    session_set_cookie_params($cookieParams);
    session_name('TGG_SESSID');
    session_start();
}

// 6. Security Helper Functions

/**
 * Generate or retrieve CSRF token
 */
function get_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify CSRF token
 */
function verify_csrf_token($token) {
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Escapes HTML output (XSS Prevention)
 */
function e($string) {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Helper to check role
 */
function has_role($role) {
    return isset($_SESSION['user']) && $_SESSION['user']['role'] === $role;
}

/**
 * Redirect Helper
 */
function redirect($path) {
    $baseUrl = rtrim($_ENV['BASE_URL'] ?? 'http://localhost/member', '/');
    $path = ltrim($path, '/');
    header("Location: {$baseUrl}/{$path}");
    exit;
}

/**
 * Send JSON Response
 */
function json_response($data, $statusCode = 200) {
    header("Content-Type: application/json; charset=utf-8");
    http_response_code($statusCode);
    echo json_encode($data);
    exit;
}

/**
 * Safe error message formatter for user-facing exception messages.
 * Prevents database details, file paths, and SQL queries from being exposed in production.
 */
function safe_err($prefix, Exception $e) {
    if (($_ENV['APP_ENV'] ?? 'production') === 'development') {
        return $prefix . $e->getMessage();
    }
    return rtrim($prefix, ': ') . ". An unexpected error occurred. Please try again or contact support.";
}
