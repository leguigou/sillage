<?php
declare(strict_types=1);
use App\Core\DB;
use App\Services\SiteTransfer;

// Whole-site export, and the import checks that must reject an archive before anything is written.
// The full export → import round trip into a second database runs in CI (tests/site_transfer_roundtrip.php).
$stDir = sys_get_temp_dir() . '/sillage-transfer-' . bin2hex(random_bytes(4));
mkdir($stDir, 0700);
$stArchive = $stDir . '/export.zip';
$stImportFails = function (string $archive, string $expected, string $name): void {
    try {
        SiteTransfer::import($archive);
    } catch (RuntimeException $error) {
        check(str_contains($error->getMessage(), $expected), $name . ' — ' . $error->getMessage());
        return;
    }
    throw new RuntimeException('ÉCHEC : ' . $name);
};
// Copies the valid archive and lets $change rewrite entries (the manifest is passed decoded).
$stVariant = function (string $name, callable $change) use ($stArchive, $stDir): string {
    $copy = $stDir . '/' . $name . '.zip';
    copy($stArchive, $copy);
    $zip = new ZipArchive();
    $zip->open($copy);
    $change($zip, json_decode((string) $zip->getFromName('manifest.json'), true));
    $zip->close();
    return $copy;
};
$stManifestEntry = fn(ZipArchive $zip, array $manifest) => $zip->addFromString('manifest.json', json_encode($manifest));
try {
    // At least one project, so the import below always meets a site that already has content.
    App\Models\Project::save(['name' => 'Transfert de site', 'key' => 'TS' . strtoupper(bin2hex(random_bytes(3)))]);
    $stManifest = SiteTransfer::export($stArchive);
    check(is_file($stArchive) && $stManifest['tables']['tickets']['rows'] === (int) DB::one('SELECT COUNT(*) n FROM tickets')['n'], 'export de site : tous les tickets de l’instantané');
    check(!array_intersect(SiteTransfer::SKIPPED, array_keys($stManifest['tables'])), 'export de site : tables temporaires et liées au site exclues');
    check(in_array('image_data', $stManifest['tables']['user_avatars']['binary'], true) && in_array('logo_data', $stManifest['tables']['company_branding']['binary'], true), 'export de site : colonnes binaires signalées pour base64');
    $stZip = new ZipArchive();
    $stZip->open($stArchive);
    check($stZip->locateName('LISEZMOI.txt') !== false && hash('sha256', (string) $stZip->getFromName('data/tickets.jsonl')) === $stManifest['tables']['tickets']['sha256'], 'export de site : notice et empreintes présentes');
    check(!str_contains((string) $stZip->getFromName('manifest.json'), env('APP_KEY')), 'export de site : APP_KEY absente de l’archive');
    $stZip->close();
    rejectsCompanyConfiguration(fn() => SiteTransfer::export($stArchive), 'export de site : fichier existant jamais écrasé');

    $stImportFails($stArchive, 'site neuf', 'import de site : archive valide refusée sur un site qui a déjà des données');
    $stImportFails($stVariant('tampered', fn(ZipArchive $zip) => $zip->addFromString('data/tickets.jsonl', "[1]\n")), 'empreinte différente', 'import de site : données modifiées refusées');
    $stImportFails($stVariant('table', function (ZipArchive $zip, array $m) use ($stManifestEntry) {
        $m['tables']['users; DROP TABLE users'] = $m['tables']['users'];
        $stManifestEntry($zip, $m);
    }), 'Table inattendue', 'import de site : nom de table hors schéma refusé');
    $stImportFails($stVariant('column', function (ZipArchive $zip, array $m) use ($stManifestEntry) {
        $m['tables']['users']['columns'][0] = 'id`) VALUES (1); --';
        $stManifestEntry($zip, $m);
    }), 'Colonne inattendue', 'import de site : nom de colonne hors schéma refusé');
    $stImportFails($stVariant('upload', function (ZipArchive $zip, array $m) use ($stManifestEntry) {
        $m['uploads'][] = ['name' => '../../public/index.php', 'sha256' => str_repeat('a', 64), 'size' => 1];
        $stManifestEntry($zip, $m);
    }), 'pièce jointe invalide', 'import de site : chemin de pièce jointe hors storage/uploads refusé');
    $stImportFails($stVariant('key', function (ZipArchive $zip, array $m) use ($stManifestEntry) {
        $m['key_fingerprint'] = str_repeat('0', 64);
        $stManifestEntry($zip, $m);
    }), 'APP_KEY différente', 'import de site : autre APP_KEY refusée');
    $stImportFails($stVariant('version', function (ZipArchive $zip, array $m) use ($stManifestEntry) {
        array_pop($m['migrations']);
        $stManifestEntry($zip, $m);
    }), 'Versions différentes', 'import de site : autre version refusée');
    $stImportFails($stVariant('missing', function (ZipArchive $zip, array $m) use ($stManifestEntry) {
        unset($m['tables']['projects']);
        $stManifestEntry($zip, $m);
    }), 'Archive incomplète', 'import de site : table manquante refusée');
    file_put_contents($stDir . '/not-a-zip.zip', 'pas une archive');
    $stImportFails($stDir . '/not-a-zip.zip', 'illisible', 'import de site : fichier qui n’est pas une archive refusé');
    check(!glob(ROOT . '/storage/import-*'), 'import de site refusé : aucun dossier temporaire laissé');
} finally {
    foreach (glob($stDir . '/*') ?: [] as $stFile) {
        unlink($stFile);
    }
    rmdir($stDir);
}
