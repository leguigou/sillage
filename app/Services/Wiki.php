<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\DB;
use App\Models\{WikiAsset, WikiPage, WikiRevision};

/**
 * Wiki operations keeping the Markdown files, wiki_pages and wiki_revisions consistent.
 *
 * Order of writes (see docs/wiki.md): the new content is first written to a temporary file next to
 * its target, then the database transaction locks the page, checks the base revision and appends
 * the revision; only after the commit is the temporary file renamed over the page. If that rename
 * fails, the file still holds the previous revision: sync() recognizes this case by its hash and
 * rewrites the file instead of recording an external change. Moves and deletions do the disk step
 * first and undo it if the transaction fails.
 */
final class Wiki
{
    /** Thrown when the page changed since the editor loaded it. */
    public const CONFLICT = 409;

    private static function author(?array $user): array
    {
        return $user ? ['user_id' => (int) $user['id'], 'user_name' => mb_substr((string) $user['name'], 0, 190, 'UTF-8'), 'source' => 'application']
            : ['user_id' => null, 'user_name' => WikiRevision::EXTERNAL_AUTHOR, 'source' => 'external'];
    }

    /** Title shown for a page: its first level-1 heading, else the file name. */
    public static function titleOf(string $path, string $content): string
    {
        return WikiRenderer::title($content) ?? mb_substr(preg_replace('/\.md$/', '', WikiStorage::name($path)) ?? $path, 0, 255, 'UTF-8');
    }

    /** Columns derived from the content (title, HTML cache, search text, hash, size). */
    private static function derived(string $path, string $content): array
    {
        $rendered = WikiRenderer::render($content);
        return ['title' => self::titleOf($path, $content), 'html' => $rendered['html'], 'text' => $rendered['text'], 'hash' => WikiStorage::hash($content)];
    }

    /** Records the file's mtime/size after a write, so the next sync() can skip hashing. */
    private static function remember(int $id, string $path): void
    {
        $stat = WikiStorage::stat($path);
        if ($stat) DB::run('UPDATE wiki_pages SET file_mtime=?,file_size=? WHERE id=?', [$stat[0], $stat[1], $id]);
    }

    /**
     * Writes the readable "_assets" copies of the images referenced by $markdown into $folder, from
     * wiki_assets. Copies are a convenience for people browsing the folder: a failure never undoes
     * the operation that called this.
     */
    private static function ensureAssets(string $folder, string ...$markdown): void
    {
        foreach (array_unique(array_merge(...array_map([WikiAsset::class, 'referenced'], $markdown ?: ['']))) as $name) {
            try {
                if (WikiStorage::assetFiles($folder)[$name] ?? null) continue;
                $asset = WikiAsset::find($name);
                if ($asset) WikiStorage::writeAsset($folder, $name, $asset['data']);
            } catch (\Throwable $error) {
                error_log('[' . gmdate('c') . '] Wiki : copie de l’image ' . $name . ' impossible dans « ' . $folder . ' ».' . PHP_EOL, 3, ROOT . '/storage/error.log');
            }
        }
    }

    /** Stores an image sent from the editor of page $id; returns its name (referenced as "_assets/<name>"). */
    public static function uploadImage(array $user, int $id, string $data): string
    {
        $page = WikiPage::find($id);
        $name = WikiAsset::store($data, (int) $user['id']);
        self::ensureAssets(WikiStorage::parent($page['path']), '_assets/' . $name);
        return $name;
    }

    private static function revisionContents(int $id): array
    {
        return array_column(DB::all('SELECT content FROM wiki_revisions WHERE page_id=?', [$id]), 'content');
    }

    private static function ensureFolder(string $folder): string
    {
        $folder = WikiStorage::relativePath($folder);
        if (!WikiStorage::isFolder($folder)) throw new \InvalidArgumentException('Ce dossier n’existe pas.');
        return $folder;
    }

