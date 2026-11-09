<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\{Auth, View};
use App\Models\{Project, WikiPage, WikiRevision};
use App\Services\{TextDiff, Wiki, WikiConflict, WikiStorage};

/**
 * Wiki: Markdown pages stored as files under WIKI_PATH, indexed in wiki_pages, versioned in
 * wiki_revisions (see docs/wiki.md).
 *
 * Reading, searching, history and download: any signed-in user. Writing (pages, folders,
 * restoring): editors. Deleting a folder, listing deleted pages and scanning the folder: admins.
 * Pages are addressed by id; folder paths only travel in the query string or the POST body and
 * are validated by WikiStorage. The editor saves with fetch (JSON, 409 on conflict); every other
 * form is a plain POST followed by a redirect.
 */
final class WikiController
{
    /** Layout context shared by every wiki screen (project of the menu, as in InboxController). */
    private function render(array $user, string $mode, string $title, array $data = []): void
    {
        $projects = Project::all();
        $project = $projects ? Project::find((int) ($_GET['project'] ?? $_SESSION['project_id'] ?? $projects[0]['id'])) : ['id' => 0, 'name' => \App\Core\Company::name(), 'project_key' => ''];
        View::render('wiki', $data + compact('user', 'projects', 'project', 'mode', 'title') + [
            'section' => 'wiki',
            'navigation' => Wiki::navigation(),
            'folders' => WikiStorage::tree()['folders'],
            'canEdit' => $user['role'] !== 'viewer',
        ]);
    }

    private function json(): bool
    {
        return str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
    }

    private static function pageUrl(int $id, string $suffix = ''): string
    {
        return '/wiki/pages/' . $id . $suffix;
    }

    public function index(): void
    {
        $user = Auth::requireUser();
        $folder = WikiStorage::relativePath((string) ($_GET['folder'] ?? ''));
        if (!WikiStorage::isFolder($folder)) throw new \InvalidArgumentException('Ce dossier n’existe pas.');
        $this->render($user, 'home', $folder === '' ? 'Wiki' : WikiStorage::name($folder), [
            'folder' => $folder,
            'recent' => $folder === '' ? WikiPage::recent() : [],
            'folderPages' => $folder === '' ? [] : array_values(array_filter(WikiPage::all(), fn($p) => WikiStorage::parent($p['path']) === $folder)),
            'subfolders' => array_values(array_filter(WikiStorage::tree()['folders'], fn($f) => WikiStorage::parent($f) === $folder)),
        ]);
    }

    public function search(): void
    {
        $user = Auth::requireUser();
        $query = trim((string) ($_GET['q'] ?? ''));
        $folder = WikiStorage::relativePath((string) ($_GET['folder'] ?? ''));
        $resultPage = max(1, (int) ($_GET['page'] ?? 1));
        $results = $query === '' ? ['rows' => [], 'total' => 0, 'words' => []] : WikiPage::search($query, $folder, $resultPage);
        $this->render($user, 'search', 'Recherche dans le wiki', compact('query', 'folder', 'results', 'resultPage'));
    }

    public function show(string $id): void
    {
        $user = Auth::requireUser();
        $page = WikiPage::find((int) $id);
        $state = Wiki::sync($page);
        $page = WikiPage::find((int) $id);
        $latest = WikiRevision::latest((int) $id);
        $html = WikiPage::html($page, (string) ($latest['content'] ?? ''));
        // Table of contents read from the cached HTML (headings carry their anchors), without rendering again.
        preg_match_all('#<h([23]) id="([a-z0-9-]+)">(.*?)</h\1>#s', $html, $headings, PREG_SET_ORDER);
        $toc = array_map(fn($h) => ['level' => (int) $h[1], 'id' => $h[2], 'text' => html_entity_decode(strip_tags($h[3]), ENT_QUOTES | ENT_HTML5, 'UTF-8')], $headings);
        $this->render($user, 'page', $page['title'], ['page' => $page, 'html' => $html, 'toc' => $toc, 'syncState' => $state, 'revisionCount' => (int) $page['current_revision_no']]);
    }

