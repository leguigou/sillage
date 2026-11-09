<?php
// Wiki with the database (included by tests/run.php inside its rolled-back transaction).
// Full-text search is not visible before a commit in InnoDB: it is covered by tests/http_wiki.py.
use App\Core\DB;
use App\Models\{WikiAsset, WikiPage, WikiRevision};
use App\Services\{Wiki, WikiConflict, WikiStorage};

$wikiDbRoot = sys_get_temp_dir() . '/sillage-wiki-db-' . bin2hex(random_bytes(6));
$wikiRebuild = sys_get_temp_dir() . '/sillage-wiki-rebuild-' . bin2hex(random_bytes(6));
WikiStorage::useRoot($wikiDbRoot);
$wikiUser = DB::one('SELECT id,name,role FROM users WHERE id=?', [$uid]);
$wikiOther = ['id' => $wikiUser['id'], 'name' => 'Autre personne', 'role' => 'member'];
try {
    Wiki::createFolder('', 'Magasins');
    Wiki::createFolder('Magasins', 'Caisse');
    rejects(fn() => Wiki::createFolder('', 'magasins'), 'dossier en double refusé (casse)');
    rejects(fn() => Wiki::createFolder('Absent', 'X'), 'dossier parent inexistant refusé');

    $wikiId = Wiki::create($wikiUser, 'Magasins/Caisse', 'Clôture de caisse ?');
    $wikiPage = WikiPage::find($wikiId);
    check($wikiPage['path'] === 'Magasins/Caisse/Clôture de caisse.md' && $wikiPage['title'] === 'Clôture de caisse ?', 'page créée : fichier nommé d’après le titre, titre conservé');
    check(WikiStorage::read($wikiPage['path']) === "# Clôture de caisse ?\n", 'fichier Markdown écrit sur disque');
    check((int) $wikiPage['current_revision_no'] === 1 && WikiRevision::latest($wikiId)['action'] === 'create', 'première version enregistrée');
    rejects(fn() => Wiki::create($wikiUser, 'Magasins/Caisse', 'clôture de caisse'), 'page en double refusée (casse)');
    rejects(fn() => Wiki::create($wikiUser, '../', 'Évasion'), 'dossier hors du wiki refusé');

    $content = "# Clôture de caisse ?\n\n## Étapes\n\n1. Compter le fond\n2. Saisir le montant\n";
    $wikiRev = Wiki::save($wikiUser, $wikiId, $content, 1, 'ajout des étapes');
    $wikiPage = WikiPage::find($wikiId);
    check($wikiRev === 2 && WikiStorage::read($wikiPage['path']) === $content, 'enregistrement : nouvelle version et fichier mis à jour');
    $wikiLatest = WikiRevision::latest($wikiId);
    check($wikiLatest['message'] === 'ajout des étapes' && $wikiLatest['user_name'] === $wikiUser['name'] && (int) $wikiLatest['lines_added'] === 5, 'auteur, message et lignes ajoutées conservés');
    check(str_contains((string) DB::one('SELECT html FROM wiki_pages WHERE id=?', [$wikiId])['html'], '<h2 id="etapes">'), 'HTML mis en cache à l’enregistrement');
    check(Wiki::save($wikiUser, $wikiId, $content, 2) === 2, 'contenu identique : aucune nouvelle version');

    // Concurrent edits.
    Wiki::save($wikiOther, $wikiId, $content . "3. Fermer le coffre\n", 2);
    $conflict = null;
    try { Wiki::save($wikiUser, $wikiId, $content . "3. Imprimer le ticket Z\n", 2); } catch (WikiConflict $error) { $conflict = $error; }
    check($conflict !== null && $conflict->currentRevision === 3, 'conflit détecté sur une version de départ dépassée');
    check(Wiki::save($wikiUser, $wikiId, $content . "3. Imprimer le ticket Z\n", 2, '', true) === 4, 'écraser malgré tout crée une version');
    check(str_contains(WikiRevision::byNumber($wikiId, 3)['content'], 'Fermer le coffre'), 'la version écrasée reste dans l’historique');

    // External change, interrupted write, missing and invalid files.
    $wikiAbs = WikiStorage::absolute(WikiPage::find($wikiId)['path']);
    file_put_contents($wikiAbs, "# Clôture de caisse ?\r\n\r\nModifié à la main\r\n");
    touch($wikiAbs, time() + 5);
    check(Wiki::sync(WikiPage::find($wikiId)) === 'external', 'modification externe détectée');
    $wikiLatest = WikiRevision::latest($wikiId);
    check($wikiLatest['action'] === 'external' && $wikiLatest['user_name'] === WikiRevision::EXTERNAL_AUTHOR && $wikiLatest['user_id'] === null && $wikiLatest['content'] === "# Clôture de caisse ?\n\nModifié à la main\n", 'modification externe enregistrée sans auteur, normalisée');
    check(Wiki::sync(WikiPage::find($wikiId)) === 'same', 'synchronisation idempotente');
    $previous = WikiRevision::byNumber($wikiId, 4)['content'];
    file_put_contents($wikiAbs, $previous);
    touch($wikiAbs, time() + 10);
    check(Wiki::sync(WikiPage::find($wikiId)) === 'repaired' && WikiStorage::read(WikiPage::find($wikiId)['path']) === $wikiLatest['content'], 'écriture interrompue réparée depuis la dernière version');
    file_put_contents($wikiAbs, "\xFF\xFE\x00");
    touch($wikiAbs, time() + 15);
    check(Wiki::sync(WikiPage::find($wikiId)) === 'invalid' && WikiStorage::read(WikiPage::find($wikiId)['path']) === "\xFF\xFE\x00", 'fichier non UTF-8 signalé sans être écrasé');
    unlink($wikiAbs);
    check(Wiki::sync(WikiPage::find($wikiId)) === 'missing' && WikiPage::find($wikiId)['deleted_at'] === null, 'fichier absent signalé sans suppression automatique');
    $wikiCurrent = (int) WikiPage::find($wikiId)['current_revision_no'];
    Wiki::save($wikiUser, $wikiId, "# Clôture de caisse ?\n\nRecréée\n", $wikiCurrent);
    check(WikiStorage::read(WikiPage::find($wikiId)['path']) === "# Clôture de caisse ?\n\nRecréée\n", 'enregistrer recrée un fichier manquant');

    // Restore, move, delete.
    $wikiCurrent = (int) WikiPage::find($wikiId)['current_revision_no'];
    $restored = Wiki::restore($wikiUser, $wikiId, 2);
    check($restored === $wikiCurrent + 1 && WikiRevision::latest($wikiId)['action'] === 'restore' && WikiStorage::read(WikiPage::find($wikiId)['path']) === $content, 'restauration ajoutée comme nouvelle version');
    check(count(WikiRevision::forPage($wikiId)) === $restored, 'aucune version effacée par la restauration');
    Wiki::move($wikiUser, $wikiId, 'Magasins', 'Clôture');
    $wikiPage = WikiPage::find($wikiId);
    check($wikiPage['path'] === 'Magasins/Clôture.md' && WikiStorage::exists('Magasins/Clôture.md') && !WikiStorage::exists('Magasins/Caisse/Clôture de caisse.md'), 'page déplacée et renommée sur disque');
    $wikiLatest = WikiRevision::latest($wikiId);
    check($wikiLatest['action'] === 'move' && $wikiLatest['path_before'] === 'Magasins/Caisse/Clôture de caisse.md' && $wikiLatest['content'] === $content, 'déplacement tracé avec les deux chemins');
    Wiki::move($wikiUser, $wikiId, 'Magasins', 'clôture');
    check(WikiPage::find($wikiId)['path'] === 'Magasins/clôture.md' && in_array('Magasins/clôture.md', WikiStorage::tree()['files'], true), 'renommage limité à la casse');
    $second = Wiki::create($wikiUser, 'Magasins', 'Ouverture');
    rejects(fn() => Wiki::move($wikiUser, $second, 'Magasins', 'CLÔTURE'), 'déplacement vers un nom occupé refusé');
    rejects(fn() => Wiki::deleteFolder('Magasins'), 'dossier contenant des pages non supprimé');

    $moved = Wiki::moveFolder($wikiUser, 'Magasins', '', 'Boutiques');
    check($moved === 'Boutiques' && WikiPage::find($wikiId)['path'] === 'Boutiques/clôture.md' && WikiPage::find($second)['path'] === 'Boutiques/Ouverture.md', 'dossier renommé avec ses pages');
    check(WikiRevision::latest($second)['action'] === 'move', 'renommage du dossier tracé dans chaque page');
    rejects(fn() => Wiki::moveFolder($wikiUser, 'Boutiques', 'Boutiques/Caisse', 'X'), 'dossier déplacé dans lui-même refusé');

    Wiki::delete($wikiUser, $second);
    $deleted = WikiPage::find($second, true);
    check($deleted['deleted_at'] !== null && !WikiStorage::exists('Boutiques/Ouverture.md') && WikiRevision::latest($second)['action'] === 'delete', 'suppression : fichier retiré, historique conservé');
    rejects(fn() => WikiPage::find($second), 'page supprimée absente des lectures');
    $recreated = Wiki::create($wikiUser, 'Boutiques', 'Ouverture');
    rejects(fn() => Wiki::restore($wikiUser, $second, 1), 'restauration refusée si l’emplacement est repris');
    Wiki::delete($wikiUser, $recreated);
    Wiki::restore($wikiUser, $second, (int) $deleted['current_revision_no']);
    check(WikiPage::find($second)['deleted_at'] === null && WikiStorage::exists('Boutiques/Ouverture.md'), 'page supprimée restaurée à son emplacement');

    // Scan of files created, changed or removed outside Sillage.
    mkdir(WikiStorage::absolute('Externe'));
    file_put_contents(WikiStorage::absolute('Externe/Note.md'), "Sans titre de niveau 1\n");
    file_put_contents(WikiStorage::absolute('Externe/CON.md'), "x\n");
    unlink(WikiStorage::absolute('Boutiques/Ouverture.md'));
    $report = Wiki::scan();
    check($report['created'] === 1 && $report['deleted'] === 1 && count($report['rejected']) === 1, 'analyse : ajout, disparition et nom refusé');
    $note = WikiPage::byPathKey(WikiStorage::key('Externe/Note.md'));
    check($note && $note['title'] === 'Note' && WikiRevision::latest((int) $note['id'])['user_name'] === WikiRevision::EXTERNAL_AUTHOR, 'fichier externe indexé, titre tiré du nom');
    check(Wiki::scan()['created'] === 0, 'analyse idempotente');
    unlink(WikiStorage::absolute('Externe/CON.md'));

    // Images: content-addressed, stored once, copied next to the pages that use them.
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
    $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
    rejects(fn() => WikiAsset::inspect('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'), 'image SVG refusée');
    rejects(fn() => WikiAsset::inspect('pas une image'), 'fichier non image refusé');
    $pngName = Wiki::uploadImage($wikiUser, $wikiId, $png);
    check($pngName === hash('sha256', $png) . '.png' && WikiAsset::find($pngName)['data'] === $png && WikiAsset::find($pngName)['mime'] === 'image/png', 'image stockée sous son empreinte');
    check(Wiki::uploadImage($wikiUser, $wikiId, $png) === $pngName && (int) DB::one('SELECT COUNT(*) n FROM wiki_assets WHERE name=?', [$pngName])['n'] === 1, 'image identique stockée une seule fois');
    check(is_file(WikiStorage::absolute('Boutiques/_assets/' . $pngName)) && !in_array('Boutiques/_assets', WikiStorage::tree()['folders'], true), 'copie lisible dans le dossier de la page, ignorée par l’arborescence');
    $withImage = $content . "
![schéma](_assets/$pngName)
";
    Wiki::save($wikiUser, $wikiId, $withImage, (int) WikiPage::find($wikiId)['current_revision_no']);
    check(str_contains((string) DB::one('SELECT html FROM wiki_pages WHERE id=?', [$wikiId])['html'], 'src="/wiki/assets/' . $pngName . '"'), 'image affichée depuis le wiki');
    Wiki::move($wikiUser, $wikiId, 'Externe', 'clôture');
    check(is_file(WikiStorage::absolute('Externe/_assets/' . $pngName)), 'images copiées avec la page déplacée');
    $gifName = hash('sha256', $gif) . '.gif';
    file_put_contents(WikiStorage::absolute('Boutiques/_assets/' . $gifName), $gif);
    file_put_contents(WikiStorage::absolute('Boutiques/_assets/' . str_repeat('0', 64) . '.png'), $png);
    $report = Wiki::scan();
    check($report['images'] === 1 && WikiAsset::exists($gifName), 'analyse : image copiée à la main enregistrée');
    check(count(array_filter($report['invalid'], fn($line) => str_contains($line, str_repeat('0', 64)))) === 1, 'image dont le nom ne correspond pas au contenu signalée');

    // Import of a Confluence export: images stored, page created with an "import" revision.
    require_once __DIR__ . '/wiki_import_fixture.php';
    Wiki::createFolder('', 'Imports');
    $converted = \App\Services\WikiImport::convert(wiki_import_fixture(), 'Punchout.doc', \App\Services\WikiImport::storeWithAssets((int) $wikiUser['id']));
    $importPng = hash('sha256', wiki_import_png()) . '.png';
    check(WikiAsset::exists($importPng) && $converted['images'] === 1, 'import : image de l’export enregistrée');
    $importId = Wiki::import($wikiUser, 'Imports', 'Procédure Punchout', $converted['markdown'], 'C:\\fakepath\\Punchout.doc');
    $importPage = WikiPage::find($importId);
    $importRevision = WikiRevision::latest($importId);
    check($importPage['path'] === 'Imports/Procédure Punchout.md' && $importPage['title'] === 'Procédure Punchout' && str_starts_with($importRevision['content'], "# Procédure Punchout\n"), 'page importée avec le titre choisi');
    check($importRevision['action'] === 'import' && $importRevision['message'] === 'Importé depuis Punchout.doc' && WikiRevision::ACTIONS['import'] === 'Import', 'version d’import tracée avec le fichier source');
    check(is_file(WikiStorage::absolute('Imports/_assets/' . $importPng)) && WikiStorage::read('Imports/Procédure Punchout.md') === $importRevision['content'], 'fichier .md et copie de l’image écrits');
    $againId = Wiki::import($wikiUser, 'Imports', 'procédure punchout', $converted['markdown'], 'Punchout.doc');
    check(WikiPage::find($againId)['path'] === 'Imports/procédure punchout (2).md', 'titre déjà pris : suffixe ajouté');
    rejects(fn() => Wiki::import($wikiUser, '../', 'X', 'x', 'x.md'), 'import hors du wiki refusé');
    rejects(fn() => Wiki::import($wikiUser, 'Imports', '   ', 'x', 'x.md'), 'import sans titre refusé');

    // Rebuild from revisions.
    check(Wiki::rebuild($wikiRebuild) === count(WikiPage::under('')), 'reconstruction : une page écrite par page active');
    check(file_get_contents($wikiRebuild . '/Externe/clôture.md') === WikiRevision::latest($wikiId)['content'] && str_contains(WikiRevision::latest($wikiId)['content'], '_assets/' . $pngName), 'reconstruction fidèle à la dernière version');
    check(file_get_contents($wikiRebuild . '/Externe/_assets/' . $pngName) === $png, 'reconstruction des images depuis la base');
    rejects(fn() => Wiki::rebuild($wikiRebuild), 'reconstruction refusée dans un dossier non vide');
    $navigation = Wiki::navigation();
    check(isset($navigation['folders']['Boutiques']['folders']['Boutiques/Caisse']) && count($navigation['folders']['Externe']['pages']) === 2 && !$navigation['folders']['Boutiques']['pages'], 'arborescence des dossiers et pages');
    Wiki::deleteFolder('Boutiques/Caisse');
    Wiki::deleteFolder('Boutiques');
    check(!WikiStorage::exists('Boutiques') && WikiAsset::exists($gifName), 'dossier sans page supprimé avec ses copies d’images, images conservées en base');
} finally {
    WikiStorage::useRoot(null);
    foreach ([$wikiDbRoot, $wikiRebuild] as $directory) {
        if (!is_dir($directory)) continue;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        rmdir($directory);
    }
}
