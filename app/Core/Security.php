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
            $scope . '|' . self::clientIp(),
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
    /**
     * Adresse du visiteur. Sans TRUSTED_PROXIES, c'est REMOTE_ADDR, comme avant.
     * Derrière un reverse proxy déclaré, X-Forwarded-For est lu de droite à gauche en
     * sautant les proxys de confiance : on retient la première adresse ajoutée par un
     * proxy de confiance. Les valeurs écrites par le visiteur, à gauche, sont ignorées.
     */
    public static function clientIp(): string
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? 'cli');
        $trusted = self::trustedProxies();
        if ($trusted === [] || !self::ipInRanges($remote, $trusted)) {
            return $remote;
        }
        $chain = explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
        for ($i = count($chain) - 1; $i >= 0; $i--) {
            $ip = trim($chain[$i]);
            if (self::packIp($ip) === null) {
                // Chaîne vide ou illisible : on garde l'adresse du proxy plutôt que de deviner.
                return $remote;
            }
            if (!self::ipInRanges($ip, $trusted)) {
                return $ip;
            }
        }
        return $remote;
    }
    /** @return list<array{0:string,1:int}> réseaux binaires et longueur de préfixe */
    private static function trustedProxies(): array
    {
        $ranges = [];
        foreach (explode(',', env('TRUSTED_PROXIES')) as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            $parts = explode('/', $entry, 2);
            $network = self::packIp($parts[0]);
            $max = $network === null ? 0 : strlen($network) * 8;
            $bits = $parts[1] ?? (string) $max;
            if ($network === null || !ctype_digit($bits) || (int) $bits > $max) {
                throw new \RuntimeException(
                    'TRUSTED_PROXIES doit lister des adresses IP ou des réseaux CIDR séparés par des virgules.',
                );
            }
            $ranges[] = [$network, (int) $bits];
        }
        return $ranges;
    }
    private static function ipInRanges(string $ip, array $ranges): bool
    {
        $packed = self::packIp($ip);
        if ($packed === null) {
            return false;
        }
        foreach ($ranges as [$network, $bits]) {
            if (strlen($network) !== strlen($packed)) {
                continue;
            }
            $bytes = intdiv($bits, 8);
            if (substr($packed, 0, $bytes) !== substr($network, 0, $bytes)) {
                continue;
            }
            $rest = $bits % 8;
            if ($rest === 0) {
                return true;
            }
            $mask = (0xff << (8 - $rest)) & 0xff;
            if ((ord($packed[$bytes]) & $mask) === (ord($network[$bytes]) & $mask)) {
                return true;
            }
        }
        return false;
    }
    /** Forme binaire d'une adresse ; une IPv4 notée ::ffff:a.b.c.d est ramenée à l'IPv4. */
    private static function packIp(string $ip): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        $packed = inet_pton($ip);
        if ($packed === false) {
            return null;
        }
        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
            return substr($packed, 12);
        }
        return $packed;
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