    public function edit(string $id): void
    {
        $user = Auth::editor();
        $page = WikiPage::find((int) $id);
        $state = Wiki::sync($page);
        if ($state === 'invalid') throw new \InvalidArgumentException('Le fichier de cette page n’est pas un texte UTF-8 valide. Un administrateur doit le corriger avant toute modification.');
        $page = WikiPage::find((int) $id);
        $latest = WikiRevision::latest((int) $id);
        $this->render($user, 'edit', 'Modifier « ' . $page['title'] . ' »', ['page' => $page, 'content' => (string) ($latest['content'] ?? ''), 'syncState' => $state]);
    }

    public function raw(string $id): void
    {
        Auth::requireUser();
        $page = WikiPage::find((int) $id);
        Wiki::sync($page);
        $content = (string) (WikiRevision::latest((int) $id)['content'] ?? '');
        $name = WikiStorage::name($page['path']);
        header('Content-Type: text/markdown; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._ -]/', '_', $name) . '"; filename*=UTF-8\'\'' . rawurlencode($name));
        header('X-Content-Type-Options: nosniff');
        echo $content;
    }

    public function history(string $id): void
    {
        $user = Auth::requireUser();
        $page = WikiPage::find((int) $id, true);
        if ($page['deleted_at'] !== null && $user['role'] !== 'admin') throw new \InvalidArgumentException('Page du wiki introuvable.');
        if ($page['deleted_at'] === null) { Wiki::sync($page);$page = WikiPage::find((int) $id); }
        $this->render($user, 'history', 'Historique de « ' . $page['title'] . ' »', ['page' => $page, 'revisions' => WikiRevision::forPage((int) $id)]);
    }

    public function revision(string $id, string $number): void
    {
        $user = Auth::requireUser();
        $page = WikiPage::find((int) $id, true);
        if ($page['deleted_at'] !== null && $user['role'] !== 'admin') throw new \InvalidArgumentException('Page du wiki introuvable.');
        $revision = WikiRevision::byNumber((int) $id, (int) $number);
        $rendered = \App\Services\WikiRenderer::render($revision['content']);
        $this->render($user, 'revision', 'Version ' . (int) $number . ' de « ' . $page['title'] . ' »', ['page' => $page, 'revision' => $revision, 'html' => $rendered['html']]);
    }

    public function diff(string $id): void
    {
        $user = Auth::requireUser();
        $page = WikiPage::find((int) $id, true);
        if ($page['deleted_at'] !== null && $user['role'] !== 'admin') throw new \InvalidArgumentException('Page du wiki introuvable.');
        $to = (int) ($_GET['to'] ?? $page['current_revision_no']);
        $from = (int) ($_GET['from'] ?? max(1, $to - 1));
        $ignore = !empty($_GET['format']);
        $old = WikiRevision::byNumber((int) $id, $from);$new = WikiRevision::byNumber((int) $id, $to);
        $diff = TextDiff::compare($old['content'], $new['content'], $ignore);
        $this->render($user, 'diff', 'Différences de « ' . $page['title'] . ' »', ['page' => $page, 'old' => $old, 'new' => $new, 'diff' => $diff, 'ignoreFormatting' => $ignore, 'revisions' => WikiRevision::forPage((int) $id)]);
    }

    public function create(): void
    {
        $user = Auth::editor();
        $id = Wiki::create($user, (string) ($_POST['folder'] ?? ''), (string) ($_POST['title'] ?? ''));
        redirect(self::pageUrl($id, '/edit'));
    }

    /** Saves the editor content. JSON: {ok, revision, changed} or 409 {conflict, current}. */
    public function save(string $id): void
    {
        $user = Auth::editor();
        try {
            $before = (int) ($_POST['base_revision'] ?? 0);
            $revision = Wiki::save($user, (int) $id, (string) ($_POST['content'] ?? ''), $before, (string) ($_POST['message'] ?? ''), ($_POST['force'] ?? '') === '1');
        } catch (WikiConflict $conflict) {
            if ($this->json()) json_response(['conflict' => true, 'current' => $conflict->currentRevision, 'error' => $conflict->getMessage(), 'diff_url' => self::pageUrl((int) $id, '/diff?from=' . (int) ($_POST['base_revision'] ?? 0) . '&to=' . $conflict->currentRevision)], 409);
            throw new \InvalidArgumentException($conflict->getMessage() . ' Rechargez la page pour reprendre la dernière version.');
        }
        if ($this->json()) json_response(['ok' => true, 'revision' => $revision, 'changed' => $revision !== $before, 'url' => self::pageUrl((int) $id)]);
        flash($revision !== $before ? 'Page enregistrée.' : 'Aucune modification à enregistrer.');
        redirect(self::pageUrl((int) $id));
    }

    /** Import screen: choose documents and a folder, preview, then import (public/assets/wiki.js). */
    public function importForm(): void
    {
        $user = Auth::editor();
        $folder = WikiStorage::relativePath((string) ($_GET['folder'] ?? ''));
        if (!WikiStorage::isFolder($folder)) $folder = '';
        $this->render($user, 'import', 'Importer des documents', ['folder' => $folder]);
    }

    /** Uploaded document of field "document": [bytes, file name]. */
    private function document(): array
    {
        $file = $_FILES['document'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            $tooLarge = in_array($file['error'] ?? null, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
            throw new \InvalidArgumentException($tooLarge ? 'Le fichier dépasse la taille autorisée par le serveur.' : 'Choisissez un fichier à importer.');
        }
        if ((int) filesize($file['tmp_name']) > \App\Services\WikiImport::maxBytes()) throw new \InvalidArgumentException('Le fichier dépasse ' . intdiv(\App\Services\WikiImport::maxBytes(), 1048576) . ' Mo.');
        return [(string) file_get_contents($file['tmp_name']), (string) ($file['name'] ?? 'document')];
    }

    /**
     * Converts one document without creating a page. Its images are stored already (content hashes:
     * importing it afterwards reuses them). JSON: {title, markdown, html, images, warnings, format}.
     */
    public function importPreview(): void
    {
        $user = Auth::editor();
        \App\Core\Security::limit('wiki-import-' . $user['id'], 120, 600);
        [$data, $name] = $this->document();
        $result = \App\Services\WikiImport::convert($data, $name, \App\Services\WikiImport::storeWithAssets((int) $user['id']));
        $result['html'] = \App\Services\WikiRenderer::render($result['markdown'])['html'];
        $result['file'] = $name;
        json_response($result);
    }

    /** Converts one document again and creates its page in "folder" with the (edited) "title". */
    public function importSave(): void
    {
        $user = Auth::editor();
        \App\Core\Security::limit('wiki-import-' . $user['id'], 120, 600);
        [$data, $name] = $this->document();
        $result = \App\Services\WikiImport::convert($data, $name, \App\Services\WikiImport::storeWithAssets((int) $user['id']));
        $title = trim((string) ($_POST['title'] ?? '')) ?: $result['title'];
        $id = Wiki::import($user, (string) ($_POST['folder'] ?? ''), $title, $result['markdown'], $name);
        if ($this->json()) json_response(['ok' => true, 'id' => $id, 'title' => WikiPage::find($id)['title'], 'url' => self::pageUrl($id), 'warnings' => $result['warnings']]);
        flash('Document importé.' . ($result['warnings'] ? ' ' . count($result['warnings']) . ' avertissement(s) : relisez la page.' : ''));
        redirect(self::pageUrl($id));
    }

    /** Image sent by the editor (field "image"). JSON: {ok, name, src: "_assets/<name>"}. */
    public function uploadImage(string $id): void
    {
        $user = Auth::editor();
        \App\Core\Security::limit('wiki-image-' . $user['id'], 60, 600);
        $file = $_FILES['image'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            $tooLarge = in_array($file['error'] ?? null, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
            throw new \InvalidArgumentException($tooLarge ? 'L’image dépasse la taille autorisée.' : 'L’image n’a pas pu être envoyée.');
        }
        if ((int) filesize($file['tmp_name']) > \App\Models\WikiAsset::maxBytes()) throw new \InvalidArgumentException('L’image dépasse la taille autorisée.');
        $name = Wiki::uploadImage($user, (int) $id, (string) file_get_contents($file['tmp_name']));
        json_response(['ok' => true, 'name' => $name, 'src' => WikiStorage::ASSETS . '/' . $name]);
    }

    /** Serves a wiki image to any signed-in user. Names are content hashes: the response never changes. */
    public function asset(string $name): void
    {
        Auth::requireUser();
        $asset = \App\Models\WikiAsset::find($name);
        if (!$asset) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Image introuvable.';
            return;
        }
        if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === '"' . $asset['name'] . '"') { http_response_code(304);return; }
        header('Content-Type: ' . $asset['mime']);
        header('Content-Length: ' . strlen($asset['data']));
        header('Content-Disposition: inline; filename="' . $asset['name'] . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=31536000, immutable');
        header('ETag: "' . $asset['name'] . '"');
        echo $asset['data'];
    }

    /** Same image, addressed relatively from a page URL (the editor resolves "_assets/…" this way). */
    public function pageAsset(string $id, string $name): void
    {
        $this->asset($name);
    }

    public function move(string $id): void
    {
        $user = Auth::editor();
        Wiki::move($user, (int) $id, (string) ($_POST['folder'] ?? ''), (string) ($_POST['name'] ?? ''));
        flash('Page renommée ou déplacée.');
        redirect(self::pageUrl((int) $id));
    }

    public function delete(string $id): void
    {
        $user = Auth::editor();
        $page = WikiPage::find((int) $id);
        Wiki::delete($user, (int) $id);
        flash('Page supprimée. Son historique est conservé.');
        $folder = WikiStorage::parent($page['path']);
        redirect('/wiki' . ($folder !== '' ? '?folder=' . rawurlencode($folder) : ''));
    }

    public function restore(string $id, string $number): void
    {
        $user = Auth::editor();
        $page = WikiPage::find((int) $id, true);
        if ($page['deleted_at'] !== null) Auth::admin();
        Wiki::restore($user, (int) $id, (int) $number);
        flash('Version ' . (int) $number . ' restaurée dans une nouvelle version.');
        redirect(self::pageUrl((int) $id));
    }

    public function createFolder(): void
    {
        Auth::editor();
        $path = Wiki::createFolder((string) ($_POST['parent'] ?? ''), (string) ($_POST['name'] ?? ''));
        flash('Dossier créé.');
        redirect('/wiki?folder=' . rawurlencode($path));
    }

    public function moveFolder(): void
    {
        $user = Auth::editor();
        $path = Wiki::moveFolder($user, (string) ($_POST['folder'] ?? ''), (string) ($_POST['parent'] ?? ''), (string) ($_POST['name'] ?? ''));
        flash('Dossier renommé ou déplacé.');
        redirect('/wiki?folder=' . rawurlencode($path));
    }

    public function deleteFolder(): void
    {
        Auth::admin();
        $folder = WikiStorage::relativePath((string) ($_POST['folder'] ?? ''));
        Wiki::deleteFolder($folder);
        flash('Dossier supprimé.');
        $parent = WikiStorage::parent($folder);
        redirect('/wiki' . ($parent !== '' ? '?folder=' . rawurlencode($parent) : ''));
    }

    public function deleted(): void
    {
        $user = Auth::admin();
        $this->render($user, 'deleted', 'Pages supprimées', ['deleted' => WikiPage::deleted()]);
    }

    public function scan(): void
    {
        $user = Auth::admin();
        $report = Wiki::scan();
        $this->render($user, 'scan', 'Analyse du dossier du wiki', ['report' => $report]);
    }
}
