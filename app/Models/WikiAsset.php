<?php
declare(strict_types=1);
namespace App\Models;
use App\Core\DB;

/**
 * Images of wiki pages (table wiki_assets). The name is the SHA-256 of the bytes plus the extension,
 * so an image is never replaced: every revision that references it keeps displaying it. The table is
 * the reference copy (served by GET /wiki/assets/{name}, carried by site:export, used by
 * wiki:rebuild); each page folder also holds a readable copy under "_assets/" (WikiStorage).
 * Types are detected from the bytes: PNG, JPEG, WebP or GIF. SVG is refused (it can embed scripts).
 */
final class WikiAsset
{
    public const NAME = '/^[a-f0-9]{64}\.(png|jpg|webp|gif)$/D';
    public const TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    public const MAX_SIDE = 10000;

    public static function maxBytes(): int
    {
        return max(1, min(15, (int) env('UPLOAD_MAX_MB', '10'))) * 1024 * 1024;
    }

    /** Validates image bytes; returns ['name', 'mime', 'width', 'height']. */
    public static function inspect(string $data): array
    {
        if ($data === '' || strlen($data) > self::maxBytes()) throw new \InvalidArgumentException('L’image doit peser au maximum ' . intdiv(self::maxBytes(), 1048576) . ' Mo.');
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($data);
        if (!isset(self::TYPES[$mime])) throw new \InvalidArgumentException('Utilisez une image PNG, JPEG, WebP ou GIF.');
        $size = @getimagesizefromstring($data);
        if (!$size || ($size['mime'] ?? '') !== $mime || $size[0] < 1 || $size[1] < 1 || $size[0] > self::MAX_SIDE || $size[1] > self::MAX_SIDE) {
            throw new \InvalidArgumentException('Image invalide ou trop grande (' . number_format(self::MAX_SIDE, 0, ',', ' ') . ' pixels de côté au maximum).');
        }
        return ['name' => hash('sha256', $data) . '.' . self::TYPES[$mime], 'mime' => $mime, 'width' => $size[0], 'height' => $size[1]];
    }

    /** Stores validated bytes once (identical images share one row). Returns the asset name. */
    public static function store(string $data, ?int $user): string
    {
        $info = self::inspect($data);
        DB::run('INSERT IGNORE INTO wiki_assets(name,mime,bytes,width,height,data,created_by,created_at) VALUES(?,?,?,?,?,?,?,UTC_TIMESTAMP())', [$info['name'], $info['mime'], strlen($data), $info['width'], $info['height'], $data, $user]);
        return $info['name'];
    }

    /** ['name','mime','data'] or null. */
    public static function find(string $name): ?array
    {
        if (!preg_match(self::NAME, $name)) return null;
        return DB::one('SELECT name,mime,data FROM wiki_assets WHERE name=?', [$name]);
    }

    public static function exists(string $name): bool
    {
        return preg_match(self::NAME, $name) === 1 && DB::one('SELECT 1 x FROM wiki_assets WHERE name=?', [$name]) !== null;
    }

    /** Asset names referenced as "_assets/<name>" in Markdown. */
    public static function referenced(string $markdown): array
    {
        preg_match_all('#_assets/([a-f0-9]{64}\.(?:png|jpg|webp|gif))\b#', $markdown, $match);
        return array_values(array_unique($match[1]));
    }
}