    /** $onDisk = false when indexing a file that already exists (scans): only the index is checked. */
    private static function ensureFree(string $path, ?int $except = null, bool $onDisk = true): void
    {
        $existing = WikiPage::byPathKey(WikiStorage::key($path));
        if ($existing && (int) $existing['id'] !== $except) throw new \InvalidArgumentException('Une page porte déjà ce nom dans ce dossier.');
        if (!$onDisk) return;
        $exceptName = null;
        if ($except !== null) { $current = WikiPage::find($except, true);if (WikiStorage::parent($current['path']) === WikiStorage::parent($path)) $exceptName = WikiStorage::name($current['path']); }
        if (WikiStorage::nameTaken(WikiStorage::parent($path), WikiStorage::name($path), $exceptName)) throw new \InvalidArgumentException('Un fichier ou dossier porte déjà ce nom dans ce dossier.');
    }

    /** Creates a page "# Title" in $folder. Returns its id. */
    public static function create(array $user, string $folder, string $title, string $content = ''): int
    {
        $folder = self::ensureFolder($folder);
        $title = trim(preg_replace('/\s+/u', ' ', $title) ?? '');
        if ($title === '' || mb_strlen($title, 'UTF-8') > 200) throw new \InvalidArgumentException('Le titre doit contenir entre 1 et 200 caractères.');
        $path = WikiStorage::relativePath(WikiStorage::join($folder, WikiStorage::fileNameFromTitle($title) . '.md'), true);
        $content = WikiStorage::normalizeContent($content !== '' ? $content : '# ' . $title . "\n");
        return self::insert($user, $path, $content, 'create');
    }

    /**
     * Creates a page from an imported document (WikiImport). The title is the first heading of the
     * Markdown; when that name is taken in the folder, " (2)", " (3)"… is added to the title.
     */
    public static function import(array $user, string $folder, string $title, string $markdown, string $source): int
    {
        $folder = self::ensureFolder($folder);
        $title = trim(preg_replace('/\s+/u', ' ', $title) ?? '');
        if ($title === '' || mb_strlen($title, 'UTF-8') > 200) throw new \InvalidArgumentException('Le titre doit contenir entre 1 et 200 caractères.');
        $candidate = $title;
        for ($n = 2; ; $n++) {
            $path = WikiStorage::relativePath(WikiStorage::join($folder, WikiStorage::fileNameFromTitle($candidate) . '.md'), true);
            if (!WikiPage::byPathKey(WikiStorage::key($path)) && !WikiStorage::nameTaken($folder, WikiStorage::name($path))) break;
            if ($n > 50) throw new \InvalidArgumentException('Trop de pages portent déjà ce titre dans ce dossier.');
            $candidate = $title . ' (' . $n . ')';
        }
        $content = WikiStorage::normalizeContent(WikiImport::withTitle($markdown, $candidate));
        $source = mb_substr(trim(basename(str_replace('\\', '/', $source))), 0, 180, 'UTF-8');
        $id = self::insert($user, $path, $content, 'import', true, 'Importé depuis ' . ($source !== '' ? $source : 'un fichier'));
        self::ensureAssets($folder, $content);
        return $id;
    }

    /** Inserts a new page row + first revision, then writes its file (shared by create() and scans). */
    private static function insert(?array $user, string $path, string $content, string $action, bool $writeFile = true, string $message = ''): int
    {
        self::ensureFree($path, null, $writeFile);
        $derived = self::derived($path, $content);$author = self::author($user);
        $temp = $writeFile ? WikiStorage::prepare($path, $content) : null;
        try {
            $id = DB::transaction(function () use ($path, $content, $derived, $author, $action, $message) {
                $id = DB::insert(
                    'INSERT INTO wiki_pages(path,path_key,title,body_text,html,render_version,content_hash,current_revision_no,created_by,created_at,updated_by,updated_at) VALUES(?,?,?,?,?,?,?,1,?,UTC_TIMESTAMP(),?,UTC_TIMESTAMP())',
                    [$path, WikiStorage::key($path), $derived['title'], $derived['text'], $derived['html'], WikiRenderer::VERSION, $derived['hash'], $author['user_id'], $author['user_id']],
                );
                $lines = $content === '' ? 0 : substr_count($content, "\n");
                WikiRevision::append($id, 1, $author + ['action' => $action, 'path_after' => $path, 'content' => $content, 'hash' => $derived['hash'], 'added' => $lines, 'message' => $message !== '' ? $message : null]);
                return $id;
            });
        } catch (\Throwable $error) {
            WikiStorage::discard($temp);
            throw $error;
        }
        if ($temp !== null) WikiStorage::commit($temp, $path);
        self::remember($id, $path);
        return $id;
    }

