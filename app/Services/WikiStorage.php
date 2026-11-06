<?php
declare(strict_types=1);
namespace App\Services;

/**
 * Filesystem side of the wiki: the Markdown files under WIKI_PATH (default storage/wiki) are the
 * reference for current content and folders. Every path received from a browser, the CLI or a
 * scan goes through relativePath()/segment() before touching the disk; absolute() then checks that
 * the target stays below the root and that no existing component is a symbolic link.
 *
 * Writes are two-phase (prepare() a temporary file in the target folder, then commit() it with an
 * atomic rename) so callers can commit the database transaction in between, as described in
 * docs/wiki.md. Names starting with "." or "_" are reserved: temporary files use a leading dot,
 * "_assets" is kept for images, and both are ignored by tree() and scans.
 */
final class WikiStorage
{
    public const MAX_BYTES = 524288;
    public const MAX_SEGMENT = 120;
    public const MAX_PATH = 500;
    public const MAX_DEPTH = 8;
    private const RESERVED = '/^(con|prn|aux|nul|com[1-9]|lpt[1-9])$/i';
    private static ?string $root = null;

    /** Absolute root directory, created on first use with private permissions. */
    public static function root(): string
    {
        if (self::$root !== null) return self::$root;
        $configured = trim(env('WIKI_PATH', ''));
        $path = $configured === '' ? ROOT . '/storage/wiki' : $configured;
        if (!preg_match('#^([a-zA-Z]:)?[\\\\/]#', $path)) $path = ROOT . '/' . $path;
        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
            throw new \RuntimeException('Dossier du wiki impossible à créer.');
        }
        $real = realpath($path);
        if ($real === false || is_link($path)) throw new \RuntimeException('Dossier du wiki invalide.');
        return self::$root = str_replace('\\', '/', $real);
    }

    /** For tests and wiki:rebuild: use another root (null restores the configured one). */
    public static function useRoot(?string $path): void
    {
        self::$root = null;
        if ($path !== null) {
            if (!is_dir($path)) mkdir($path, 0700, true);
            self::$root = str_replace('\\', '/', (string) realpath($path));
        }
    }

    /** NFC form when the intl extension is present; names are kept as typed otherwise. */
    public static function nfc(string $value): string
    {
        return class_exists(\Normalizer::class) ? (\Normalizer::normalize($value, \Normalizer::FORM_C) ?: $value) : $value;
    }

    /** Case-insensitive comparison key used for uniqueness (Windows and macOS ignore case). */
    public static function key(string $relative): string
    {
        return mb_strtolower($relative, 'UTF-8');
    }

    /** Validates and normalizes one file or folder name. */
    public static function segment(string $name): string
    {
        $name = self::nfc($name);
        if (!mb_check_encoding($name, 'UTF-8')) throw new \InvalidArgumentException('Nom invalide : encodage non UTF-8.');
        if ($name === '' || $name === '.' || $name === '..') throw new \InvalidArgumentException('Le nom ne peut pas être vide.');
        if (preg_match('/[\\\\\/:*?"<>|\x00-\x1F\x7F]/u', $name)) throw new \InvalidArgumentException('Le nom « ' . $name . ' » contient un caractère interdit (\ / : * ? " < > |).');
        if ($name[0] === '.' || $name[0] === '_') throw new \InvalidArgumentException('Un nom ne peut pas commencer par « . » ou « _ ».');
        if (preg_match('/[. ]$/u', $name) || preg_match('/^\s/u', $name)) throw new \InvalidArgumentException('Un nom ne peut pas commencer par une espace ni finir par un point ou une espace.');
        if (mb_strlen($name, 'UTF-8') > self::MAX_SEGMENT) throw new \InvalidArgumentException('Un nom est limité à ' . self::MAX_SEGMENT . ' caractères.');
        if (preg_match(self::RESERVED, explode('.', $name)[0])) throw new \InvalidArgumentException('« ' . $name . ' » est un nom réservé par Windows.');
        return $name;
    }

    /** Turns a page title into a valid file name (without extension), replacing forbidden characters. */
    public static function fileNameFromTitle(string $title): string
    {
        $name = preg_replace('/[\\\\\/:*?"<>|\x00-\x1F\x7F]+/u', ' ', self::nfc(trim($title))) ?? '';
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        $name = ltrim($name, '._ ');
        $name = rtrim($name, '. ');
        if (mb_strlen($name, 'UTF-8') > self::MAX_SEGMENT - 3) $name = rtrim(mb_substr($name, 0, self::MAX_SEGMENT - 3, 'UTF-8'), '. ');
        if ($name === '') throw new \InvalidArgumentException('Le titre doit contenir au moins une lettre ou un chiffre.');
        if (preg_match(self::RESERVED, $name)) $name .= ' (page)';
        return self::segment($name);
    }

    /**
     * Normalizes a relative path ('' is the root folder). $file requires a final ".md" segment.
     * Rejects absolute paths, "..", backslashes, empty segments, excessive depth or length.
     */
    public static function relativePath(string $path, bool $file = false): string
    {
        $path = trim($path);
        if ($path === '' && !$file) return '';
        if (str_contains($path, '\\') || str_contains($path, "\0")) throw new \InvalidArgumentException('Chemin invalide.');
        if (str_starts_with($path, '/') || preg_match('#^[a-zA-Z]:#', $path)) throw new \InvalidArgumentException('Chemin invalide : il doit être relatif au wiki.');
        $parts = explode('/', $path);
        $clean = [];
        foreach ($parts as $index => $part) {
            if ($part === '..' || $part === '.') throw new \InvalidArgumentException('Chemin invalide.');
            if ($file && $index === count($parts) - 1) {
                if (!preg_match('/\.md$/iD', $part)) throw new \InvalidArgumentException('Seuls les fichiers .md sont acceptés.');
                $part = substr($part, 0, -3) . '.md';
            }
            $clean[] = self::segment($part);
        }
        if (count($clean) - ($file ? 1 : 0) > self::MAX_DEPTH) throw new \InvalidArgumentException('Le wiki est limité à ' . self::MAX_DEPTH . ' niveaux de dossiers.');
        $result = implode('/', $clean);
        if (mb_strlen($result, 'UTF-8') > self::MAX_PATH) throw new \InvalidArgumentException('Chemin trop long (' . self::MAX_PATH . ' caractères au maximum).');
        return $result;
    }

    public static function join(string $folder, string $name): string
    {
        return $folder === '' ? $name : $folder . '/' . $name;
    }

    public static function parent(string $relative): string
    {
        $position = strrpos($relative, '/');
        return $position === false ? '' : substr($relative, 0, $position);
    }

    public static function name(string $relative): string
    {
        $position = strrpos($relative, '/');
        return $position === false ? $relative : substr($relative, $position + 1);
    }

    /**
     * Absolute path for a validated relative path. Every existing component is checked: none may be
     * a symbolic link, and the deepest existing one must resolve below the root.
     */
    public static function absolute(string $relative): string
    {
        $root = self::root();
        $current = $root;
        foreach ($relative === '' ? [] : explode('/', $relative) as $part) {
            $current .= '/' . $part;
            if (is_link($current)) throw new \InvalidArgumentException('Les liens symboliques ne sont pas suivis dans le wiki.');
            if (!file_exists($current)) break;
            $real = realpath($current);
            if ($real === false || !self::inside(str_replace('\\', '/', $real), $root)) throw new \InvalidArgumentException('Chemin hors du wiki refusé.');
        }
        return $relative === '' ? $root : $root . '/' . $relative;
    }

    private static function inside(string $path, string $root): bool
    {
        $a = rtrim(self::key($path), '/');$b = rtrim(self::key($root), '/');
        return $a === $b || str_starts_with($a, $b . '/');
    }

    /**
     * Normalized content for storage: UTF-8 without BOM, LF line endings, one final newline,
     * at most MAX_BYTES. Throws on invalid encoding or size.
     */
    public static function normalizeContent(string $content): string
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) $content = substr($content, 3);
        if (!mb_check_encoding($content, 'UTF-8')) throw new \InvalidArgumentException('Le contenu n’est pas un texte UTF-8 valide.');
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $content = rtrim($content, "\n") . "\n";
        if ($content === "\n") $content = '';
        if (strlen($content) > self::MAX_BYTES) throw new \InvalidArgumentException('Une page est limitée à 512 Ko.');
        return $content;
    }

    public static function hash(string $content): string
    {
        return hash('sha256', $content);
    }

    /** Raw file content, or null when the file does not exist. Never follows links. */
    public static function read(string $relative): ?string
    {
        $file = self::absolute($relative);
        if (!is_file($file)) return null;
        $size = filesize($file);
        if ($size === false || $size > self::MAX_BYTES + 4) throw new \InvalidArgumentException('Fichier trop volumineux pour le wiki (512 Ko au maximum).');
        $content = file_get_contents($file);
        if ($content === false) throw new \RuntimeException('Lecture du fichier impossible.');
        return $content;
    }

    /** [mtime, size] of a file, or null when missing. */
    public static function stat(string $relative): ?array
    {
        $file = self::absolute($relative);
        clearstatcache(true, $file);
        if (!is_file($file)) return null;
        return [(int) filemtime($file), (int) filesize($file)];
    }

    public static function exists(string $relative): bool
    {
        return file_exists(self::absolute($relative));
    }

    public static function isFolder(string $relative): bool
    {
        return is_dir(self::absolute($relative));
    }

    /** True when $folder already holds an entry whose name equals $name ignoring case. */
    public static function nameTaken(string $folder, string $name, ?string $except = null): bool
    {
        $directory = self::absolute($folder);
        if (!is_dir($directory)) return false;
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $entry = self::nfc($entry);
            if ($except !== null && self::key($entry) === self::key($except)) continue;
            if (self::key($entry) === self::key($name)) return true;
        }
        return false;
    }

    /** Writes $content to a temporary file next to $relative and returns its absolute path. */
    public static function prepare(string $relative, string $content): string
    {
        $folder = self::absolute(self::parent($relative));
        if (!is_dir($folder)) throw new \InvalidArgumentException('Le dossier de destination n’existe pas.');
        $temp = $folder . '/.wiki-' . bin2hex(random_bytes(8)) . '.tmp';
        if (file_put_contents($temp, $content, LOCK_EX) !== strlen($content)) {
            @unlink($temp);
            throw new \RuntimeException('Écriture du fichier temporaire impossible.');
        }
        return $temp;
    }

    /** Atomically replaces $relative with a file produced by prepare(). */
    public static function commit(string $temp, string $relative): void
    {
        $target = self::absolute($relative);
        if (!@rename($temp, $target)) {
            @unlink($temp);
            throw new \RuntimeException('Le fichier n’a pas pu être remplacé.');
        }
    }

    public static function discard(?string $temp): void
    {
        if ($temp !== null && is_file($temp)) @unlink($temp);
    }

    /** Renames a file or folder; the destination must not exist. */
    public static function move(string $from, string $to): void
    {
        $source = self::absolute($from);$target = self::absolute($to);
        if (!file_exists($source)) throw new \InvalidArgumentException('L’élément à déplacer est introuvable sur le disque.');
        if (!is_dir(dirname($target))) throw new \InvalidArgumentException('Le dossier de destination n’existe pas.');
        $sameEntry = self::key($from) === self::key($to);
        if (!$sameEntry && (file_exists($target) || self::nameTaken(self::parent($to), self::name($to)))) throw new \InvalidArgumentException('Un élément porte déjà ce nom à cet emplacement.');
        if ($sameEntry && $from !== $to) {
            // Case-only rename: go through a temporary name for case-insensitive filesystems.
            $step = dirname($source) . '/.wiki-' . bin2hex(random_bytes(8)) . '.move';
            if (!@rename($source, $step) || !@rename($step, $target)) throw new \RuntimeException('Renommage impossible.');
            return;
        }
        if (!@rename($source, $target)) throw new \RuntimeException('Déplacement impossible.');
    }

    /** Moves a file aside (hidden name) before a delete is committed; returns the hidden path. */
    public static function stash(string $relative): ?string
    {
        $source = self::absolute($relative);
        if (!is_file($source)) return null;
        $hidden = dirname($source) . '/.wiki-' . bin2hex(random_bytes(8)) . '.deleted';
        if (!@rename($source, $hidden)) throw new \RuntimeException('Suppression du fichier impossible.');
        return $hidden;
    }

    public static function unstash(?string $hidden, string $relative): void
    {
        if ($hidden !== null && is_file($hidden)) @rename($hidden, self::absolute($relative));
    }

    public static function makeFolder(string $relative): void
    {
        $path = self::absolute($relative);
        // Linux file systems are case-sensitive: check the name ignoring case, as Windows would.
        if (file_exists($path) || self::nameTaken(self::parent($relative), self::name($relative))) throw new \InvalidArgumentException('Un élément porte déjà ce nom.');
        if (!is_dir(dirname($path))) throw new \InvalidArgumentException('Le dossier parent n’existe pas.');
        if (!mkdir($path, 0700)) throw new \RuntimeException('Création du dossier impossible.');
    }

    /** Removes an empty folder; hidden leftovers of interrupted operations do not count as content. */
    public static function removeFolder(string $relative): void
    {
        if ($relative === '') throw new \InvalidArgumentException('Le dossier racine ne peut pas être supprimé.');
        $path = self::absolute($relative);
        if (!is_dir($path)) throw new \InvalidArgumentException('Dossier introuvable.');
        $entries = array_diff(scandir($path) ?: [], ['.', '..']);
        foreach ($entries as $entry) {
            if (!preg_match('/^\.wiki-[0-9a-f]{16}\.(tmp|deleted|move)$/D', $entry)) throw new \InvalidArgumentException('Le dossier n’est pas vide.');
        }
        foreach ($entries as $entry) @unlink($path . '/' . $entry);
        if (!rmdir($path)) throw new \RuntimeException('Suppression du dossier impossible.');
    }

    /**
     * Walks the wiki and returns ['folders' => [relative...], 'files' => [relative...], 'rejected' => [[path, reason]...]].
     * Hidden and reserved entries are skipped; links and invalid names are reported, never followed.
     */
    public static function tree(): array
    {
        $result = ['folders' => [], 'files' => [], 'rejected' => []];
        self::walk('', 0, $result);
        sort($result['folders'], SORT_STRING | SORT_FLAG_CASE);
        sort($result['files'], SORT_STRING | SORT_FLAG_CASE);
        return $result;
    }

    private static function walk(string $folder, int $depth, array &$result): void
    {
        $directory = self::absolute($folder);
        $seen = [];
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || $entry[0] === '.' || $entry[0] === '_') continue;
            $relative = self::join($folder, $entry);
            $absolute = $directory . '/' . $entry;
            if (is_link($absolute)) { $result['rejected'][] = [$relative, 'lien symbolique ignoré']; continue; }
            $isFolder = is_dir($absolute);
            if (!$isFolder && !preg_match('/\.md$/iD', $entry)) continue;
            try {
                $clean = self::relativePath($relative, !$isFolder);
            } catch (\InvalidArgumentException $error) {
                $result['rejected'][] = [$relative, $error->getMessage()];
                continue;
            }
            if ($clean !== $relative && !$isFolder && substr($relative, -3) !== '.md') {
                $result['rejected'][] = [$relative, 'extension à écrire en minuscules (.md)'];
                continue;
            }
            $key = self::key($entry);
            if (isset($seen[$key])) { $result['rejected'][] = [$relative, 'nom en double si on ignore les majuscules']; continue; }
            $seen[$key] = true;
            if ($isFolder) {
                if ($depth >= self::MAX_DEPTH) { $result['rejected'][] = [$relative, 'trop de niveaux de dossiers']; continue; }
                $result['folders'][] = $relative;
                self::walk($relative, $depth + 1, $result);
            } else {
                $result['files'][] = $relative;
            }
        }
    }
}
