<?php
declare(strict_types=1);
/**
 * CI round trip for the whole-site transfer (see the "transfert complet" step of .github/workflows/ci.yml):
 *   php tests/site_transfer_roundtrip.php prepare <target database>   (needs MYSQL_ROOT_PASSWORD)
 *   php tests/site_transfer_roundtrip.php compare <source database> <target database>
 * "prepare" creates an empty database for the import and grants it to DB_USERNAME. "compare" checks
 * that every transferred table has the same row count and CHECKSUM TABLE value on both sides.
 * Test databases only: it refuses any name that does not start with "sillage_".
 */
if (PHP_SAPI !== 'cli') {
    exit();
}
require dirname(__DIR__) . '/app/bootstrap.php';
use App\Services\SiteTransfer;

$mode = $argv[1] ?? '';
$databases = array_slice($argv, 2);
foreach ($databases as $database) {
    if (!preg_match('/^sillage_[a-z0-9_]+$/D', $database)) {
        throw new RuntimeException('Base de test attendue (sillage_…) : ' . $database);
    }
}
$connect = fn(string $database, string $user, string $password) => new PDO(
    'mysql:host=' . env('DB_HOST', '127.0.0.1') . ';port=' . env('DB_PORT', '3306') . ($database !== '' ? ';dbname=' . $database : '') . ';charset=utf8mb4',
    $user,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
);

if ($mode === 'prepare' && count($databases) === 1) {
    $root = $connect('', 'root', (string) getenv('MYSQL_ROOT_PASSWORD'));
    $root->exec('CREATE DATABASE `' . $databases[0] . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci');
    $root->exec('GRANT ALL ON `' . $databases[0] . '`.* TO ' . $root->quote(env('DB_USERNAME')) . "@'%'");
    echo 'Base ' . $databases[0] . " prête.\n";
    exit();
}
if ($mode !== 'compare' || count($databases) !== 2) {
    throw new RuntimeException('Usage : prepare <base cible> | compare <base source> <base cible>');
}
[$source, $target] = array_map(fn($database) => $connect($database, env('DB_USERNAME'), env('DB_PASSWORD')), $databases);
$tables = $source->query("SELECT TABLE_NAME t FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
$compared = 0;
$rows = 0;
foreach ($tables as $table) {
    if (in_array($table, SiteTransfer::SKIPPED, true)) {
        continue;
    }
    $count = fn(PDO $db) => (int) $db->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
    $checksum = fn(PDO $db) => (string) $db->query('CHECKSUM TABLE `' . $table . '` EXTENDED')->fetch()['Checksum'];
    if ($count($source) !== $count($target) || $checksum($source) !== $checksum($target)) {
        throw new RuntimeException('ÉCHEC : contenu différent après transfert pour la table ' . $table);
    }
    $compared++;
    $rows += $count($source);
}
if ($rows === 0 || $compared < 20) {
    throw new RuntimeException('ÉCHEC : comparaison sans données, le transfert n’a pas été réellement testé.');
}
echo "OK transfert de site : $compared tables et $rows lignes identiques (nombre de lignes et CHECKSUM TABLE).\n";
