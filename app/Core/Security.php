<?php
declare(strict_types=1);
namespace App\Core;
final class Security
{
    private static function key(): string
    {
        $hex = env('APP_KEY');
        if (strlen($hex) !== 64 || !ctype_xdigit($hex)) {
            throw new \RuntimeException('Configurez APP_KEY (64 caractères hexadécimaux).');
        }
        return hex2bin($hex);
    }
    public static function sessionName(): string
    {
        return 'sillage_' .
            substr(
                hash_hmac(
                    'sha256',
                    rtrim(env('APP_URL', 'http://127.0.0.1:8088'), '/'),
                    self::key(),
                ),
                0,
                16,
            );
    }
    public static function start(): void
    {
        self::key();
        Company::settings();
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_name(self::sessionName());
        session_set_cookie_params([
            'httponly' => true,
            'secure' => env('SESSION_SECURE', 'true') === 'true',
            'samesite' => 'Lax',
            'path' => '/',
        ]);
        session_start();
        if (isset($_SESSION['last_seen']) && time() - $_SESSION['last_seen'] > 28800) {
            $_SESSION = [];
            session_regenerate_id(true);
        }
        $_SESSION['last_seen'] = time();
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        header('X-Frame-Options: DENY');
        self::contentSecurityPolicy();
        header('Cache-Control: no-store');
    }
    /** OAuth form redirects must also be permitted by the originating document's CSP. */
    public static function contentSecurityPolicy(?string $formRedirect = null): void
    {
        $sources = "'self'";
        if ($formRedirect !== null) {
            $parts = parse_url($formRedirect);
            if (
                !$parts || !filter_var($formRedirect, FILTER_VALIDATE_URL) ||
                !in_array($parts['scheme'] ?? '', ['http', 'https'], true) ||
                isset($parts['user']) || isset($parts['pass']) ||
                !preg_match('/^(?:[a-zA-Z0-9.-]+|\[[a-fA-F0-9:]+\])$/D', $parts['host'] ?? '')
            ) throw new \InvalidArgumentException('Destination du formulaire invalide.');
            // The server still redirects to the exact registered URL; only its origin enters CSP.
            $sources .= ' ' . $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        }
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action " . $sources);
    }
    public static function csrf(): string
    {
        return $_SESSION['_csrf'] ??= bin2hex(random_bytes(32));
    }
    public static function checkCsrf(): void
    {
        if (
            !hash_equals(
                self::csrf(),
                (string) ($_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')),
            )
        ) {
            http_response_code(403);
            exit('La session du formulaire a expiré. Rechargez la page.');
        }
    }
    public static function email(string $email, bool $checkCompany = true): string
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
            throw new \InvalidArgumentException(
                'Saisissez une adresse e-mail valide (190 caractères maximum).',
            );
        }
        if ($checkCompany && !Company::allowsEmailDomain($email)) {
            throw new \InvalidArgumentException(Company::signupHint());
        }
        return $email;
    }
    public static function nameFromEmail(string $email): string
    {
        $local = explode('@', $email, 2)[0];
        $local = preg_replace('/[._+\-]+/u', ' ', $local);
        return mb_convert_case(trim($local), MB_CASE_TITLE, 'UTF-8');
    }
    public static function password(string $password): string
    {
        if (mb_strlen($password) < 12 || strlen($password) > 72) {
            throw new \InvalidArgumentException(
                'Le mot de passe doit contenir au moins 12 caractères et au plus 72 octets.',
            );
        }
        return password_hash($password, PASSWORD_DEFAULT);
    }
    public static function limit(string $scope, int $max = 8, int $window = 900): void
    {
        $key = hash_hmac(
            'sha256',
            $scope . '|' . ($_SERVER['REMOTE_ADDR'] ?? 'cli'),
            env('APP_KEY'),
        );
        DB::run(
            'INSERT INTO rate_limits (bucket, hits, expires_at) VALUES (?, 1, ?) ON DUPLICATE KEY UPDATE hits=IF(expires_at < UNIX_TIMESTAMP(),1,hits+1), expires_at=IF(expires_at < UNIX_TIMESTAMP(),VALUES(expires_at),expires_at)',
            [$key, time() + $window],
        );
        if ((int) DB::one('SELECT hits FROM rate_limits WHERE bucket=?', [$key])['hits'] > $max) {
            http_response_code(429);
            exit('Trop de tentatives. Réessayez plus tard.');
        }
    }
    public static function encrypt(string $plain): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new \RuntimeException('Chiffrement indisponible.');
        }
        return base64_encode($iv . $tag . $cipher);
    }
    public static function decrypt(string $encoded): string
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < 28) {
            throw new \RuntimeException('Secret invalide.');
        }
        $v = openssl_decrypt(
            substr($raw, 28),
            'aes-256-gcm',
            self::key(),
            OPENSSL_RAW_DATA,
            substr($raw, 0, 12),
            substr($raw, 12, 16),
        );
        if ($v === false) {
            throw new \RuntimeException('Impossible de déchiffrer le secret.');
        }
        return $v;
    }
    public static function date(?string $date): ?string
    {
        if (!$date) {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date) {
            throw new \InvalidArgumentException('Date invalide.');
        }
        return $date;
    }
}
