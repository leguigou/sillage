<?php
declare(strict_types=1);
namespace App\Models;
use App\Core\DB;
use App\Services\WikiRenderer;

/**
 * Wiki page index (table wiki_pages). The Markdown file on disk holds the current content; this
 * row keeps its path, title, rendered HTML cache, search text, hash and authorship. Writes go
 * through App\Services\Wiki, which keeps files, pages and revisions consistent.
 */
final class WikiPage
{
    private const COLUMNS = 'p.id,p.path,p.path_key,p.title,p.content_hash,p.file_mtime,p.file_size,p.current_revision_no,p.created_by,p.created_at,p.updated_by,p.updated_at,p.deleted_at,p.render_version';

    public static function find(int $id, bool $withDeleted = false): array
    {
        $page = DB::one('SELECT ' . self::COLUMNS . ',p.html,u.name updated_by_name FROM wiki_pages p LEFT JOIN users u ON u.id=p.updated_by WHERE p.id=?' . ($withDeleted ? '' : ' AND p.deleted_at IS NULL'), [$id]);
        if (!$page) throw new \InvalidArgumentException('Page du wiki introuvable.');
        return $page;
    }

    /** Locks the row for the current transaction. */
    public static function lock(int $id): array
    {
        $page = DB::one('SELECT ' . self::COLUMNS . ' FROM wiki_pages p WHERE p.id=? FOR UPDATE', [$id]);
        if (!$page) throw new \InvalidArgumentException('Page du wiki introuvable.');
        return $page;
    }

    public static function byPathKey(string $key): ?array
    {
        return DB::one('SELECT ' . self::COLUMNS . ' FROM wiki_pages p WHERE p.active_path_key=?', [$key]);
    }

    /** Active pages ordered by path, for the tree. */
    public static function all(): array
    {
        return DB::all('SELECT p.id,p.path,p.title,p.updated_at FROM wiki_pages p WHERE p.deleted_at IS NULL ORDER BY p.path_key');
    }

    /** Active pages under a folder ('' = all), for scans and folder moves. */
    public static function under(string $folder): array
    {
        if ($folder === '') return DB::all('SELECT ' . self::COLUMNS . ' FROM wiki_pages p WHERE p.deleted_at IS NULL ORDER BY p.path_key');
        return DB::all('SELECT ' . self::COLUMNS . ' FROM wiki_pages p WHERE p.deleted_at IS NULL AND p.path_key LIKE ? ORDER BY p.path_key', [self::like(mb_strtolower($folder, 'UTF-8')) . '/%']);
    }

    public static function recent(int $limit = 20): array
    {
        return DB::all('SELECT p.id,p.path,p.title,p.updated_at,u.name updated_by_name FROM wiki_pages p LEFT JOIN users u ON u.id=p.updated_by WHERE p.deleted_at IS NULL ORDER BY p.updated_at DESC,p.id DESC LIMIT ' . max(1, min(100, $limit)));
    }

    public static function deleted(): array
    {
        return DB::all('SELECT p.id,p.path,p.title,p.deleted_at,p.current_revision_no FROM wiki_pages p WHERE p.deleted_at IS NOT NULL ORDER BY p.deleted_at DESC,p.id DESC LIMIT 200');
    }

