<?php
declare(strict_types=1);
namespace App\Http;
use App\Config;

final class Session {
    public static function start(Config $cfg): void {
        if (session_status() === PHP_SESSION_ACTIVE) return;
        session_name($cfg->sessionName);
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => $cfg->secureCookie,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }

    public static function login(int $userId): void {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
    }

    public static function logout(): void {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    public static function userId(): ?int {
        $uid = $_SESSION['user_id'] ?? null;
        return is_int($uid) ? $uid : null;
    }
}
