<?php
declare(strict_types=1);
namespace App\Models;
use App\Core\DB;

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
            DB::run('INSERT IGNORE INTO company_branding(id) VALUES(1)');
            $old = DB::one('SELECT version FROM company_branding WHERE id=1 FOR UPDATE');
            if ((int) ($input['version'] ?? -1) !== (int) $old['version']) {
                throw new \InvalidArgumentException('Les informations de l’entreprise ont changé. Rechargez la page avant de réessayer.');
            }
            DB::run('UPDATE company_branding SET name=?,subtitle=?,icon=?,version=version+1 WHERE id=1',[$name,$subtitle,$icon === '' ? null : $icon]);
            if ($remove || $logo) {
                DB::run('UPDATE company_branding SET logo_data=?,logo_mime=?,logo_digest=? WHERE id=1',[
                    $logo['data'] ?? null, $logo['mime'] ?? null, $logo['digest'] ?? null,
                ]);
            }
        });
        self::resetCache();
    }

    public static function uploadedLogo(array $file): ?array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
        if (($file['error'] ?? null) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) {
            throw new \InvalidArgumentException('Le logo n’a pas pu être chargé.');
        }
        return self::validateImage($file['tmp_name']);
    }

    /** File-format validation shared by HTTP upload and real image contract tests. */
    public static function validateImage(string $path): array
    {
        $size = filesize($path);
        if (!$size || $size > 2 * 1024 * 1024) throw new \InvalidArgumentException('Le logo doit peser au maximum 2 Mo.');
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (!in_array($mime, ['image/png','image/jpeg','image/webp'], true)) {
            throw new \InvalidArgumentException('Utilisez un logo PNG, JPEG ou WebP.');
        }
        $dimensions = @getimagesize($path);
        if (!$dimensions || $dimensions[0] < 1 || $dimensions[1] < 1 || $dimensions[0] > 4096 || $dimensions[1] > 4096 || ($dimensions['mime'] ?? '') !== $mime) {
            throw new \InvalidArgumentException('Image invalide ou trop grande (4 096 × 4 096 pixels maximum).');
        }
        $data = file_get_contents($path);
        return ['data'=>$data, 'mime'=>$mime, 'digest'=>hash('sha256',$data)];
    }
}
