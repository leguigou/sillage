<?php
declare(strict_types=1);
use App\Core\Security;

// Address used by rate limits, with and without a trusted reverse proxy.
$originalProxies = getenv('TRUSTED_PROXIES');
$originalRemote = $_SERVER['REMOTE_ADDR'] ?? null;
$originalForwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;
$clientIp = function (string $remote, ?string $forwarded): string {
    $_SERVER['REMOTE_ADDR'] = $remote;
    if ($forwarded === null) {
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);
    } else {
        $_SERVER['HTTP_X_FORWARDED_FOR'] = $forwarded;
    }
    return Security::clientIp();
};
try {
    putenv('TRUSTED_PROXIES');
    check(
        $clientIp('172.18.0.5', '203.0.113.7') === '172.18.0.5',
        'sans proxy déclaré, X-Forwarded-For est ignoré',
    );
    putenv('TRUSTED_PROXIES=10.0.0.0/8, 172.16.0.0/12,fd00::/8');
    check(
        $clientIp('172.18.0.5', '203.0.113.7') === '203.0.113.7',
        'derrière un proxy de confiance, l’adresse du visiteur est retenue',
    );
    check(
        $clientIp('172.18.0.5', '198.51.100.1, 203.0.113.7') === '203.0.113.7',
        'une adresse forgée par le visiteur à gauche est ignorée',
    );
    check(
        $clientIp('172.18.0.5', '203.0.113.7, 10.0.4.2') === '203.0.113.7',
        'les proxys de confiance enchaînés sont sautés',
    );
    check(
        $clientIp('198.51.100.9', '203.0.113.7') === '198.51.100.9',
        'une connexion directe non fiable ne peut pas choisir son adresse',
    );
    check(
        $clientIp('172.18.0.5', null) === '172.18.0.5' &&
            $clientIp('172.18.0.5', 'inconnu') === '172.18.0.5',
        'une chaîne absente ou illisible conserve l’adresse du proxy',
    );
    check(
        $clientIp('::ffff:172.18.0.5', '2001:db8::7') === '2001:db8::7',
        'IPv4 notée en IPv6 et visiteur IPv6 reconnus',
    );
    check(
        $clientIp('172.32.0.1', '203.0.113.7') === '172.32.0.1',
        'le préfixe CIDR est respecté à la limite du réseau',
    );
    foreach (['proxy.local', '10.0.0.0/33', '10.0.0.0/x'] as $invalid) {
        putenv('TRUSTED_PROXIES=' . $invalid);
        try {
            $clientIp('172.18.0.5', '203.0.113.7');
            throw new RuntimeException('ÉCHEC : TRUSTED_PROXIES invalide accepté ' . $invalid);
        } catch (RuntimeException $error) {
            check(
                str_starts_with($error->getMessage(), 'TRUSTED_PROXIES'),
                'TRUSTED_PROXIES invalide refusé : ' . $invalid,
            );
        }
    }
} finally {
    putenv($originalProxies === false ? 'TRUSTED_PROXIES' : 'TRUSTED_PROXIES=' . $originalProxies);
    if ($originalRemote === null) {
        unset($_SERVER['REMOTE_ADDR']);
    } else {
        $_SERVER['REMOTE_ADDR'] = $originalRemote;
    }
    if ($originalForwarded === null) {
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);
    } else {
        $_SERVER['HTTP_X_FORWARDED_FOR'] = $originalForwarded;
    }
}