    /**
     * Saves new content. $base is the revision number the editor started from; when another revision
     * was saved since and $force is false, throws WikiConflict. Returns the new revision number, or
     * the current one when the content did not change.
     */
    public static function save(array $user, int $id, string $content, int $base, string $message = '', bool $force = false, string $action = 'edit'): int
    {
        // Record a pending external change first, so it appears in the history and triggers a conflict.
        self::sync(WikiPage::find($id));
        return self::write($user, $id, $content, $base, $message, $force, $action);
    }

    /** save() without the preliminary sync (used by sync() itself). */
    private static function write(array $user, int $id, string $content, int $base, string $message, bool $force, string $action): int
    {
        $content = WikiStorage::normalizeContent($content);
        $message = trim(mb_substr(preg_replace('/\s+/u', ' ', $message) ?? '', 0, 255, 'UTF-8'));
        $page = WikiPage::find($id);
        $derived = self::derived($page['path'], $content);$author = self::author($user);
        if ($derived['hash'] === $page['content_hash']) return (int) $page['current_revision_no'];
        $temp = WikiStorage::prepare($page['path'], $content);
        try {
            $number = DB::transaction(function () use ($id, $content, $base, $force, $derived, $author, $message, $action) {
                $page = WikiPage::lock($id);
                if ($page['deleted_at'] !== null) throw new \InvalidArgumentException('Cette page a été supprimée entre-temps.');
                if (!$force && (int) $page['current_revision_no'] !== $base) throw new WikiConflict((int) $page['current_revision_no']);
                $previous = WikiRevision::latest($id);
                [$added, $removed] = TextDiff::stats((string) ($previous['content'] ?? ''), $content);
                $number = (int) $page['current_revision_no'] + 1;
                WikiRevision::append($id, $number, $author + ['action' => $action, 'path_after' => $page['path'], 'content' => $content, 'hash' => $derived['hash'], 'added' => $added, 'removed' => $removed, 'message' => $message !== '' ? $message : null]);
                DB::run('UPDATE wiki_pages SET title=?,body_text=?,html=?,render_version=?,content_hash=?,current_revision_no=?,updated_by=?,updated_at=UTC_TIMESTAMP() WHERE id=?', [$derived['title'], $derived['text'], $derived['html'], WikiRenderer::VERSION, $derived['hash'], $number, $author['user_id'], $id]);
                return $number;
            });
        } catch (\Throwable $error) {
            WikiStorage::discard($temp);
            throw $error;
        }
        WikiStorage::commit($temp, $page['path']);
        self::remember($id, $page['path']);
        self::ensureAssets(WikiStorage::parent($page['path']), $content);
        return $number;
    }

    /** Restores the content of revision $number as a new revision (the page is undeleted if needed). */
    public static function restore(array $user, int $id, int $number): int
    {
        $page = WikiPage::find($id, true);
        $revision = WikiRevision::byNumber($id, $number);
        if ($page['deleted_at'] === null) return self::save($user, $id, $revision['content'], (int) $page['current_revision_no'], 'Restauration de la version ' . $number, true, 'restore');
        // Undelete: the file is written again at its last path, which must still be free.
        self::ensureFree($page['path'], $id);
        if (!WikiStorage::isFolder(WikiStorage::parent($page['path']))) throw new \InvalidArgumentException('Le dossier d’origine n’existe plus. Recréez-le avant de restaurer la page.');
        $content = WikiStorage::normalizeContent($revision['content']);
        $derived = self::derived($page['path'], $content);$author = self::author($user);
        $temp = WikiStorage::prepare($page['path'], $content);
        try {
            $new = DB::transaction(function () use ($id, $content, $derived, $author, $number) {
                $page = WikiPage::lock($id);
                if ($page['deleted_at'] === null) throw new \InvalidArgumentException('La page a déjà été restaurée.');
                $new = (int) $page['current_revision_no'] + 1;
                WikiRevision::append($id, $new, $author + ['action' => 'restore', 'path_after' => $page['path'], 'content' => $content, 'hash' => $derived['hash'], 'added' => substr_count($content, "\n"), 'message' => 'Restauration de la version ' . $number]);
                DB::run('UPDATE wiki_pages SET title=?,body_text=?,html=?,render_version=?,content_hash=?,current_revision_no=?,updated_by=?,updated_at=UTC_TIMESTAMP(),deleted_at=NULL WHERE id=?', [$derived['title'], $derived['text'], $derived['html'], WikiRenderer::VERSION, $derived['hash'], $new, $author['user_id'], $id]);
                return $new;
            });
        } catch (\Throwable $error) {
            WikiStorage::discard($temp);
            throw $error;
        }
        WikiStorage::commit($temp, $page['path']);
        self::remember($id, $page['path']);
        self::ensureAssets(WikiStorage::parent($page['path']), $content);
        return $new;
    }

