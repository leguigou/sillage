<?php
declare(strict_types=1);
namespace App\Core;
use App\Models\CompanyBranding;

final class Company
{
    private static ?array $source = null;
    private static array $settings = [];

    /** One parsed policy shared by registration, CLI commands and the interface. */
    public static function settings(): array
    {
        $source = [
            CompanyBranding::get()['name'] ?? trim(env('COMPANY_NAME', 'Mon entreprise')),
            env('ALLOWED_DOMAINS', env('ALLOWED_DOMAIN')),
            env('EMAIL_DOMAIN_RESTRICTION', 'true'),
        ];
        if (self::$source === $source) {
            return self::$settings;
        }
        [$name, $rawDomains, $restriction] = $source;
        if ($name === '' || mb_strlen($name) > 120 || preg_match('/[\x00-\x1f\x7f]/', $name)) {
            throw new \RuntimeException(
                'Configurez COMPANY_NAME : un nom de société de 1 à 120 caractères, sans caractère de contrôle.',
            );
        }
        if (!in_array($restriction, ['true', 'false'], true)) {
            throw new \RuntimeException('EMAIL_DOMAIN_RESTRICTION doit valoir true ou false.');
        }
        $domains = [];
        if (trim($rawDomains) !== '') {
            foreach (explode(',', $rawDomains) as $value) {
                $domain = strtolower(trim($value));
                if (
                    !str_contains($domain, '.') ||
                    str_ends_with($domain, '.') ||
                    !filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
                ) {
                    throw new \RuntimeException(
                        'ALLOWED_DOMAINS doit contenir des domaines exacts séparés par des virgules, sans @, URL ni joker.',
                    );
                }
                $domains[] = $domain;
            }
        }
        if ($restriction === 'true' && !$domains) {
            throw new \RuntimeException(
                'Configurez au moins un domaine dans ALLOWED_DOMAINS pour restreindre les inscriptions.',
            );
        }
        self::$settings = [
            'name' => $name,
            'domains' => array_values(array_unique($domains)),
            'restricted' => $restriction === 'true',
        ];
        self::$source = $source;
        return self::$settings;
    }

    public static function name(): string
    {
        return self::settings()['name'];
    }

    public static function initial(): string
    {
        return mb_strtoupper(mb_substr(self::name(), 0, 1));
    }

    public static function mark(): string
    {
        $branding = CompanyBranding::get();
        if ($branding['logo_digest']) {
            return '<img class="company-logo" src="/company/logo/' . e($branding['logo_digest']) . '" alt="' . e(self::name()) . '">';
        }
        return '<span aria-hidden="true">' . e($branding['icon'] ?? self::initial()) . '</span>';
    }

    public static function subtitle(): string
    {
        return CompanyBranding::get()['subtitle'];
    }

    public static function domainsLabel(): string
    {
        return implode(', ', array_map(fn($domain) => '@' . $domain, self::settings()['domains']));
    }

    public static function signupHint(): string
    {
        return self::settings()['restricted']
            ? 'Inscriptions réservées aux adresses ' . self::domainsLabel() . '.'
            : 'Les inscriptions acceptent toute adresse e-mail valide.';
    }

    public static function exampleEmail(): string
    {
        return 'prenom.nom@' . (self::settings()['domains'][0] ?? 'example.com');
    }

    public static function allowsEmailDomain(string $email): bool
    {
        $settings = self::settings();
        $domain = strtolower(substr(strrchr($email, '@') ?: '', 1));
        return !$settings['restricted'] || in_array($domain, $settings['domains'], true);
    }
}
