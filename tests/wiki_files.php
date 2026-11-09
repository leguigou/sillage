<?php
declare(strict_types=1);
// Wiki checks that need no database: names and paths, file operations, rendering, diff.
// Included by tests/run.php; can also run alone: php tests/wiki_files.php
use App\Services\{WikiStorage, WikiRenderer, TextDiff};

if (!function_exists('check')) {
    require dirname(__DIR__) . '/app/bootstrap.php';
    $passed = 0;
    function check(bool $ok, string $name): void { global $passed; if (!$ok) { fwrite(STDERR, "ÉCHEC : $name\n"); exit(1); } $passed++; }
    function rejects(callable $fn, string $name): void { try { $fn(); } catch (InvalidArgumentException) { check(true, $name); return; } check(false, $name); }
    $wikiStandalone = true;
}

$wikiRoot = sys_get_temp_dir() . '/sillage-wiki-test-' . bin2hex(random_bytes(6));
WikiStorage::useRoot($wikiRoot);
try {
    // Names and paths.
    check(WikiStorage::segment('Clôture de caisse.md') === 'Clôture de caisse.md', 'nom accentué accepté');
    foreach (['', '.', '..', '.cache', '_assets', 'a/b', 'a\\b', 'a:b', 'a?b', 'a*b', 'a"b', 'a<b', 'a|b', "a\0b", "a\tb", 'fin.', 'fin ', ' début', 'CON', 'con.md', 'LPT1.txt', str_repeat('a', 121)] as $bad) {
        rejects(fn() => WikiStorage::segment($bad), 'nom refusé : ' . json_encode($bad));
    }
    foreach (['../x.md', '/etc/passwd.md', 'C:/x.md', 'a/../b.md', 'a//b.md', 'a\\b.md', './x.md', 'x.txt', str_repeat('d/', 9) . 'x.md'] as $bad) {
        rejects(fn() => WikiStorage::relativePath($bad, true), 'chemin refusé : ' . $bad);
    }
    check(WikiStorage::relativePath('Magasins/Caisse/Clôture.MD', true) === 'Magasins/Caisse/Clôture.md', 'extension normalisée en minuscules');
    check(WikiStorage::relativePath('', false) === '', 'racine acceptée comme dossier');
    check(WikiStorage::fileNameFromTitle('Comment faire ? / Étape 1 : caisse') === 'Comment faire Étape 1 caisse', 'titre converti en nom de fichier');
    check(WikiStorage::fileNameFromTitle('CON') === 'CON (page)', 'titre réservé Windows contourné');
    rejects(fn() => WikiStorage::fileNameFromTitle(' ?? '), 'titre sans caractère utile refusé');
    check(WikiStorage::key('Caisse/Clôture.md') === 'caisse/clôture.md', 'clé insensible à la casse');

    // Content normalization.
    check(WikiStorage::normalizeContent("\xEF\xBB\xBFa\r\nb\r\n\r\n") === "a\nb\n", 'BOM retiré, fins de ligne LF, une fin de ligne finale');
    rejects(fn() => WikiStorage::normalizeContent("\xFF\xFE"), 'contenu non UTF-8 refusé');
    rejects(fn() => WikiStorage::normalizeContent(str_repeat('a', WikiStorage::MAX_BYTES + 1)), 'contenu trop volumineux refusé');

    // File operations.
    WikiStorage::makeFolder('Magasins');
    rejects(fn() => WikiStorage::makeFolder('magasins'), 'dossier en double (casse) refusé');
    $temp = WikiStorage::prepare('Magasins/Caisse.md', "# Caisse\n");
    check(!WikiStorage::exists('Magasins/Caisse.md') && is_file($temp), 'fichier temporaire préparé sans toucher la cible');
    check(WikiStorage::tree()['files'] === [], 'fichiers temporaires ignorés par le parcours');
    WikiStorage::commit($temp, 'Magasins/Caisse.md');
    check(WikiStorage::read('Magasins/Caisse.md') === "# Caisse\n", 'écriture atomique validée');
    check(WikiStorage::nameTaken('Magasins', 'caisse.md'), 'nom existant détecté sans tenir compte de la casse');
    WikiStorage::move('Magasins/Caisse.md', 'Magasins/caisse.md');
    check(WikiStorage::tree()['files'] === ['Magasins/caisse.md'], 'renommage limité à la casse');
    rejects(fn() => WikiStorage::removeFolder('Magasins'), 'dossier non vide non supprimé');
    $hidden = WikiStorage::stash('Magasins/caisse.md');
    check(!WikiStorage::exists('Magasins/caisse.md') && is_file((string) $hidden), 'fichier mis de côté avant suppression');
    WikiStorage::unstash($hidden, 'Magasins/caisse.md');
    check(WikiStorage::exists('Magasins/caisse.md'), 'fichier remis en place après échec');
    file_put_contents($wikiRoot . '/notes.txt', 'x');
    file_put_contents($wikiRoot . '/CON.md', 'x');
    mkdir($wikiRoot . '/_assets');
    $tree = WikiStorage::tree();
    check($tree['folders'] === ['Magasins'] && $tree['files'] === ['Magasins/caisse.md'], 'parcours limité aux .md et dossiers visibles');
    check(count(array_filter($tree['rejected'], fn($r) => $r[0] === 'CON.md')) === 1, 'nom réservé signalé par le parcours');
    unlink($wikiRoot . '/CON.md');
    $outside = sys_get_temp_dir() . '/sillage-wiki-outside-' . bin2hex(random_bytes(4));
    mkdir($outside);
    if (@symlink($outside, $wikiRoot . '/lien')) {
        rejects(fn() => WikiStorage::absolute('lien/x.md'), 'lien symbolique jamais suivi');
        check(count(array_filter(WikiStorage::tree()['rejected'], fn($r) => $r[0] === 'lien')) === 1, 'lien symbolique signalé par le parcours');
        unlink($wikiRoot . '/lien') || @rmdir($wikiRoot . '/lien');
    }
    rmdir($outside);

    // Rendering.
    $rendered = WikiRenderer::render("# Clôture de caisse\n\n## Étapes\n\n- [ ] Coffre\n- [x] Alarme\n\n| A | B |\n|:--|--:|\n| 1 | 2 |\n\n## Étapes\n\n[site](https://exemple.fr) ![logo](https://exemple.fr/l.png) 5 € & 10 %\n");
    check($rendered['title'] === 'Clôture de caisse', 'titre extrait du rendu');
    check(str_contains($rendered['html'], 'id="cloture-de-caisse"') && str_contains($rendered['html'], 'id="etapes-2"'), 'ancres de titres uniques');
    check(array_column($rendered['toc'], 'id') === ['etapes', 'etapes-2'], 'sommaire des titres de niveau 2 et 3');
    check(!str_contains($rendered['html'], 'style=') && str_contains($rendered['html'], 'class="align-right"'), 'alignements convertis en classes (CSP)');
    check(substr_count($rendered['html'], 'type="checkbox"') === 2 && str_contains($rendered['html'], 'checked'), 'cases à cocher en lecture seule');
    check(str_contains($rendered['html'], 'rel="noopener noreferrer"') && !str_contains($rendered['html'], '<img'), 'liens externes isolés et images en liens');
    check(str_contains($rendered['html'], 'Clôture') && str_contains($rendered['html'], '5 € &amp; 10 %'), 'texte UTF-8 conservé dans le HTML');
    check(str_contains($rendered['text'], 'Coffre') && !str_contains($rendered['text'], '<'), 'texte brut pour la recherche');
    foreach (['<script>alert(1)</script>', '<img src=x onerror=alert(1)>', '[x](javascript:alert(1))', '[x](JaVaScRiPt:alert(1))', '![a" onerror="alert(1)](http://a.fr/i.png)', '<svg onload=alert(1)>', '[x](data:text/html;base64,PHNjcmlwdD4=)', "[x][r]\n\n[r]: vbscript:x"] as $attack) {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<meta charset="utf-8">' . WikiRenderer::render($attack)['html']);
        libxml_clear_errors();
        $unsafe = false;
        foreach ($dom->getElementsByTagName('*') as $element) {
            if (in_array($element->nodeName, ['script', 'svg', 'iframe', 'img', 'style'], true)) $unsafe = true;
            foreach ($element->attributes as $attribute) {
                $value = strtolower(preg_replace('/[\x00-\x20]+/', '', html_entity_decode($attribute->value)) ?? '');
                if (str_starts_with(strtolower($attribute->name), 'on') || $attribute->name === 'style' || preg_match('/^(javascript|vbscript|data):/', $value)) $unsafe = true;
            }
        }
        check(!$unsafe, 'injection neutralisée : ' . $attack);
    }
    check(!str_contains(strtolower(WikiRenderer::render('[x](javascript:alert(1)) [y](JAVASCRIPT:x)')['html']), 'href="javascript'), 'liens javascript neutralisés retirés du rendu');

    // Images and callouts.
    $asset = str_repeat('ab', 32) . '.png';
    $figure = WikiRenderer::render('![1.00](_assets/' . $asset . ' "Parcours d’achat")')['html'];
    check(str_contains($figure, '<figure class="wiki-figure"><img class="wiki-image" src="/wiki/assets/' . $asset . '" alt="" loading="lazy"><figcaption>Parcours d’achat</figcaption></figure>'), 'image du wiki en figure avec légende, ratio de l’éditeur ignoré');
    $inline = WikiRenderer::render('Voir ![logo](_assets/' . $asset . ') ici')['html'];
    check(str_contains($inline, '<img class="wiki-image" src="/wiki/assets/' . $asset . '" alt="logo"') && !str_contains($inline, '<figure'), 'image dans le texte sans figure');
    foreach (['![x](_assets/../secret.png)', '![x](_assets/' . $asset . '.svg)', '![x](https://exemple.fr/a.png)', '![x](/wiki/assets/' . $asset . ')'] as $other) {
        check(!str_contains(WikiRenderer::render($other)['html'], '<img'), 'image hors du wiki affichée en lien : ' . $other);
    }
    $callout = WikiRenderer::render("> [!WARNING]\n> Ne pas partager.\n\n> \\[!tip] Astuce en ligne\n\n> Citation\n")['html'];
    check(str_contains($callout, '<blockquote class="wiki-callout wiki-callout-warning" role="note"><p class="wiki-callout-title">Attention</p>') && str_contains($callout, 'wiki-callout-tip') && substr_count($callout, 'wiki-callout ') === 2, 'encadrés colorés, citation simple inchangée');
    check(!str_contains($callout, '[!WARNING]') && str_contains($callout, 'Ne pas partager.'), 'marqueur d’encadré retiré, texte conservé');
    check(WikiStorage::normalizeContent("> \\[!NOTE]\n> x\n") === "> [!NOTE]\n> x\n", 'échappement des encadrés retiré à l’enregistrement');
    check(\App\Models\WikiAsset::referenced("![](_assets/$asset) ![](_assets/$asset) ![](_assets/x.png)") === [$asset], 'images référencées extraites sans doublon');
    check(WikiRenderer::title("```\n# pas un titre\n```\n# Vrai titre\n") === 'Vrai titre', 'titre ignoré dans un bloc de code');
    check(WikiRenderer::title("Texte\n") === null, 'page sans titre de niveau 1');

    // Import of a Confluence export (MHTML) and other documents, without database.
    require_once __DIR__ . '/wiki_import_fixture.php';
    $stored = [];
    $fakeStore = function (string $data, string $label) use (&$stored): string { $name = \App\Models\WikiAsset::inspect($data)['name'];$stored[] = $label;return $name; };
    $imported = \App\Services\WikiImport::convert(wiki_import_fixture(), 'Punchout.doc', $fakeStore);
    $md = $imported['markdown'];
    $pngName = hash('sha256', wiki_import_png()) . '.png';
    check($imported['format'] === 'confluence' && $imported['title'] === '🏷️ Punchout client', 'export Confluence reconnu, titre issu du premier titre');
    check(str_starts_with($md, "# 🏷️ Punchout client\n") && str_contains($md, "## ⭐ Schéma"), 'emojis Confluence convertis en caractères');
    check(str_contains($md, "![schema](_assets/$pngName)") && $imported['images'] === 1 && $stored === ['schema.png'], 'image intégrée importée, émoticônes non stockées');
    check(str_contains($md, '*(image non importée : absente.png)*') && count(array_filter($imported['warnings'], fn($w) => str_contains($w, 'absente.png'))) === 1, 'image externe signalée');
    check(str_contains($md, "> [!NOTE]\n> Commande validée par le client.") && !str_contains($md, "> Note :"), 'macro Information en encadré, libellé en double retiré');
    check(str_contains($md, "> [!NOTE]\n> Le lot 2 supprimera cette étape.") && str_contains($md, "> [!CAUTION]\n> Ne jamais modifier le mapping."), 'paragraphes « Note : » et macro Warning en encadrés');
    check(str_contains($md, "- **Connexion**\n  - *Détails* : `PunchOut Site` ce qui :") && str_contains($md, '| Connecte le client existant |'), 'tableau imbriqué converti en liste sans perte');
    check(substr_count($md, 'Connecte le client existant') === 1, 'aucun texte dupliqué');
    check(str_contains($md, "| Type | Description |\n| --- | --- |\n| \"Setup request\" | requête \\| initiale |"), 'tableau simple conservé, barre verticale échappée');
    check(str_contains($md, "```xml\n<cXML>\n  <Cart/>\n</cXML>\n```"), 'bloc de code avec son langage');
    check(str_contains($md, "- Premier\n  1. Sous-étape\n- Second") && str_contains($md, "- [x] Fait\n- [ ] À faire"), 'listes imbriquées et cases à cocher');
    check(str_contains($md, '`Punchout_Gateway`') && str_contains($md, '\\*panier\\*') && str_contains($md, '\\[crochets\\]'), 'texte échappé, code intact');
    check(str_contains($md, '[la doc](https://exemple.fr/doc?a=1&b=2)') && str_contains($md, ' piège.') && !str_contains($md, 'javascript'), 'liens sûrs conservés, javascript retiré');
    check(str_contains($md, '\\1. pas une liste') && !str_contains($md, 'alert(') && !str_contains($md, 'color:red'), 'scripts, styles et formulaires ignorés');
    $importHtml = WikiRenderer::render($md)['html'];
    check(substr_count($importHtml, 'wiki-callout ') === 3 && str_contains($importHtml, '<figure class="wiki-figure">') && !str_contains($importHtml, '<script'), 'page importée rendue avec encadrés et image');
    check(\App\Services\WikiImport::withTitle("# Ancien\n\nTexte\n", 'Nouveau $1') === "# Nouveau \$1\n\nTexte\n" && \App\Services\WikiImport::withTitle("Texte\n", 'Titre') === "# Titre\n\nTexte\n", 'titre modifiable avant import');
    $plain = \App\Services\WikiImport::convert("Ligne sans titre\r\n", 'notes_reunion.md', $fakeStore);
    check($plain['format'] === 'markdown' && $plain['title'] === 'notes reunion' && $plain['markdown'] === "Ligne sans titre\n", 'Markdown importé tel quel, titre tiré du nom');
    $page = \App\Services\WikiImport::convert('<!doctype html><html><head><meta charset="windows-1252"><title>Ancienne page</title></head><body><p>' . "caf\xE9" . '</p></body></html>', 'x.html', $fakeStore);
    check($page['format'] === 'html' && str_contains($page['markdown'], 'café') && str_starts_with($page['markdown'], '# Ancienne page'), 'page HTML Windows-1252 convertie en UTF-8');
    foreach (["%PDF-1.7\n" => 'PDF', "PK\x03\x04xx" => 'docx', "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" => 'Word binaire', "\x00\x01\x02binaire" => 'binaire', '' => 'vide'] as $bytes => $label) {
        rejects(fn() => \App\Services\WikiImport::convert((string) $bytes, 'f', $fakeStore), 'format refusé : ' . $label);
    }
    $deep = fn(int $levels) => '<html><body>' . str_repeat('<div><span>', $levels) . 'fond' . str_repeat('</span></div>', $levels) . '</body></html>';
    check(str_contains(\App\Services\WikiImport::convert($deep(110), 'deep.html', $fakeStore)['markdown'], 'fond'), 'imbrication au-delà de la limite du convertisseur aplatie en texte');
    check(is_string(\App\Services\WikiImport::convert($deep(3000), 'deep.html', $fakeStore)['markdown']), 'imbrication extrême sans erreur (limite de libxml)');

    // Diff.
    $diff = TextDiff::compare("a\nBonjour le monde\nc\n", "a\nBonjour tout le monde\nc\nd\n");
    check($diff['added'] === 2 && $diff['removed'] === 1 && !$diff['truncated'], 'lignes ajoutées et supprimées comptées');
    $changed = array_values(array_filter($diff['rows'], fn($r) => $r['type'] === 'add' && $r['parts']));
    check($changed && $changed[0]['parts'] === [[false, 'Bonjour '], [true, 'tout '], [false, 'le monde']], 'mots modifiés mis en évidence');
    check(TextDiff::stats("x\n", "x\n") === [0, 0], 'aucune différence entre contenus identiques');
    $long = implode("\n", range(1, 200)) . "\n";
    $context = TextDiff::compare($long, str_replace("\n100\n", "\ncent\n", $long));
    check(count($context['rows']) === 10 && $context['rows'][0]['type'] === 'gap', 'contexte limité autour des changements');
    $formatting = TextDiff::compare("- a\n|x|y|\n|:--|--:|\n---\n", "* a\n| x | y |\n| :---- | ----: |\n***\n", true);
    check($formatting['added'] === 0 && $formatting['removed'] === 0, 'option « Ignorer la mise en forme »');
    $huge = TextDiff::compare(implode("\n", range(1, 3500)), implode("\n", range(10001, 13500)));
    check($huge['truncated'] && $huge['added'] === 3500 && $huge['removed'] === 3500, 'diff trop volumineux résumé');
} finally {
    WikiStorage::useRoot(null);
    $items = is_dir($wikiRoot) ? new RecursiveIteratorIterator(new RecursiveDirectoryIterator($wikiRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) : [];
    foreach ($items as $item) $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    if (is_dir($wikiRoot)) rmdir($wikiRoot);
}
if (!empty($wikiStandalone)) echo "$passed contrôles du wiki (fichiers) réussis.\n";
