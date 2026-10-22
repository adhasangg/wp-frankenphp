<?php
/**
 * Custom wp-config.php for WordPress with FrankenPHP on Coolify
 * Fully supports .env loading and Coolify reverse proxy / SSL termination.
 */

// -----------------------------------------------------------------------------
// 1. Lightweight Built-in .env Loader (Zero external dependencies)
// -----------------------------------------------------------------------------
if (!function_exists('load_custom_dotenv')) {
    function load_custom_dotenv($filePath) {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            return;
        }

        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            // Skip empty lines and comments
            if ($line === '' || strpos($line, '#') === 0) {
                continue;
            }

            if (strpos($line, '=') !== false) {
                list($name, $value) = explode('=', $line, 2);
                $name = trim($name);
                $value = trim($value);

                // Strip quotes if enclosed
                if (strlen($value) >= 2 && (
                    ($value[0] === '"' && $value[strlen($value) - 1] === '"') ||
                    ($value[0] === "'" && $value[strlen($value) - 1] === "'")
                )) {
                    $value = substr($value, 1, -1);
                }

                // Unescape special characters
                $value = str_replace(['\n', '\r', '\t'], ["\n", "\r", "\t"], $value);

                // Set if not already defined in environment (Docker env vars take precedence)
                if (getenv($name) === false && !isset($_ENV[$name])) {
                    putenv("{$name}={$value}");
                    $_ENV[$name] = $value;
                    $_SERVER[$name] = $value;
                }
            }
        }
    }
}

// Check and load .env files
load_custom_dotenv(__DIR__ . '/.env');
load_custom_dotenv(dirname(__DIR__) . '/.env');

// Helper function to read environment variables with fallback
if (!function_exists('env')) {
    function env($key, $default = null) {
        $value = getenv($key);
        if ($value === false) {
            $value = $_ENV[$key] ?? $_SERVER[$key] ?? $default;
        }

        if ($value === null || is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            switch (strtolower(trim($value))) {
                case 'true':
                case '(true)':
                    return true;
                case 'false':
                case '(false)':
                    return false;
                case 'empty':
                case '(empty)':
                    return '';
                case 'null':
                case '(null)':
                    return null;
            }
        }

        return $value;
    }
}

// -----------------------------------------------------------------------------
// 2. Coolify / Traefik Reverse Proxy & SSL Termination Compatibility
// -----------------------------------------------------------------------------
// When Coolify terminates SSL, it forwards X-Forwarded-Proto: https
if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
    $_SERVER['HTTPS'] = 'on';
} elseif (isset($_SERVER['HTTP_CF_VISITOR'])) {
    $visitor = json_decode($_SERVER['HTTP_CF_VISITOR'], true);
    if (isset($visitor['scheme']) && $visitor['scheme'] === 'https') {
        $_SERVER['HTTPS'] = 'on';
    }
}

if (!empty($_SERVER['HTTP_X_FORWARDED_HOST'])) {
    $_SERVER['HTTP_HOST'] = $_SERVER['HTTP_X_FORWARDED_HOST'];
}

// -----------------------------------------------------------------------------
// 3. Site URLs (Dynamic Fallback or .env Override)
// -----------------------------------------------------------------------------
$env_home = env('WP_HOME');
$env_siteurl = env('WP_SITEURL');

if ($env_home) {
    define('WP_HOME', rtrim($env_home, '/'));
    define('WP_SITEURL', rtrim($env_siteurl ?: $env_home, '/'));
} elseif (isset($_SERVER['HTTP_HOST'])) {
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $auto_url = $proto . '://' . $_SERVER['HTTP_HOST'];
    define('WP_HOME', $auto_url);
    define('WP_SITEURL', $auto_url);
}

// -----------------------------------------------------------------------------
// 4. Database Settings
// -----------------------------------------------------------------------------
define('DB_NAME', env('WORDPRESS_DB_NAME', env('DB_NAME', 'wordpress')));
define('DB_USER', env('WORDPRESS_DB_USER', env('DB_USER', 'wordpress')));
define('DB_PASSWORD', env('WORDPRESS_DB_PASSWORD', env('DB_PASSWORD', 'wordpress')));
define('DB_HOST', env('WORDPRESS_DB_HOST', env('DB_HOST', 'mysql:3306')));
define('DB_CHARSET', env('WORDPRESS_DB_CHARSET', env('DB_CHARSET', 'utf8mb4')));
define('DB_COLLATE', env('WORDPRESS_DB_COLLATE', env('DB_COLLATE', '')));

$table_prefix = env('WORDPRESS_TABLE_PREFIX', env('TABLE_PREFIX', 'wp_'));

