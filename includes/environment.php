<?php
/**
 * MohToh Environment Configuration
 * Centralized dynamic environment & base URL resolution module.
 */

// Helper function to load .env configuration
function mt_load_env(): array
{
    static $env = null;
    if ($env !== null) {
        return $env;
    }

    $env = [];
    $envPath = __DIR__ . '/../.env';
    if (file_exists($envPath)) {
        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || $line[0] === '#') {
                continue;
            }
            $parts = explode('=', $line, 2);
            if (count($parts) === 2) {
                $key = trim($parts[0]);
                $value = trim($parts[1]);
                if (preg_match('/^"([^"]*)"$/', $value, $matches) || preg_match("/^'([^']*)'$/", $value, $matches)) {
                    $value = $matches[1];
                }
                $env[$key] = $value;
                
                // Set environment variable only if not already defined (standard dotenv behavior)
                if (!isset($_ENV[$key]) && getenv($key) === false) {
                    $_ENV[$key] = $value;
                    putenv("$key=$value");
                } elseif (isset($_ENV[$key]) && getenv($key) === false) {
                    putenv("$key=" . $_ENV[$key]);
                } elseif (!isset($_ENV[$key]) && getenv($key) !== false) {
                    $_ENV[$key] = getenv($key);
                }
            }
        }
    }
    return $env;
}

// Load env configuration variables
$env = mt_load_env();

// Define APP_ENV constant if not already defined (load from env or fallback to 'auto')
if (!defined('APP_ENV')) {
    define('APP_ENV', $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?? 'auto');
}

/**
 * Resolves the application's base URL dynamically based on priority:
 * 1. Explicit configuration value (constant APP_BASE_URL or env override)
 * 2. Environment override (APP_BASE_URL_LOCAL / STAGING / PRODUCTION)
 * 3. Automatic server detection (SSL detection, reverse proxy support, and folder path logic)
 * 4. Safe fallback
 */
function mt_get_base_url(): string
{
    static $resolved_url = null;
    if ($resolved_url !== null) {
        return $resolved_url;
    }

    // 1. Explicit configuration value
    if (defined('APP_BASE_URL_EXPLICIT')) {
        $resolved_url = rtrim(APP_BASE_URL_EXPLICIT, '/');
        return $resolved_url;
    }

    $env_base_url = $_ENV['APP_BASE_URL'] ?? getenv('APP_BASE_URL') ?? '';
    if (!empty($env_base_url)) {
        $resolved_url = rtrim($env_base_url, '/');
        return $resolved_url;
    }

    // 2. Environment override
    $app_env = strtoupper(trim(APP_ENV));
    if ($app_env === 'LOCAL' && !empty($_ENV['APP_BASE_URL_LOCAL'])) {
        $resolved_url = rtrim($_ENV['APP_BASE_URL_LOCAL'], '/');
        return $resolved_url;
    }
    if ($app_env === 'STAGING' && !empty($_ENV['APP_BASE_URL_STAGING'])) {
        $resolved_url = rtrim($_ENV['APP_BASE_URL_STAGING'], '/');
        return $resolved_url;
    }
    if ($app_env === 'PRODUCTION' && !empty($_ENV['APP_BASE_URL_PRODUCTION'])) {
        $resolved_url = rtrim($_ENV['APP_BASE_URL_PRODUCTION'], '/');
        return $resolved_url;
    }

    // 3. Automatic server detection
    // In CLI mode, we only skip detection if no host or server name is present (allowing testing harnesses to simulate requests)
    $is_cli = (php_sapi_name() === 'cli' && empty($_SERVER['HTTP_HOST']) && empty($_SERVER['SERVER_NAME']));

    if (!$is_cli) {
        // SSL detection
        $https = false;
        if (
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
            (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) ||
            (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') ||
            (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower($_SERVER['HTTP_X_FORWARDED_SSL']) === 'on') ||
            (!empty($_SERVER['HTTP_FRONT_END_HTTPS']) && strtolower($_SERVER['HTTP_FRONT_END_HTTPS']) === 'on')
        ) {
            $https = true;
        }
        $scheme = $https ? 'https' : 'http';

        // Host header detection
        $host = '';
        if (!empty($_SERVER['HTTP_X_FORWARDED_HOST'])) {
            $hosts = explode(',', $_SERVER['HTTP_X_FORWARDED_HOST']);
            $host = trim($hosts[0]);
        }
        if (empty($host) && !empty($_SERVER['HTTP_HOST'])) {
            $host = $_SERVER['HTTP_HOST'];
        }
        if (empty($host) && !empty($_SERVER['SERVER_NAME'])) {
            $host = $_SERVER['SERVER_NAME'];
        }

        // Host validation (Security: Prevent Host Header Injection)
        $host_valid = false;
        if (!empty($host)) {
            if (preg_match('/^[a-zA-Z0-9.\-_]+(?::\d+)?$/', $host)) {
                $allowed_hosts_str = $_ENV['ALLOWED_HOSTS'] ?? getenv('ALLOWED_HOSTS') ?? '';
                if (!empty($allowed_hosts_str)) {
                    $allowed_hosts = array_map('trim', explode(',', strtolower($allowed_hosts_str)));
                    $hostname = strtolower(explode(':', $host)[0]);
                    if (in_array($hostname, $allowed_hosts, true)) {
                        $host_valid = true;
                    }
                } else {
                    // For local development convenience, allow any well-formed host if whitelist is empty
                    $host_valid = true;
                }
            }
        }

        if ($host_valid) {
            // Calculate base subdirectory path relative to the app root
            $app_root = str_replace('\\', '/', realpath(dirname(__DIR__)));
            $script_filename = str_replace('\\', '/', realpath($_SERVER['SCRIPT_FILENAME'] ?? ''));
            $script_name = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');

            $base_path = '';
            if ($app_root && $script_filename && $script_name && strpos($script_filename, $app_root) === 0) {
                $relative_path = substr($script_filename, strlen($app_root));
                if (substr($script_name, -strlen($relative_path)) === $relative_path) {
                    $base_path = substr($script_name, 0, -strlen($relative_path));
                } else {
                    $base_path = rtrim(dirname($script_name), '/');
                    if (preg_match('#/(api|driver|rider|admin)(/.*)?$#', $base_path)) {
                        $base_path = preg_replace('#/(api|driver|rider|admin)(/.*)?$#', '', $base_path);
                    }
                }
            } else {
                $base_path = rtrim(dirname($script_name), '/');
                if (preg_match('#/(api|driver|rider|admin)(/.*)?$#', $base_path)) {
                    $base_path = preg_replace('#/(api|driver|rider|admin)(/.*)?$#', '', $base_path);
                }
            }

            $base_path = rtrim(str_replace('\\', '/', $base_path), '/');
            if ($base_path === '/') {
                $base_path = '';
            }

            $resolved_url = $scheme . '://' . $host . $base_path;
            return $resolved_url;
        }
    }

    // 4. Safe fallback
    if ($app_env === 'PRODUCTION') {
        $resolved_url = 'https://app.mohtoh.com.ng';
    } elseif ($app_env === 'STAGING') {
        $resolved_url = 'https://staging.mohtoh.com.ng';
    } else {
        $resolved_url = 'http://app.mohtoh.com.ng/Mostmerit/merit_drives';
    }

    return $resolved_url;
}