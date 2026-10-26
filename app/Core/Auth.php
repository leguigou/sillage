<?php
declare(strict_types=1);
namespace App\Core;
final class Auth
{
    private static ?array $cached = null;
    public static function user(): ?array
    {
        if (!isset($_SESSION['user_id'])) {
            return null;
        }
        if (self::$cached === null) {
            self::$cached = DB::one(
                'SELECT id,email,name,role,verified_at,active,auth_version FROM users WHERE id=?',
                [(int) $_SESSION['user_id']],
            );
        }
        if (
            !self::$cached ||
            !self::$cached['active'] ||
            !self::$cached['verified_at'] ||
            (int) ($_SESSION['auth_version'] ?? 0) !== (int) self::$cached['auth_version']
        ) {
            return null;
        }
        return self::$cached;
    }
    public static function requireUser(): array
    {
        $u = self::user();
        if (!$u) {
            redirect('/login');
        }
        return $u;
    }
    public static function admin(): array
    {
        $u = self::requireUser();
        if ($u['role'] !== 'admin') {
            http_response_code(403);
            exit('Cette action est réservée à un administrateur.');
        }
        return $u;
    }
    public static function editor(): array
    {
        $u = self::requireUser();
        if ($u['role'] === 'viewer') {
            http_response_code(403);
            exit('Votre compte est en lecture seule.');
        }
        return $u;
    }
    public static function login(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION = [
            'user_id' => (int) $user['id'],
            'auth_version' => (int) $user['auth_version'],
            'last_seen' => time(),
        ];
        self::$cached = null;
    }
    public static function logout(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
        self::$cached = null;
    }
}
