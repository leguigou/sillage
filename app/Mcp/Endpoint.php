<?php
declare(strict_types=1);
namespace App\Mcp;
/**
 * Transport-level guard shared by the MCP endpoint (Server::handle) and the public OAuth endpoints
 * (OAuthController::publicEndpoint), checked before any authentication.
 * It requires HTTPS for APP_URL (plain HTTP is tolerated only for a local install reached from
 * loopback), a Host header matching APP_URL's origin, and, when the client sends an Origin header,
 * that same origin. The Host/Origin checks protect against DNS rebinding and cross-site browser calls.
 */
final class Endpoint
{
    /** Normalised "scheme://host:port" (default port filled in), or null for anything that is not a bare http(s) origin. */
    private static function origin(string $url): ?string
    {
        $p = parse_url($url);
        if (
            !$p ||
            !isset($p['scheme'], $p['host']) ||
            !in_array(strtolower($p['scheme']), ['http', 'https'], true) ||
            isset($p['user']) ||
            isset($p['pass']) ||
            isset($p['query']) ||
            isset($p['fragment'])
        ) {
            return null;
        }
        return strtolower($p['scheme']) .
            '://' .
            strtolower($p['host']) .
            ':' .
            ($p['port'] ?? (strtolower($p['scheme']) === 'https' ? 443 : 80));
    }
    /** Null when the request may proceed, otherwise ['message' => ..., 'status' => HTTP code] to send back. */
    public static function error(): ?array
    {
            $base = url();
            $origin = self::origin($base);
            $scheme = strtolower((string) parse_url($base, PHP_URL_SCHEME));
            $localHttp =
                env('APP_ENV') === 'local' &&
                in_array(
                    strtolower((string) parse_url($base, PHP_URL_HOST)),
                    ['localhost', '127.0.0.1', '::1', '[::1]'],
                    true,
                ) &&
                in_array(
                    $_SERVER['REMOTE_ADDR'] ?? '',
                    ['127.0.0.1', '::1', '::ffff:127.0.0.1'],
                    true,
                );
            if (!$origin || ($scheme !== 'https' && !$localHttp)) {
                return ['message'=>'Le MCP exige une URL HTTPS en production.','status'=>503];
            }
            $host = self::origin(
                parse_url($base, PHP_URL_SCHEME) . '://' . ($_SERVER['HTTP_HOST'] ?? ''),
            );
            if ($host !== $origin) {
                return ['message'=>'Hôte non autorisé.','status'=>403];
            }
            if (
                isset($_SERVER['HTTP_ORIGIN']) &&
                (self::origin($_SERVER['HTTP_ORIGIN']) !== $origin ||
                    !in_array(
                        parse_url($_SERVER['HTTP_ORIGIN'], PHP_URL_PATH),
                        [null, '', '/'],
                        true,
                    ))
            ) {
                return ['message'=>'Origine non autorisée.','status'=>403];
            }
        return null;
    }
}