    /**
     * Full-text search in titles and contents (MySQL boolean mode, every word required, prefix match).
     * Words shorter than three characters are ignored by InnoDB; a query made only of such words
     * falls back to a title search. Returns ['rows' => [...], 'total' => int, 'words' => [...]].
     */
    public static function search(string $query, string $folder = '', int $page = 1, int $perPage = 20): array
    {
        $query = trim(mb_substr($query, 0, 200, 'UTF-8'));
        preg_match_all('/[\p{L}\p{N}]+/u', $query, $match);
        $words = array_values(array_unique(array_map(fn($w) => mb_strtolower($w, 'UTF-8'), $match[0])));
        $long = array_values(array_filter($words, fn($w) => mb_strlen($w, 'UTF-8') >= 3));
        if (!$words) return ['rows' => [], 'total' => 0, 'words' => []];
        $where = 'p.deleted_at IS NULL';$params = [];
        if ($folder !== '') { $where .= ' AND p.path_key LIKE ?';$params[] = self::like(mb_strtolower($folder, 'UTF-8')) . '/%'; }
        if ($long) {
            $boolean = implode(' ', array_map(fn($w) => '+' . $w . '*', array_slice($long, 0, 10)));
            $score = 'MATCH(p.title,p.body_text) AGAINST(? IN BOOLEAN MODE)';
            $where .= ' AND ' . $score;
            $order = $score . ' DESC,p.updated_at DESC';
            $countParams = array_merge($params, [$boolean]);
            $rowParams = array_merge([$boolean], $params, [$boolean, $boolean]);
            $select = 'SELECT p.id,p.path,p.title,p.body_text,p.updated_at,' . $score . ' score FROM wiki_pages p WHERE ' . $where;
        } else {
            $where .= ' AND p.title LIKE ?';
            $countParams = array_merge($params, ['%' . self::like($query) . '%']);
            $rowParams = $countParams;
            $order = 'p.updated_at DESC';
            $select = 'SELECT p.id,p.path,p.title,p.body_text,p.updated_at FROM wiki_pages p WHERE ' . $where;
        }
        $total = (int) DB::one('SELECT COUNT(*) n FROM wiki_pages p WHERE ' . $where, $countParams)['n'];
        $offset = (max(1, $page) - 1) * $perPage;
        $rows = DB::all($select . ' ORDER BY ' . $order . ' LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset, $rowParams);
        foreach ($rows as &$row) { $row['snippet'] = self::snippet($row['body_text'], $words);unset($row['body_text']); }
        return ['rows' => $rows, 'total' => $total, 'words' => $words];
    }

    /** About 220 characters of text around the first matching word. */
    public static function snippet(string $text, array $words, int $length = 220): string
    {
        $folded = WikiRenderer::fold($text);
        $position = null;
        foreach ($words as $word) {
            $found = mb_strpos($folded, WikiRenderer::fold($word), 0, 'UTF-8');
            if ($found !== false && ($position === null || $found < $position)) $position = $found;
        }
        $start = max(0, ($position ?? 0) - 60);
        $snippet = mb_substr($text, $start, $length, 'UTF-8');
        return ($start > 0 ? '…' : '') . $snippet . ($start + $length < mb_strlen($text, 'UTF-8') ? '…' : '');
    }

    /** Escaped snippet with matching words wrapped in <mark> (accent and case insensitive). */
    public static function highlight(string $snippet, array $words): string
    {
        $folded = WikiRenderer::fold($snippet);
        $marks = array_fill(0, mb_strlen($snippet, 'UTF-8'), false);
        foreach ($words as $word) {
            $needle = WikiRenderer::fold($word);$length = mb_strlen($needle, 'UTF-8');$offset = 0;
            if ($length === 0) continue;
            while (($found = mb_strpos($folded, $needle, $offset, 'UTF-8')) !== false) {
                for ($k = $found; $k < $found + $length && $k < count($marks); $k++) $marks[$k] = true;
                $offset = $found + $length;
            }
        }
        $html = '';$open = false;
        foreach (mb_str_split($snippet, 1, 'UTF-8') as $k => $char) {
            if ($marks[$k] && !$open) { $html .= '<mark>';$open = true; }
            if (!$marks[$k] && $open) { $html .= '</mark>';$open = false; }
            $html .= e($char);
        }
        return $html . ($open ? '</mark>' : '');
    }

    /** Cached HTML, rendered again when the renderer changed since it was stored. */
    public static function html(array $page, string $content): string
    {
        if ((int) $page['render_version'] === WikiRenderer::VERSION && isset($page['html'])) return (string) $page['html'];
        $rendered = WikiRenderer::render($content);
        DB::run('UPDATE wiki_pages SET html=?,body_text=?,render_version=? WHERE id=?', [$rendered['html'], $rendered['text'], WikiRenderer::VERSION, $page['id']]);
        return $rendered['html'];
    }

    public static function like(string $value): string
    {
        return addcslashes($value, '\\%_');
    }
}
