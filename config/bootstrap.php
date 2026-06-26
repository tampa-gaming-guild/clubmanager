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

// The club operates in the US Eastern timezone; all date/time logic (PHP and DB) should assume it.
date_default_timezone_set('America/New_York');

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
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://js.stripe.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; frame-src 'self' https://js.stripe.com; img-src 'self' data:;");
header("Strict-Transport-Security: max-age=31536000; includeSubDomains");

// Detect proxy-terminated HTTPS (e.g., from Caddy reverse proxy)
if ((isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
    (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on')) {
    $_SERVER['HTTPS'] = 'on';
}

// 5. Secure Session Setup
if (session_status() === PHP_SESSION_NONE && php_sapi_name() !== 'cli') {
    // Dynamically determine cookie path from BASE_URL to support subdirectories and localhost root
    $cookiePath = '/';
    if (isset($_ENV['BASE_URL'])) {
        $parsedUrl = parse_url($_ENV['BASE_URL']);
        $cookiePath = isset($parsedUrl['path']) ? rtrim($parsedUrl['path'], '/') . '/' : '/';
    }

    // Dedicated session directory, isolated from other apps on the same server
    // so their GC (which may use a shorter maxlifetime) can't purge our sessions.
    $sessionSavePath = $_ENV['SESSION_SAVE_PATH'] ?? getenv('SESSION_SAVE_PATH') ?: null;
    if ($sessionSavePath) {
        ini_set('session.save_path', $sessionSavePath);
    }

    // Match server-side session GC lifetime to the cookie lifetime below.
    // PHP's default (1440s / 24min) is otherwise unrelated to the cookie's
    // expiration and silently destroys sessions long before the cookie does.
    ini_set('session.gc_maxlifetime', '2592000');

    // Session Cookie Settings
    $cookieParams = [
        'lifetime' => 2592000, // 30 days
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

/** Strip all non-digit characters; drop a leading country code '1' from 11-digit US numbers. */
function normalize_phone(string $phone): string {
    $digits = preg_replace('/\D/', '', $phone);
    if (strlen($digits) === 11 && $digits[0] === '1') {
        $digits = substr($digits, 1);
    }
    return $digits;
}

/** Format a 10-digit string as (NXX) NXX-XXXX; return raw value for any other length. */
function format_phone(string $digits): string {
    if (strlen($digits) === 10) {
        return '(' . substr($digits, 0, 3) . ') ' . substr($digits, 3, 3) . '-' . substr($digits, 6);
    }
    return $digits;
}

/**
 * Cache-busting query string for a static asset, based on its last-modified time.
 * $relativePath is relative to public_html/member/ regardless of the URL prefix used to reach it.
 */
function asset_version(string $relativePath): string {
    $fullPath = dirname(__DIR__) . '/public_html/member/' . $relativePath;
    $mtime = file_exists($fullPath) ? filemtime($fullPath) : time();
    return '?v=' . $mtime;
}

/**
 * Helper to check role
 */
function has_role($role) {
    if (!isset($_SESSION['user'])) {
        return false;
    }
    $userRoles = $_SESSION['user']['roles'] ?? [];
    if (empty($userRoles) && isset($_SESSION['user']['role'])) {
        $userRoles = [$_SESSION['user']['role']];
    }
    if ($role === 'admin') {
        return in_array('admin', $userRoles, true) || in_array('superadmin', $userRoles, true);
    }
    return in_array($role, $userRoles, true);
}

/**
 * Helper to check permission
 */
function has_permission($permission) {
    if (!isset($_SESSION['user'])) {
        return false;
    }
    $userPermissions = $_SESSION['user']['permissions'] ?? [];
    if (in_array('all', $userPermissions, true)) {
        return true;
    }
    return in_array($permission, $userPermissions, true);
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
 * Validate password complexity based on modern security standards (length >= 10, uppercase, lowercase, digit, special char).
 */
function is_password_complex(string $password, &$error): bool {
    if (strlen($password) < 10) {
        $error = "Password must be at least 10 characters long.";
        return false;
    }
    if (!preg_match('/[A-Z]/', $password)) {
        $error = "Password must contain at least one uppercase letter.";
        return false;
    }
    if (!preg_match('/[a-z]/', $password)) {
        $error = "Password must contain at least one lowercase letter.";
        return false;
    }
    if (!preg_match('/[0-9]/', $password)) {
        $error = "Password must contain at least one number.";
        return false;
    }
    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        $error = "Password must contain at least one special character (e.g., !@#$%^&*).";
        return false;
    }
    
    // Check against common dictionary words or patterns
    $lowercasePassword = strtolower($password);
    $commonPasswords = [
        'password', '12345678', '1234567890', 'qwertyuiop', 'change_me_123', 'changeme123',
        'tampagamingguild', 'gamingguild', 'clubmanager', 'admin12345'
    ];
    foreach ($commonPasswords as $common) {
        if (strpos($lowercasePassword, $common) !== false) {
            $error = "Password cannot contain common words or easily guessable patterns.";
            return false;
        }
    }
    
    return true;
}

/**
 * Safe error message formatter for user-facing exception messages.
 * Prevents database details, file paths, and SQL queries from being exposed in production.
 */
function safe_err($prefix, Exception $e) {
    // Log exception with full detail
    error_log($prefix . $e->getMessage() . "\n" . $e->getTraceAsString());

    if ($e->getCode() === 423 || ($_ENV['APP_ENV'] ?? 'production') === 'development') {
        return $prefix . $e->getMessage();
    }
    return rtrim($prefix, ': ') . ". An unexpected error occurred. Please try again or contact support.";
}

// 7. Impersonation Banner Output Buffering
if (isset($_SESSION['impersonator'])) {
    ob_start(function($buffer) {
        $headers = headers_list();
        $isHtml = true;
        foreach ($headers as $header) {
            if (stripos($header, 'Content-Type:') === 0) {
                if (stripos($header, 'text/html') === false) {
                    $isHtml = false;
                    break;
                }
            }
        }
        
        if ($isHtml && stripos($buffer, '<body') !== false) {
            $displayName = htmlspecialchars($_SESSION['user']['display_name'] ?? 'User');
            $stopUrl = rtrim($_ENV['BASE_URL'] ?? 'http://localhost/member', '/') . '/index.php?action=stop_impersonating';
            
            $banner = '
            <div id="impersonation-banner" style="
                position: fixed;
                top: 0;
                left: 0;
                background-color: #d32f2f;
                color: #ffffff;
                font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Helvetica, Arial, sans-serif;
                font-size: 0.85rem;
                font-weight: bold;
                padding: 6px 15px;
                z-index: 999999;
                border-bottom-right-radius: 8px;
                box-shadow: 0 4px 6px rgba(0,0,0,0.15);
                display: flex;
                align-items: center;
                gap: 10px;
                pointer-events: auto;
            ">
                <span>Logged in as ' . $displayName . '</span>
                <a href="' . $stopUrl . '" style="
                    color: #ffffff;
                    text-decoration: underline;
                    font-weight: normal;
                    margin-left: 5px;
                    transition: opacity 0.2s;
                " onmouseover="this.style.opacity=0.8" onmouseout="this.style.opacity=1">Return to Admin</a>
            </div>';
            
            // Inject banner right after <body>
            $buffer = preg_replace('/(<body[^>]*>)/i', '$1' . $banner, $buffer, 1);
        }
        return $buffer;
    });
}
