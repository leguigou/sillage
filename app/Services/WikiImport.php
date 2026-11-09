<?php
declare(strict_types=1);
namespace App\Services;
use App\Models\WikiAsset;

/**
 * Converts an uploaded document into wiki Markdown.
 *
 * Supported (detected from the bytes, never from the file name):
 * - MHTML (multipart/related): Confluence "Export to Word" (.doc), saved web pages (.mht, .mhtml);
 *   the HTML part is converted by HtmlToMarkdown and its embedded images become wiki images;
 * - HTML (.html, .htm): images that are not embedded cannot be imported and are reported;
 * - Markdown or plain text (.md, .txt), kept as is.
 * Real Word files (.docx, binary .doc) and PDF are refused with an explanation.
 *
 * The file is untrusted: parts, sizes and nesting are bounded, images are validated by WikiAsset
 * (type from the bytes, SVG refused) and the HTML is never output: it only becomes Markdown,
 * rendered later by WikiRenderer in safe mode.
 */
final class WikiImport
{
    public const MAX_PARTS = 300;
    public const MAX_HTML = 5242880;

    public static function maxBytes(): int
    {
        return max(1, min(15, (int) env('UPLOAD_MAX_MB', '10'))) * 1024 * 1024;
    }

    /**
     * @param callable(string $data, string $label): ?string $storeImage stores validated image bytes, returns the asset name
     * @return array{format: string, title: string, markdown: string, images: int, warnings: list<string>}
     */
    public static function convert(string $data, string $fileName, callable $storeImage): array
    {
        if ($data === '') throw new \InvalidArgumentException('Le fichier est vide.');
        if (strlen($data) > self::maxBytes()) throw new \InvalidArgumentException('Le fichier dépasse ' . intdiv(self::maxBytes(), 1048576) . ' Mo.');
        $fallbackTitle = self::titleFromFileName($fileName);
        $head = substr($data, 0, 4096);
        if (str_starts_with($data, '%PDF')) throw new \InvalidArgumentException('Les PDF ne sont pas encore pris en charge. Depuis Confluence, exportez plutôt la page au format Word.');
        if (str_starts_with($data, "PK\x03\x04")) throw new \InvalidArgumentException('Les fichiers Word .docx ne sont pas encore pris en charge. Depuis Confluence, utilisez « Exporter → Word » (fichier .doc), ou copiez-collez le texte.');
        if (str_starts_with($data, "\xD0\xCF\x11\xE0")) throw new \InvalidArgumentException('Ce fichier Word (.doc binaire) n’est pas pris en charge. Seuls les .doc exportés depuis Confluence le sont.');

        if (preg_match('/^MIME-Version:/im', $head) && preg_match('/^Content-Type:\s*multipart\/related/im', $head)) {
            $parts = self::mhtml($data);
            $html = null;$resources = [];
            foreach ($parts as $part) {
                if ($html === null && $part['type'] === 'text/html') { $html = self::utf8($part['body'], $part['charset']);continue; }
                if ($part['location'] !== '') { $resources[$part['location']] = $part['body'];$resources[self::basename($part['location'])] ??= $part['body']; }
                if ($part['id'] !== '') $resources['cid:' . $part['id']] = $part['body'];
            }
            if ($html === null) throw new \InvalidArgumentException('Aucune page HTML dans ce fichier.');
            return self::fromHtml($html, $fallbackTitle, 'confluence', $resources, $storeImage);
        }
        if (preg_match('/<(?:!doctype\s+html|html[\s>]|body[\s>]|h1[\s>]|p[\s>]|div[\s>]|table[\s>])/i', $head)) {
            $charset = preg_match('/<meta[^>]+charset=["\']?([\w-]+)/i', $head, $m) ? $m[1] : 'UTF-8';
            return self::fromHtml(self::utf8($data, $charset), $fallbackTitle, 'html', [], $storeImage);
        }
        if (!mb_check_encoding($data, 'UTF-8')) {
            $data = self::utf8($data, 'Windows-1252');
        }
        if (preg_match('/[\x00-\x08\x0E-\x1F]/', substr($data, 0, 8192))) throw new \InvalidArgumentException('Format non reconnu. Formats acceptés : export Word de Confluence (.doc), .mht, .html, .md ou .txt.');
        $markdown = WikiStorage::normalizeContent($data);
        return ['format' => 'markdown', 'title' => WikiRenderer::title($markdown) ?? $fallbackTitle, 'markdown' => $markdown, 'images' => 0, 'warnings' => []];
    }

    /** Stores images through WikiAsset; any refusal becomes a warning instead of aborting the import. */
    public static function storeWithAssets(?int $user): callable
    {
        return static function (string $data, string $label) use ($user): ?string {
            return WikiAsset::store($data, $user);
        };
    }

    /** Puts $title as the first level-1 heading of $markdown (replacing an existing one at the top). */
    public static function withTitle(string $markdown, string $title): string
    {
        $title = trim(preg_replace('/\s+/u', ' ', $title) ?? '');
        if ($title === '') return $markdown;
        $heading = '# ' . str_replace(["\n", "\r"], ' ', $title);
        if (preg_match('/\A\s*#[ \t]+[^\n]*(\n|\z)/', $markdown)) return preg_replace('/\A\s*#[ \t]+[^\n]*/', addcslashes($heading, '\\$'), $markdown, 1) ?? $markdown;
        return $heading . "\n\n" . ltrim($markdown);
    }

