<?php
/**
 * Admin-side DB / config bootstrap. Mirrors api/_common.php but tailored
 * for the dashboard pages (no JSON output, returns PDO directly, exposes
 * config to the views).
 */

declare(strict_types=1);

// Lock the entire admin runtime to Asia/Kolkata (IST, UTC+05:30, no DST).
// Affects date(), strtotime(), and any other PHP time function. Safe to set
// here because every admin entry-point requires _auth.php which requires this.
date_default_timezone_set('Asia/Kolkata');

if (!function_exists('admin_config')) {

    function admin_config(): array {
        static $cfg = null;
        if ($cfg !== null) return $cfg;

        // Same candidate chain as api_config() — see cloudserver/api/_common.php.
        $cloudserverRoot = dirname(__DIR__);
        $candidates = array_filter([
            getenv('DANGEECAST_CONFIG') ?: null,
            $cloudserverRoot . '/../../config_dc/config.php',
            $cloudserverRoot . '/../config_dc/config.php',
            $cloudserverRoot . '/config.php',
        ]);

        $loaded = null;
        $picked = null;
        foreach ($candidates as $path) {
            if (is_readable($path)) { $loaded = require $path; $picked = $path; break; }
        }
        if (!is_array($loaded)) {
            http_response_code(500);
            echo 'Server not configured. Place config.php under <deploy-root>/config_dc/ '
               . '(see cloudserver/config.example.php for the template).';
            exit;
        }

        // Inject on-disk paths derived from cloudserver location.
        $loaded['paths'] = [
            'media_dir'   => $cloudserverRoot . '/media',
            'devices_dir' => $cloudserverRoot . '/devices',
            'apk_dir'     => $cloudserverRoot . '/apk',
            'logs_dir'    => $cloudserverRoot . '/logs',
        ];
        $cfg = $loaded;
        return $cfg;
    }

    function admin_pdo(): PDO {
        static $pdo = null;
        if ($pdo !== null) return $pdo;
        $c = admin_config()['db'];
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $c['host'], (int)$c['port'], $c['name'], $c['charset']);
        $pdo = new PDO($dsn, $c['user'], $c['password'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        // Force the session to IST so current_timestamp() / NOW() inserts and
        // TIMESTAMP comparisons happen in Asia/Kolkata regardless of the
        // server's default. Numeric '+05:30' always works; named zones need
        // the mysql_tzinfo tables loaded which shared hosts often lack.
        $pdo->exec("SET time_zone = '+05:30'");
        return $pdo;
    }

    function admin_public_url(string $path = ''): string {
        $base = rtrim(admin_config()['public_base_url'] ?? '', '/');
        return $base . ($path ? ('/' . ltrim($path, '/')) : '');
    }

    function h(?string $s): string {
        return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Compact "X ago" formatter for diagnostic UI. Caps at days; we never
     * want a 3-month-old device showing as "12 weeks" — that's just "long ago".
     */
    function human_ago(int $secs): string {
        if ($secs < 60)        return $secs . 's';
        if ($secs < 3600)      return intdiv($secs, 60) . 'm';
        if ($secs < 86400)     return intdiv($secs, 3600) . 'h';
        if ($secs < 86400 * 30) return intdiv($secs, 86400) . 'd';
        return 'long ago';
    }

    /**
     * Compact bytes formatter — "10.5 GB" style. Used on the device-edit
     * card to render storage free / total. Mirrors the JS fmtBytes() in
     * the player so dashboard and on-device numbers read the same way.
     */
    function human_bytes(?int $n): string {
        if ($n === null || $n <= 0) return '0 B';
        if ($n < 1024)               return $n . ' B';
        if ($n < 1024 * 1024)        return number_format($n / 1024, 1) . ' KB';
        if ($n < 1024 * 1024 * 1024) return number_format($n / (1024 * 1024), 1) . ' MB';
        return number_format($n / (1024 * 1024 * 1024), 2) . ' GB';
    }

    function flash_set(string $msg, string $type = 'info'): void {
        $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
    }

    function flash_take(): array {
        $f = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);
        return $f;
    }
}