// -----------------------------------------------------------------------------
// 5. Authentication Unique Keys and Salts
// -----------------------------------------------------------------------------
$salts = [
    'AUTH_KEY',
    'SECURE_AUTH_KEY',
    'LOGGED_IN_KEY',
    'NONCE_KEY',
    'AUTH_SALT',
    'SECURE_AUTH_SALT',
    'LOGGED_IN_SALT',
    'NONCE_SALT',
];

foreach ($salts as $salt) {
    $val = env($salt);
    if (!$val) {
        // Safe deterministic fallback based on DB name and host if not supplied
        $val = hash('sha256', 'frankenphp_wp_' . $salt . DB_NAME . DB_HOST);
    }
    define($salt, $val);
}

// -----------------------------------------------------------------------------
// 6. Redis Object Cache (Supported via php-redis & Redis Object Cache plugin)
// -----------------------------------------------------------------------------
$redis_host = env('WP_REDIS_HOST', env('REDIS_HOST'));
if ($redis_host) {
    define('WP_REDIS_HOST', $redis_host);
    define('WP_REDIS_PORT', (int) env('WP_REDIS_PORT', env('REDIS_PORT', 6379)));
    
    $redis_password = env('WP_REDIS_PASSWORD', env('REDIS_PASSWORD'));
    if ($redis_password) {
        define('WP_REDIS_PASSWORD', $redis_password);
    }
    
    $redis_database = env('WP_REDIS_DATABASE', env('REDIS_DATABASE'));
    if ($redis_database !== null) {
        define('WP_REDIS_DATABASE', (int) $redis_database);
    }

    // Shared Redis isolation (prevents cache collisions when multiple sites share one Redis)
    $redis_prefix = env('WP_REDIS_PREFIX', DB_NAME . '_');
    define('WP_REDIS_PREFIX', $redis_prefix);

    $cache_key_salt = env('WP_CACHE_KEY_SALT', DB_NAME . '_');
    define('WP_CACHE_KEY_SALT', $cache_key_salt);

    $redis_timeout = env('WP_REDIS_TIMEOUT', 1.0);
    define('WP_REDIS_TIMEOUT', (float) $redis_timeout);

    $redis_read_timeout = env('WP_REDIS_READ_TIMEOUT', 1.0);
    define('WP_REDIS_READ_TIMEOUT', (float) $redis_read_timeout);
}

// -----------------------------------------------------------------------------
// 7. Debugging & Performance
// -----------------------------------------------------------------------------
define('WP_DEBUG', (bool) env('WP_DEBUG', false));
define('WP_DEBUG_LOG', (bool) env('WP_DEBUG_LOG', false));
define('WP_DEBUG_DISPLAY', (bool) env('WP_DEBUG_DISPLAY', false));
define('SCRIPT_DEBUG', (bool) env('SCRIPT_DEBUG', false));
define('SAVEQUERIES', (bool) env('SAVEQUERIES', false));

// Direct filesystem write for themes/plugins updates without FTP credentials prompt
if (!defined('FS_METHOD')) {
    define('FS_METHOD', env('FS_METHOD', 'direct'));
}

// Temporary directory for streaming package downloads & unzipping (plugins/themes/core)
if (!defined('WP_TEMP_DIR')) {
    define('WP_TEMP_DIR', env('WP_TEMP_DIR', dirname(__FILE__) . '/wp-content/upgrade'));
}

// Automatic updates control (options: 'minor', true, false)
if (!defined('WP_AUTO_UPDATE_CORE')) {
    define('WP_AUTO_UPDATE_CORE', env('WP_AUTO_UPDATE_CORE', 'minor'));
}
if (!defined('AUTOMATIC_UPDATER_DISABLED')) {
    define('AUTOMATIC_UPDATER_DISABLED', (bool) env('AUTOMATIC_UPDATER_DISABLED', false));
}

// Disable theme and plugin file editor in admin for security
define('DISALLOW_FILE_EDIT', (bool) env('DISALLOW_FILE_EDIT', true));

// Autosave interval and revisions control
define('AUTOSAVE_INTERVAL', (int) env('AUTOSAVE_INTERVAL', 120)); // in seconds
define('WP_POST_REVISIONS', env('WP_POST_REVISIONS', 5));
define('EMPTY_TRASH_DAYS', (int) env('EMPTY_TRASH_DAYS', 30));

// Memory limits for WordPress
define('WP_MEMORY_LIMIT', env('WP_MEMORY_LIMIT', '256M'));
define('WP_MAX_MEMORY_LIMIT', env('WP_MAX_MEMORY_LIMIT', '512M'));

// -----------------------------------------------------------------------------
// 8. Bootstrap WordPress
// -----------------------------------------------------------------------------
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

require_once ABSPATH . 'wp-settings.php';