    private static function fromHtml(string $html, string $fallbackTitle, string $format, array $resources, callable $storeImage): array
    {
        if (strlen($html) > self::MAX_HTML) throw new \InvalidArgumentException('La page HTML est trop volumineuse (5 Mo au maximum).');
        $warnings = [];$images = 0;
        $resolver = function (string $src, string $label) use ($resources, $storeImage, &$warnings, &$images): ?string {
            $data = $resources[$src] ?? $resources[self::basename($src)] ?? null;
            if ($data === null) {
                $warnings[] = 'Image « ' . $label . ' » absente du fichier : elle n’a pas été importée.';
                return null;
            }
            try {
                $name = $storeImage($data, $label);
                if ($name !== null) $images++;
                return $name;
            } catch (\InvalidArgumentException $error) {
                $warnings[] = 'Image « ' . $label . ' » ignorée : ' . $error->getMessage();
                return null;
            }
        };
        $converter = new HtmlToMarkdown($resolver);
        $result = $converter->convert($html);
        $warnings = array_values(array_unique(array_merge($warnings, $converter->warnings())));
        $title = $result['title'] ?? $fallbackTitle;
        $markdown = WikiStorage::normalizeContent(self::withTitle($result['markdown'], WikiRenderer::title($result['markdown']) ?? $title));
        return ['format' => $format, 'title' => WikiRenderer::title($markdown) ?? $title, 'markdown' => $markdown, 'images' => $images, 'warnings' => $warnings];
    }

    /**
     * Splits an MHTML document into decoded parts: ['type', 'charset', 'location', 'id', 'body'].
     * Only one level of multipart is read; transfer encodings: base64, quoted-printable, 7bit, 8bit, binary.
     */
    public static function mhtml(string $data): array
    {
        $data = str_replace("\r\n", "\n", $data);
        [$headers, $body] = self::splitHeaders($data);
        if (!preg_match('/boundary="?([^";\n]+)"?/i', $headers['content-type'] ?? '', $match)) throw new \InvalidArgumentException('Fichier MHTML invalide : séparateur absent.');
        $boundary = '--' . trim($match[1]);
        $chunks = explode("\n" . $boundary, "\n" . $body);
        array_shift($chunks);
        $parts = [];
        foreach ($chunks as $chunk) {
            if (str_starts_with($chunk, '--')) break;
            if (count($parts) >= self::MAX_PARTS) throw new \InvalidArgumentException('Fichier MHTML trop complexe (' . self::MAX_PARTS . ' éléments au maximum).');
            [$partHeaders, $content] = self::splitHeaders(ltrim($chunk, " \t\n"));
            $type = strtolower(trim(explode(';', $partHeaders['content-type'] ?? 'text/plain')[0]));
            $charset = preg_match('/charset="?([\w-]+)"?/i', $partHeaders['content-type'] ?? '', $c) ? $c[1] : 'UTF-8';
            $encoding = strtolower(trim($partHeaders['content-transfer-encoding'] ?? '7bit'));
            $decoded = match ($encoding) {
                'base64' => base64_decode(preg_replace('/\s+/', '', $content) ?? '', true),
                'quoted-printable' => quoted_printable_decode(str_replace("\n", "\r\n", $content)),
                default => $content,
            };
            if ($decoded === false) continue;
            $parts[] = ['type' => $type, 'charset' => $charset, 'location' => trim($partHeaders['content-location'] ?? ''), 'id' => trim($partHeaders['content-id'] ?? '', " <>\t"), 'body' => $decoded];
        }
        return $parts;
    }

    /** Header block (unfolded, lower-case names) and body of a MIME entity. */
    private static function splitHeaders(string $entity): array
    {
        $position = strpos($entity, "\n\n");
        $raw = $position === false ? $entity : substr($entity, 0, $position);
        $body = $position === false ? '' : substr($entity, $position + 2);
        $raw = preg_replace('/\n[ \t]+/', ' ', $raw) ?? $raw;
        $headers = [];
        foreach (explode("\n", $raw) as $line) {
            if (preg_match('/^([\w-]+):\s*(.*)$/', $line, $m)) $headers[strtolower($m[1])] ??= $m[2];
        }
        return [$headers, $body];
    }

    private static function utf8(string $text, string $charset): string
    {
        $charset = strtoupper($charset);
        if ($charset === 'UTF-8' || $charset === 'UTF8') return mb_check_encoding($text, 'UTF-8') ? $text : mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        $map = ['ISO-8859-1' => 'ISO-8859-1', 'LATIN1' => 'ISO-8859-1', 'WINDOWS-1252' => 'Windows-1252', 'CP1252' => 'Windows-1252', 'ISO-8859-15' => 'ISO-8859-15', 'US-ASCII' => 'ASCII'];
        return mb_convert_encoding($text, 'UTF-8', $map[$charset] ?? 'Windows-1252');
    }

    private static function basename(string $location): string
    {
        $location = preg_replace('/[?#].*$/', '', $location) ?? $location;
        $position = max(strrpos($location, '/') ?: -1, strrpos($location, '\\') ?: -1);
        return rawurldecode(substr($location, $position + 1));
    }

    private static function titleFromFileName(string $name): string
    {
        $name = preg_replace('/\.[A-Za-z0-9]{1,6}$/', '', basename(str_replace('\\', '/', $name))) ?? '';
        $name = trim(preg_replace('/[_\s]+/u', ' ', $name) ?? '');
        return $name !== '' && mb_check_encoding($name, 'UTF-8') ? mb_substr($name, 0, 200, 'UTF-8') : 'Page importée';
    }
}