    /** Renames and/or moves a page. $name is the new file name without ".md". */
    public static function move(array $user, int $id, string $folder, string $name): void
    {
        $page = WikiPage::find($id);
        self::sync($page);
        $page = WikiPage::find($id);
        $folder = self::ensureFolder($folder);
        $name = preg_replace('/\.md$/i', '', trim($name)) ?? '';
        $target = WikiStorage::relativePath(WikiStorage::join($folder, WikiStorage::segment($name) . '.md'), true);
        if ($target === $page['path']) return;
        if (WikiStorage::key($target) !== $page['path_key']) self::ensureFree($target, $id);
        WikiStorage::move($page['path'], $target);
        $author = self::author($user);
        try {
            DB::transaction(function () use ($id, $target, $author) {
                $page = WikiPage::lock($id);
                $latest = WikiRevision::latest($id);
                $number = (int) $page['current_revision_no'] + 1;
                WikiRevision::append($id, $number, $author + ['action' => 'move', 'path_before' => $page['path'], 'path_after' => $target, 'content' => (string) $latest['content'], 'hash' => $page['content_hash']]);
                DB::run('UPDATE wiki_pages SET path=?,path_key=?,title=?,current_revision_no=?,updated_by=?,updated_at=UTC_TIMESTAMP() WHERE id=?', [$target, WikiStorage::key($target), self::titleOf($target, (string) $latest['content']), $number, $author['user_id'], $id]);
            });
        } catch (\Throwable $error) {
            try { WikiStorage::move($target, $page['path']); } catch (\Throwable) {}
            throw $error;
        }
        self::remember($id, $target);
        // Older revisions keep their relative "_assets/" links: copy every image they use.
        if (WikiStorage::parent($target) !== WikiStorage::parent($page['path'])) self::ensureAssets(WikiStorage::parent($target), ...self::revisionContents($id));
    }

    /** Deletes a page: its file is removed, its history is kept and it can be restored. */
    public static function delete(?array $user, int $id, bool $fileAlreadyGone = false): void
    {
        $page = WikiPage::find($id);
        if (!$fileAlreadyGone) { self::sync($page);$page = WikiPage::find($id); }
        $hidden = $fileAlreadyGone ? null : WikiStorage::stash($page['path']);
        $author = self::author($user);
        try {
            DB::transaction(function () use ($id, $author) {
                $page = WikiPage::lock($id);
                if ($page['deleted_at'] !== null) return;
                $latest = WikiRevision::latest($id);
                $number = (int) $page['current_revision_no'] + 1;
                WikiRevision::append($id, $number, $author + ['action' => 'delete', 'path_before' => $page['path'], 'content' => (string) $latest['content'], 'hash' => $page['content_hash'], 'removed' => substr_count((string) $latest['content'], "\n")]);
                DB::run('UPDATE wiki_pages SET current_revision_no=?,updated_by=?,updated_at=UTC_TIMESTAMP(),deleted_at=UTC_TIMESTAMP() WHERE id=?', [$number, $author['user_id'], $id]);
            });
        } catch (\Throwable $error) {
            WikiStorage::unstash($hidden, $page['path']);
            throw $error;
        }
        WikiStorage::discard($hidden);
    }

    public static function createFolder(string $parent, string $name): string
    {
        $parent = self::ensureFolder($parent);
        $path = WikiStorage::relativePath(WikiStorage::join($parent, WikiStorage::segment(trim($name))));
        if (WikiStorage::nameTaken($parent, WikiStorage::name($path))) throw new \InvalidArgumentException('Un fichier ou dossier porte déjà ce nom.');
        WikiStorage::makeFolder($path);
        return $path;
    }

