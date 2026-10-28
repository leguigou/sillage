<?php
declare(strict_types=1);
namespace App\Core;
final class Auth
{
    private static ?array $cached = null;
    public static function user(): ?array
    {
        if (!isset($_SESSION['user_id'])) {
            $cookie=(string)($_COOKIE[PersistentLogin::cookieName()]??'');
            if ($cookie==='') return null;
            $restored=PersistentLogin::restore($cookie);
            if (!$restored) { PersistentLogin::setCookie('',time()-3600);return null; }
            self::establish($restored['user'],$restored['selector'],$restored['expires']);
        }
        if (self::$cached === null) {
            self::$cached = DB::one(
                'SELECT u.id,u.email,u.name,u.role,u.verified_at,u.active,u.auth_version,(r.selector IS NOT NULL) remember_valid FROM users u LEFT JOIN persistent_logins r ON r.selector=? AND r.user_id=u.id AND r.auth_version=u.auth_version AND r.expires_at>UTC_TIMESTAMP() WHERE u.id=?',
                [(string)($_SESSION['remember_selector']??''),(int) $_SESSION['user_id']],
            );
        }
        if (
            !self::$cached ||
            !self::$cached['active'] ||
            !self::$cached['verified_at'] ||
            (int) ($_SESSION['auth_version'] ?? 0) !== (int) self::$cached['auth_version'] ||
            (isset($_SESSION['remember_selector']) && !self::$cached['remember_valid']) ||
            (isset($_SESSION['remember_until']) && (int)$_SESSION['remember_until']<=time())
        ) {
            self::logout();
            return null;
        }
        // Upgrade an existing authenticated session without requiring another password entry.
        if (!isset($_SESSION['remember_until']) || !isset($_COOKIE[PersistentLogin::cookieName()])) {
            $credential=PersistentLogin::issue(self::$cached,(int)($_SESSION['remember_until']??(time()+PersistentLogin::LIFETIME)),$_SESSION['remember_selector']??null);
            if (!$credential) { self::logout();return null; }
            $_SESSION['remember_selector']=$credential['selector'];$_SESSION['remember_until']=$credential['expires'];
            PersistentLogin::setCookie($credential['cookie'],$credential['expires']);
            self::$cached['remember_valid']=1;
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
        PersistentLogin::forget();
        $credential=PersistentLogin::issue($user,time()+PersistentLogin::LIFETIME);
        if (!$credential) throw new \InvalidArgumentException('Le compte a changé. Reconnectez-vous.');
        self::establish($user,$credential['selector'],$credential['expires']);
        PersistentLogin::setCookie($credential['cookie'],$credential['expires']);
    }
    private static function establish(array $user,string $selector,int $expires): void
    {
        session_regenerate_id(true);
        $_SESSION = [
            'user_id' => (int) $user['id'],
            'auth_version' => (int) $user['auth_version'],
            'last_seen' => time(),
            'remember_selector'=>$selector,
            'remember_until'=>$expires,
        ];
        self::$cached = null;
    }
    public static function logout(): void
    {
        PersistentLogin::forget();
        $_SESSION = [];
        session_regenerate_id(true);
        self::$cached = null;
    }
}
