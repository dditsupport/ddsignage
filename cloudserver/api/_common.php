<?php
/**
 * Shared bootstrap for every API endpoint:
 *   - load config
 *   - PDO factory
 *   - JSON output helper
 *   - client IP extraction
 *   - device_logs writer (optional)
 *
 * Endpoints require this file at their top, then call api_pdo() / api_json() etc.
 */

declare(strict_types=1);

// Lock the entire API runtime to Asia/Kolkata (IST, UTC+05:30, no DST).
// Every endpoint requires this file at its top, so this is the one place
// to set it — date(), strtotime(), etc. all default to IST after this.
date_default_timezone_set('Asia/Kolkata');

if (!function_exists('api_config')) {

    function api_config(): array {
        static $cfg = null;
        if ($cfg !== null) return $cfg;

        // Candidate locations, tried in order:
        //   1. DANGEECAST_CONFIG env var (explicit override)
        //   2. <deploy-root>/config_dc/config.php  — production: cloudserver = public_html/signage
        //   3. <repo-root>/config_dc/config.php    — dev: cloudserver and config_dc are siblings
        //   4. cloudserver/config.php              — legacy, only if the file was placed inside
        $cloudserverRoot = dirname(__DIR__);
        $candidates = array_filter([
            getenv('DANGEECAST_CONFIG') ?: null,
            $cloudserverRoot . '/../../config_dc/config.php',
            $cloudserverRoot . '/../config_dc/config.php',
            $cloudserverRoot . '/config.php',
        ]);

        $loaded = null;
        foreach ($candidates as $path) {
            if (is_readable($path)) { $loaded = require $path; break; }
        }
        if (!is_array($loaded)) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Server not configured']);
            exit;
        }

        // On-disk paths are derived from the cloudserver location so the
        // config file outside public_html doesn't need to know the deploy layout.
        $loaded['paths'] = [
            'media_dir'   => $cloudserverRoot . '/media',
            'devices_dir' => $cloudserverRoot . '/devices',
            'apk_dir'     => $cloudserverRoot . '/apk',
            'logs_dir'    => $cloudserverRoot . '/logs',
        ];
        $cfg = $loaded;
        return $cfg;
    }

    function api_pdo(): PDO {
        static $pdo = null;
        if ($pdo !== null) return $pdo;
        $c = api_config()['db'];
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $c['host'], (int)$c['port'], $c['name'], $c['charset']
        );
        try {
            $pdo = new PDO($dsn, $c['user'], $c['password'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            // Match PHP's default TZ — every TIMESTAMP read/write happens in IST.
            $pdo->exec("SET time_zone = '+05:30'");
        } catch (Throwable $t) {
            error_log('signage db connect failed: ' . $t->getMessage());
            api_json(['status' => 'error', 'message' => 'Database unavailable'], 500);
        }
        return $pdo;
    }

    function api_json(array $payload, int $status = 200): void {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    function api_post(string $key, ?string $default = null): ?string {
        $v = $_POST[$key] ?? $default;
        return is_string($v) ? trim($v) : $default;
    }

    function api_get(string $key, ?string $default = null): ?string {
        $v = $_GET[$key] ?? $default;
        return is_string($v) ? trim($v) : $default;
    }

    function api_require_post(): void {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            api_json(['status' => 'error', 'message' => 'POST required'], 405);
        }
    }

    function api_require_get(): void {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
            api_json(['status' => 'error', 'message' => 'GET required'], 405);
        }
    }

    /**
     * Extract a usable client IP, respecting trusted proxy headers when
     * deployed behind a load balancer. Defaults to REMOTE_ADDR.
     */
    function api_client_ip(): string {
        $candidates = [];
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            // First entry of XFF is the original client.
            $first = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0] ?? '';
            $candidates[] = trim($first);
        }
        if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            $candidates[] = $_SERVER['HTTP_X_REAL_IP'];
        }
        $candidates[] = $_SERVER['REMOTE_ADDR'] ?? '';
        foreach ($candidates as $ip) {
            if ($ip && filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
        return '';
    }

    /**
     * Optional event logging. Controlled by config.log_pings to avoid
     * unbounded growth in production.
     */
    function api_log_event(int $deviceId, string $eventType, ?string $details = null): void {
        if (empty(api_config()['log_pings']) && $eventType === 'ping') return;
        try {
            api_pdo()->prepare(
                'INSERT INTO device_logs (device_id, event_type, details) VALUES (?, ?, ?)'
            )->execute([$deviceId, $eventType, $details]);
        } catch (Throwable $t) {
            error_log('device_logs insert failed: ' . $t->getMessage());
        }
    }

    /**
     * ANDROID_ID validation. Accept the canonical 16-hex-char form, but allow
     * up to 64 chars / alnum-with-underscore so test overrides like
     * "test_device_001" work too (matches APK behavior).
     */
    function api_valid_android_id(?string $s): bool {
        if (!is_string($s) || $s === '') return false;
        return (bool)preg_match('/^[A-Za-z0-9_-]{1,64}$/', $s);
    }
}