    /** Renames or moves a folder and records a "move" revision for every page it contains. */
    public static function moveFolder(array $user, string $folder, string $parent, string $name): string
    {
        $folder = self::ensureFolder($folder);
        if ($folder === '') throw new \InvalidArgumentException('Le dossier racine ne peut pas être déplacé.');
        $parent = self::ensureFolder($parent);
        $target = WikiStorage::relativePath(WikiStorage::join($parent, WikiStorage::segment(trim($name))));
        if ($target === $folder) return $folder;
        $caseOnly = WikiStorage::key($target) === WikiStorage::key($folder);
        if (!$caseOnly && str_starts_with(WikiStorage::key($target) . '/', WikiStorage::key($folder) . '/')) throw new \InvalidArgumentException('Un dossier ne peut pas être déplacé dans lui-même.');
        if (!$caseOnly && WikiStorage::nameTaken($parent, WikiStorage::name($target))) throw new \InvalidArgumentException('Un fichier ou dossier porte déjà ce nom.');
        $pages = WikiPage::under($folder);
        foreach ($pages as $page) {
            $new = $target . substr($page['path'], strlen($folder));
            WikiStorage::relativePath($new, true);
        }
        WikiStorage::move($folder, $target);
        $author = self::author($user);
        try {
            DB::transaction(function () use ($pages, $folder, $target, $author) {
                foreach ($pages as $page) {
                    $locked = WikiPage::lock((int) $page['id']);
                    $new = $target . substr($locked['path'], strlen($folder));
                    $latest = WikiRevision::latest((int) $page['id']);
                    $number = (int) $locked['current_revision_no'] + 1;
                    WikiRevision::append((int) $page['id'], $number, $author + ['action' => 'move', 'path_before' => $locked['path'], 'path_after' => $new, 'content' => (string) $latest['content'], 'hash' => $locked['content_hash']]);
                    DB::run('UPDATE wiki_pages SET path=?,path_key=?,current_revision_no=?,updated_by=?,updated_at=UTC_TIMESTAMP() WHERE id=?', [$new, WikiStorage::key($new), $number, $author['user_id'], $page['id']]);
                }
            });
        } catch (\Throwable $error) {
            try { WikiStorage::move($target, $folder); } catch (\Throwable) {}
            throw $error;
        }
        return $target;
    }

    public static function deleteFolder(string $folder): void
    {
        $folder = self::ensureFolder($folder);
        if (WikiPage::under($folder)) throw new \InvalidArgumentException('Le dossier contient encore des pages.');
        WikiStorage::removeFolder($folder);
    }

    /**
     * Compares a page with its file and reconciles them. Returns one of:
     * 'same', 'repaired' (interrupted write rewritten), 'external' (new revision), 'missing', 'invalid'.
     */
    public static function sync(array $page): string
    {
        $stat = WikiStorage::stat($page['path']);
        if ($stat === null) return 'missing';
        if ($page['file_mtime'] !== null && (int) $page['file_mtime'] === $stat[0] && (int) $page['file_size'] === $stat[1]) return 'same';
        $raw = WikiStorage::read($page['path']);
        if ($raw === null) return 'missing';
        try {
            $content = WikiStorage::normalizeContent($raw);
        } catch (\InvalidArgumentException) {
            return 'invalid';
        }
        $hash = WikiStorage::hash($content);
        if ($hash === $page['content_hash']) { self::remember((int) $page['id'], $page['path']);return 'same'; }
        if ($hash === WikiRevision::previousHash((int) $page['id'], $page['content_hash'])) {
            $latest = WikiRevision::latest((int) $page['id']);
            $temp = WikiStorage::prepare($page['path'], (string) $latest['content']);
            WikiStorage::commit($temp, $page['path']);
            self::remember((int) $page['id'], $page['path']);
            return 'repaired';
        }
        try {
            self::write([], (int) $page['id'], $content, (int) $page['current_revision_no'], '', false, 'external');
        } catch (WikiConflict) {
            return 'same';
        }
        return 'external';
    }

