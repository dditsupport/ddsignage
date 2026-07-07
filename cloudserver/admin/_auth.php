<?php
/**
 * Auth + CSRF helpers. Every admin page (except login.php itself)
 * must require this file.
 *
 *   require __DIR__ . '/_auth.php';
 *   admin_require_login();
 */

declare(strict_types=1);

require_once __DIR__ . '/_db.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'httponly' => true,
        'secure'   => !empty($_SERVER['HTTPS']),
        'samesite' => 'Lax',
    ]);
    session_start();
}

if (!function_exists('admin_require_login')) {

    function admin_require_login(): void {
        $ttl = (int)(admin_config()['admin']['session_ttl'] ?? 28800);
        $now = time();
        if (empty($_SESSION['admin']) || ($now - (int)($_SESSION['admin_ts'] ?? 0)) > $ttl) {
            unset($_SESSION['admin'], $_SESSION['admin_ts']);
            header('Location: login.php');
            exit;
        }
        // Sliding expiry — extend on each authenticated request.
        $_SESSION['admin_ts'] = $now;
    }

    function admin_attempt_login(string $username, string $password): bool {
        $cfg = admin_config()['admin'];
        if (!hash_equals((string)$cfg['username'], $username)) return false;
        if (!password_verify($password, (string)$cfg['password_hash'])) return false;
        session_regenerate_id(true);
        $_SESSION['admin'] = $username;
        $_SESSION['admin_ts'] = time();
        return true;
    }

    function admin_logout(): void {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    function csrf_token(): string {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    function csrf_check(): void {
        $t = $_POST['csrf'] ?? '';
        if (!is_string($t) || !hash_equals($_SESSION['csrf'] ?? '', $t)) {
            http_response_code(400);
            echo 'CSRF check failed. Reload and try again.';
            exit;
        }
    }

    function csrf_field(): string {
        return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
    }
}
