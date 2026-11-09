<?php
declare(strict_types=1);
namespace App\Models;
use App\Core\DB;

/**
 * Immutable history of wiki pages (table wiki_revisions): one full Markdown copy per change, with
 * the action (create, edit, move, delete, restore, external, import), its source, the author's name as it
 * was at that time, and line counts. Revisions are only appended, inside the transaction that
 * changes the page.
 */
final class WikiRevision
{
    public const ACTIONS = ['create' => 'Création', 'edit' => 'Modification', 'move' => 'Renommage ou déplacement', 'delete' => 'Suppression', 'restore' => 'Restauration', 'external' => 'Modification externe', 'import' => 'Import'];
    public const EXTERNAL_AUTHOR = 'Modification externe';

    /** Appends a revision; must run inside the page's transaction after WikiPage::lock(). */
    public static function append(int $page, int $number, array $data): int
    {
        return DB::insert(
            'INSERT INTO wiki_revisions(page_id,revision_no,action,source,path_before,path_after,content,content_hash,lines_added,lines_removed,message,user_id,user_name,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())',
            [$page, $number, $data['action'], $data['source'], $data['path_before'] ?? null, $data['path_after'] ?? null, $data['content'], $data['hash'], $data['added'] ?? 0, $data['removed'] ?? 0, $data['message'] ?? null, $data['user_id'] ?? null, $data['user_name']],
        );
    }

    /** Revisions of a page, newest first, without their content. */
    public static function forPage(int $page): array
    {
        return DB::all('SELECT id,revision_no,action,source,path_before,path_after,content_hash,lines_added,lines_removed,message,user_id,user_name,created_at FROM wiki_revisions WHERE page_id=? ORDER BY revision_no DESC', [$page]);
    }

    public static function byNumber(int $page, int $number): array
    {
        $revision = DB::one('SELECT * FROM wiki_revisions WHERE page_id=? AND revision_no=?', [$page, $number]);
        if (!$revision) throw new \InvalidArgumentException('Version introuvable.');
        return $revision;
    }

    /** Content of the latest revision (the reference used to repair or rebuild files). */
    public static function latest(int $page): ?array
    {
        return DB::one('SELECT * FROM wiki_revisions WHERE page_id=? ORDER BY revision_no DESC LIMIT 1', [$page]);
    }

    /** Hash of the revision before the latest one that changed the content (to recognize an interrupted write). */
    public static function previousHash(int $page, string $currentHash): ?string
    {
        $row = DB::one('SELECT content_hash FROM wiki_revisions WHERE page_id=? AND content_hash<>? ORDER BY revision_no DESC LIMIT 1', [$page, $currentHash]);
        return $row['content_hash'] ?? null;
    }
}