    /**
     * Reconciles the whole folder with the index: new .md files become pages, changed files get an
     * external revision, pages whose file disappeared are marked deleted. Returns a report.
     */
    public static function scan(): array
    {
        $tree = WikiStorage::tree();
        $report = ['created' => 0, 'external' => 0, 'repaired' => 0, 'deleted' => 0, 'images' => 0, 'invalid' => [], 'rejected' => $tree['rejected']];
        // Images copied by hand into an "_assets" folder are added to wiki_assets when valid.
        foreach (array_merge([''], $tree['folders']) as $folder) {
            foreach (WikiStorage::assetFiles($folder) as $name => $file) {
                if (WikiAsset::exists($name)) continue;
                $data = (string) file_get_contents($file);
                try {
                    if (WikiAsset::inspect($data)['name'] !== $name) throw new \InvalidArgumentException('le nom ne correspond pas à l’empreinte du contenu');
                    WikiAsset::store($data, null);
                    $report['images']++;
                } catch (\InvalidArgumentException $error) {
                    $report['invalid'][] = WikiStorage::join($folder, WikiStorage::ASSETS . '/' . $name) . ' — ' . $error->getMessage();
                }
            }
        }
        $seen = [];
        foreach ($tree['files'] as $path) {
            $key = WikiStorage::key($path);$seen[$key] = true;
            $page = WikiPage::byPathKey($key);
            if ($page) {
                $state = self::sync($page);
                if ($state === 'external') $report['external']++;
                elseif ($state === 'repaired') $report['repaired']++;
                elseif ($state === 'invalid') $report['invalid'][] = $path;
                continue;
            }
            try {
                $content = WikiStorage::normalizeContent((string) WikiStorage::read($path));
                self::insert(null, $path, $content, 'external', false);
                $report['created']++;
            } catch (\InvalidArgumentException $error) {
                $report['invalid'][] = $path . ' — ' . $error->getMessage();
            }
        }
        foreach (WikiPage::under('') as $page) {
            if (isset($seen[$page['path_key']]) || WikiStorage::stat($page['path']) !== null) continue;
            self::delete(null, (int) $page['id'], true);
            $report['deleted']++;
        }
        return $report;
    }

    /** Writes every active page from its latest revision into an empty directory. Returns the count. */
    public static function rebuild(string $directory): int
    {
        if (is_dir($directory) && array_diff(scandir($directory) ?: [], ['.', '..'])) throw new \InvalidArgumentException('Le dossier de reconstruction doit être vide.');
        $previous = WikiStorage::root();
        WikiStorage::useRoot($directory);
        try {
            $count = 0;
            foreach (WikiPage::under('') as $page) {
                $latest = WikiRevision::latest((int) $page['id']);
                $folder = '';
                foreach (explode('/', WikiStorage::parent($page['path'])) as $part) {
                    if ($part === '') continue;
                    $folder = WikiStorage::join($folder, $part);
                    if (!WikiStorage::isFolder($folder)) WikiStorage::makeFolder($folder);
                }
                WikiStorage::commit(WikiStorage::prepare($page['path'], (string) $latest['content']), $page['path']);
                self::ensureAssets(WikiStorage::parent($page['path']), ...self::revisionContents((int) $page['id']));
                $count++;
            }
            return $count;
        } finally {
            WikiStorage::useRoot($previous);
        }
    }

    /** Folder tree with pages, for the side navigation: nested ['name','path','folders','pages']. */
    public static function navigation(): array
    {
        $root = ['name' => 'Wiki', 'path' => '', 'folders' => [], 'pages' => []];
        $tree = WikiStorage::tree();
        $index = ['' => &$root];
        foreach ($tree['folders'] as $folder) {
            $parent = WikiStorage::parent($folder);
            if (!isset($index[$parent])) continue;
            $index[$parent]['folders'][$folder] = ['name' => WikiStorage::name($folder), 'path' => $folder, 'folders' => [], 'pages' => []];
            $index[$folder] = &$index[$parent]['folders'][$folder];
        }
        foreach (WikiPage::all() as $page) {
            $parent = WikiStorage::parent($page['path']);
            $target = &$root;
            if (isset($index[$parent])) $target = &$index[$parent];
            $target['pages'][] = $page;
            unset($target);
        }
        return $root;
    }
}
