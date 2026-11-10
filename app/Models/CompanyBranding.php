<?php
declare(strict_types=1);
namespace App\Models;
use App\Core\DB;

/**
 * Installation-wide company identity shown in the interface: name, subtitle, short icon text and
 * optional logo, stored in the single row id=1 of company_branding.
 * save() uses an optimistic version check under a row lock; the logo bytes themselves are written
 * here but read only by the dedicated image endpoint (get() returns the metadata and digest).
 */
final class CompanyBranding
{
    private static ?array $cached = null;

    /** Read only the small metadata; logo bytes are loaded by the image endpoint. */
    public static function get(): array
    {
        if (self::$cached !== null) return self::$cached;
        try {
            $row = DB::one('SELECT name,subtitle,icon,logo_mime,logo_digest,version FROM company_branding WHERE id=1');
        } catch (\PDOException $error) {
            // Allow installation diagnostics before the additive migration is run.
            if ($error->getCode() !== '42S02') throw $error;
            $row = null;
        }
        return self::$cached = $row ?? [
            'name'=>null, 'subtitle'=>'Espace entreprise', 'icon'=>null,
            'logo_mime'=>null, 'logo_digest'=>null, 'version'=>0,
        ];
    }

    public static function resetCache(): void { self::$cached = null; }

    public static function save(array $input, ?array $logo = null): void
    {
        $name = trim((string) ($input['company_name'] ?? ''));
        $subtitle = trim((string) ($input['company_subtitle'] ?? ''));
        $icon = trim((string) ($input['company_icon'] ?? ''));
        foreach ([[$name,120,'Le nom de l’entreprise'],[$subtitle,100,'Le sous-titre'],[$icon,4,'L’initiale ou le symbole']] as [$value,$max,$label]) {
            if (mb_strlen($value) > $max || preg_match('/[\x00-\x1f\x7f]/u', $value)) {
                throw new \InvalidArgumentException($label . ' contient des caractères invalides ou dépasse ' . $max . ' caractères.');
            }
        }
        if ($name === '') throw new \InvalidArgumentException('Le nom de l’entreprise est requis.');
        $remove = !empty($input['remove_logo']);
        if ($remove && $logo) throw new \InvalidArgumentException('Choisissez un nouveau logo ou retirez le logo actuel, sans combiner les deux.');
        DB::transaction(function () use ($input,$name,$subtitle,$icon,$logo,$remove) {
            // Create the singleton row on first save so it can then be locked and version-checked.
            DB::run('INSERT IGNORE INTO company_branding(id) VALUES(1)');
            $old = DB::one('SELECT version FROM company_branding WHERE id=1 FOR UPDATE');
            if ((int) ($input['version'] ?? -1) !== (int) $old['version']) {
                throw new \InvalidArgumentException('Les informations de l’entreprise ont changé. Rechargez la page avant de réessayer.');
            }
            DB::run('UPDATE company_branding SET name=?,subtitle=?,icon=?,version=version+1 WHERE id=1',[$name,$subtitle,$icon === '' ? null : $icon]);
            // With $remove the three logo columns are set to NULL; without either flag the logo is kept.
            if ($remove || $logo) {
                DB::run('UPDATE company_branding SET logo_data=?,logo_mime=?,logo_digest=? WHERE id=1',[
                    $logo['data'] ?? null, $logo['mime'] ?? null, $logo['digest'] ?? null,
                ]);
            }
        });
        self::resetCache();
    }


    /**
     * Display name put before MAIL_FROM in the From header of every e-mail; null when an
     * administrator has not chosen one (the software name is then used).
     */
    public static function senderName(): ?string
    {
        try {
            $value = DB::one('SELECT mail_from_name FROM company_branding WHERE id=1')['mail_from_name'] ?? null;
        } catch (\PDOException $error) {
            // Table or column not migrated yet (42S02/42S22): behave as if nothing was chosen.
            if (!in_array($error->getCode(), ['42S02', '42S22'], true)) throw $error;
            return null;
        }
        return $value === null || $value === '' ? null : (string) $value;
    }

    /** Saves the sender display name; an empty value restores the default. */
    public static function saveSenderName(string $name): void
    {
        $name = trim($name);
        if (mb_strlen($name) > 100 || preg_match('/[\x00-\x1f\x7f"<>]/u', $name)) {
            throw new \InvalidArgumentException('Le nom de l’expéditeur doit faire au plus 100 caractères, sans guillemets, chevrons ni retour à la ligne.');
        }
        DB::run('INSERT INTO company_branding(id,mail_from_name) VALUES(1,?) ON DUPLICATE KEY UPDATE mail_from_name=VALUES(mail_from_name)', [$name === '' ? null : $name]);
    }
}
