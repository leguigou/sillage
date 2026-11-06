<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\DB;
use PDO;

/**
 * Whole-site transfer between two installations running the same Sillage version
 * (`php bin/console.php site:export` / `site:import`, see docs/transfert-site.md).
 *
 * export() writes one zip archive:
 * - manifest.json: format, application version, applied migrations, APP_KEY fingerprint, and for
 *   every table its columns, binary columns, row count and SHA-256; for every attachment file its
 *   SHA-256 and size;
 * - data/<table>.jsonl: one JSON array per row, in the manifest's column order (binary values in
 *   base64). Avatars and the company logo are stored in MySQL, so they travel with the data;
 * - uploads/<stored name>: the attachment files of storage/uploads;
 * - LISEZMOI.txt: operator notes.
 *
 * import() loads such an archive into an installation that has the same applied migrations, the
 * same APP_KEY and no user, project or ticket yet. Encrypted secrets (Git tokens, webhook secrets,
 * LLM keys) are copied as ciphertext and never decrypted, hence the same-key requirement.
 *
 * The archive is untrusted input: every checksum is verified before anything is written, table and
 * column names must exist in the target schema, values only travel as bound parameters (no SQL is
 * ever read from the archive), and upload names must be 64 hex characters. All rows are replaced
 * in one transaction; attachment files are staged first and moved into place after the commit.
 */
final class SiteTransfer
{
    public const FORMAT = 1;

    /**
     * Tables that are not transferred: short-lived or tied to the source installation
     * (sign-in cookies, e-mail/reset links, rate limits, technical logs, OAuth codes, Git worker
     * heartbeat), plus migrations, which is compared instead of copied.
     */
    public const SKIPPED = [
        'migrations', 'rate_limits', 'persistent_logins', 'auth_tokens',
        'technical_logs', 'oauth_codes', 'git_worker_state',
    ];

    private const NAME = '/^[a-z0-9_]{1,64}$/D';
    private const UPLOAD_NAME = '/^[a-f0-9]{64}$/D';
    private const BINARY_TYPES = ['binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob'];
    /** Insert batches stay well under MySQL's default max_allowed_packet (64 MB). */
    private const BATCH_ROWS = 200;
    private const BATCH_BYTES = 8_000_000;

    /**
     * Writes the archive to $path (which must not exist yet) from one consistent snapshot of the
     * database and returns the manifest. Attachment rows whose file is missing on disk are listed
     * in manifest['missing_uploads'] instead of failing the export.
     */
    public static function export(string $path): array
    {
        if (file_exists($path)) {
            throw new \RuntimeException('Le fichier d’export existe déjà : ' . $path);
        }
        self::requireZip();
        $db = DB::connection();
        $ownTransaction = !$db->inTransaction();
        if ($ownTransaction) {
            // Every table is read from the same InnoDB snapshot, without blocking users.
            $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $db->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
        }
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::EXCL) !== true) {
            throw new \RuntimeException('Impossible de créer l’archive : ' . $path);
        }
        $temporaryFiles = [];
        $complete = false;
        try {
            $manifest = [
                'format' => self::FORMAT,
                'application' => 'Sillage',
                'version' => ApplicationHistory::catalog()['current_version'],
                'created_at' => gmdate('c'),
                'source_url' => url(),
                'key_fingerprint' => self::keyFingerprint(),
                'migrations' => self::migrations(),
                'tables' => [],
                'uploads' => [],
                'missing_uploads' => [],
            ];
            foreach (self::schema() as $table => $columns) {
                if (in_array($table, self::SKIPPED, true)) {
                    continue;
                }
                $file = tempnam(sys_get_temp_dir(), 'sillage-export-');
                $temporaryFiles[] = $file;
                $rows = self::exportTable($db, $table, $columns, $file);
                $manifest['tables'][$table] = [
                    'columns' => array_keys($columns),
                    'binary' => array_keys(array_filter($columns)),
                    'rows' => $rows,
                    'sha256' => hash_file('sha256', $file),
                ];
                // ZipArchive reads added files when the archive is closed, so they are kept until then.
                $zip->addFile($file, 'data/' . $table . '.jsonl');
            }
            // Attachment files come from the same snapshot as the attachments table.
            foreach (DB::all('SELECT stored_name FROM attachments ORDER BY id') as $attachment) {
                $name = (string) $attachment['stored_name'];
                $file = self::uploadPath($name);
                if (!preg_match(self::UPLOAD_NAME, $name) || !is_file($file)) {
                    $manifest['missing_uploads'][] = $name;
                    continue;
                }
                $manifest['uploads'][] = ['name' => $name, 'sha256' => hash_file('sha256', $file), 'size' => filesize($file)];
                $zip->addFile($file, 'uploads/' . $name);
            }
            $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            $zip->addFromString('LISEZMOI.txt', self::readme($manifest));
            if (!$zip->close()) {
                throw new \RuntimeException('Écriture de l’archive impossible : ' . $path);
            }
            $complete = true;
            // The archive holds personal data: only the operator account may read it.
            @chmod($path, 0600);
            return $manifest;
        } finally {
            if (!$complete) {
                @$zip->close();
                @unlink($path);
            }
            foreach ($temporaryFiles as $file) {
                @unlink($file);
            }
            if ($ownTransaction) {
                // Ends the read-only snapshot opened above (started with exec, not beginTransaction).
                $db->exec('COMMIT');
            }
        }
    }

    /**
     * Loads an archive made by export() into this installation and returns
     * ['tables' => [table => rows], 'uploads' => files moved, 'skipped_uploads' => identical files already present].
     * Nothing is written unless every check passes; the target must be empty.
     */
    public static function import(string $path): array
    {
        self::requireZip();
        $zip = new \ZipArchive();
        if (!is_file($path) || $zip->open($path, \ZipArchive::RDONLY) !== true) {
            throw new \RuntimeException('Archive introuvable ou illisible : ' . $path);
        }
        $staging = null;
        try {
            $manifest = self::readManifest($zip);
            $schema = self::schema();
            $tables = self::checkTables($manifest, $schema);
            $uploads = self::checkUploads($manifest);
            foreach ($tables as $table => $definition) {
                if (self::entryHash($zip, 'data/' . $table . '.jsonl') !== $definition['sha256']) {
                    throw new \RuntimeException('Archive altérée : empreinte différente pour la table ' . $table . '.');
                }
            }
            foreach ($uploads as $upload) {
                if (self::entryHash($zip, 'uploads/' . $upload['name']) !== $upload['sha256']) {
                    throw new \RuntimeException('Archive altérée : empreinte différente pour la pièce jointe ' . $upload['name'] . '.');
                }
            }
            self::requireEmptyTarget();

            // Stage attachment files next to storage/uploads so the final move is a same-disk rename.
            $staging = ROOT . '/storage/import-' . bin2hex(random_bytes(6));
            if (!mkdir($staging, 0700, true)) {
                throw new \RuntimeException('Dossier temporaire d’import impossible à créer : ' . $staging);
            }
            $toMove = [];
            $alreadyPresent = 0;
            foreach ($uploads as $upload) {
                $target = self::uploadPath($upload['name']);
                if (is_file($target)) {
                    // Only possible when storage/uploads is shared with the source: keep identical files.
                    if (hash_file('sha256', $target) !== $upload['sha256']) {
                        throw new \RuntimeException('Un fichier différent existe déjà dans storage/uploads : ' . $upload['name']);
                    }
                    $alreadyPresent++;
                    continue;
                }
                self::extractEntry($zip, 'uploads/' . $upload['name'], $staging . '/' . $upload['name'], $upload['sha256']);
                $toMove[] = $upload['name'];
            }

            $counts = self::replaceData($zip, $tables);

            // Data is committed: move the staged files into place.
            $uploadDirectory = ROOT . '/storage/uploads';
            if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0700, true)) {
                throw new \RuntimeException('Données importées, mais storage/uploads est inaccessible. Fichiers en attente dans ' . $staging);
            }
            $failed = [];
            foreach ($toMove as $name) {
                if (!rename($staging . '/' . $name, $uploadDirectory . '/' . $name)) {
                    $failed[] = $name;
                }
            }
            if ($failed) {
                $kept = $staging;
                $staging = null;
                throw new \RuntimeException('Données importées, mais ' . count($failed) . ' pièce(s) jointe(s) non déplacée(s). Déplacez-les depuis ' . $kept . ' vers storage/uploads.');
            }
            return ['tables' => $counts, 'uploads' => count($toMove), 'skipped_uploads' => $alreadyPresent];
        } finally {
            $zip->close();
            if ($staging !== null && is_dir($staging)) {
                foreach (glob($staging . '/*') ?: [] as $file) {
                    @unlink($file);
                }
                @rmdir($staging);
            }
        }
    }

    /** Non-reversible fingerprint of APP_KEY: proves both sides share the key without revealing it. */
    public static function keyFingerprint(): string
    {
        $key = env('APP_KEY');
        if (strlen($key) !== 64 || !ctype_xdigit($key)) {
            throw new \RuntimeException('Configurez APP_KEY (64 caractères hexadécimaux).');
        }
        return hash_hmac('sha256', 'sillage-site-transfer', $key);
    }

    /** Streams one table to $file as JSON lines and returns the row count. */
    private static function exportTable(PDO $db, string $table, array $columns, string $file): int
    {
        $out = fopen($file, 'wb');
        if ($out === false) {
            throw new \RuntimeException('Fichier temporaire inaccessible.');
        }
        $list = implode(',', array_map(fn($column) => '`' . $column . '`', array_keys($columns)));
        // Unbuffered: rows are streamed instead of loading the whole table in memory.
        $db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        $rows = 0;
        try {
            $statement = $db->query('SELECT ' . $list . ' FROM `' . $table . '`');
            while (($row = $statement->fetch(PDO::FETCH_NUM)) !== false) {
                $i = 0;
                foreach ($columns as $isBinary) {
                    if ($isBinary && $row[$i] !== null) {
                        $row[$i] = base64_encode((string) $row[$i]);
                    }
                    $i++;
                }
                fwrite($out, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
                $rows++;
            }
            $statement->closeCursor();
        } finally {
            $db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
            fclose($out);
        }
        return $rows;
    }

    private static function readManifest(\ZipArchive $zip): array
    {
        try {
            $manifest = json_decode((string) $zip->getFromName('manifest.json'), true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException('Manifeste absent ou invalide : ce fichier n’est pas un export de site Sillage.');
        }
        if (!is_array($manifest) || ($manifest['format'] ?? null) !== self::FORMAT) {
            throw new \RuntimeException('Format d’export non pris en charge.');
        }
        if (($manifest['key_fingerprint'] ?? null) !== self::keyFingerprint()) {
            throw new \RuntimeException('APP_KEY différente de celle du site d’origine : reprenez sa valeur dans le .env de ce site avant l’import.');
        }
        if (($manifest['migrations'] ?? null) !== self::migrations()) {
            throw new \RuntimeException(
                'Versions différentes : les migrations appliquées ne correspondent pas (export de Sillage ' . (string) ($manifest['version'] ?? '?')
                . ', ce site en ' . ApplicationHistory::catalog()['current_version'] . '). Installez la même version et lancez migrate des deux côtés.',
            );
        }
        return $manifest;
    }

    /** Checks every table and column name against the target schema; returns the tables to load. */
    private static function checkTables(array $manifest, array $schema): array
    {
        $tables = $manifest['tables'] ?? null;
        if (!is_array($tables) || $tables === []) {
            throw new \RuntimeException('Archive sans données.');
        }
        foreach ($tables as $table => $definition) {
            if (!is_string($table) || !preg_match(self::NAME, $table) || !isset($schema[$table]) || in_array($table, self::SKIPPED, true)) {
                throw new \RuntimeException('Table inattendue dans l’archive : ' . (string) $table);
            }
            $columns = $definition['columns'] ?? null;
            $binary = $definition['binary'] ?? null;
            if (!is_array($columns) || $columns === [] || !is_array($binary) || !is_int($definition['rows'] ?? null)
                || !is_string($definition['sha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $definition['sha256'])) {
                throw new \RuntimeException('Description invalide pour la table ' . $table . '.');
            }
            foreach ($columns as $column) {
                if (!is_string($column) || !preg_match(self::NAME, $column) || !array_key_exists($column, $schema[$table])) {
                    throw new \RuntimeException('Colonne inattendue dans l’archive : ' . $table . '.' . (is_string($column) ? $column : '?'));
                }
            }
            // Binary (base64) columns must be exactly the target's binary columns among those listed.
            $expectedBinary = array_values(array_filter($columns, fn($column) => $schema[$table][$column]));
            if (count(array_unique($columns)) !== count($columns) || $binary !== $expectedBinary) {
                throw new \RuntimeException('Description invalide pour la table ' . $table . '.');
            }
        }
        // Same migrations means same schema: a missing table signals an incomplete archive.
        foreach (array_keys($schema) as $table) {
            if (!in_array($table, self::SKIPPED, true) && !isset($tables[$table])) {
                throw new \RuntimeException('Archive incomplète : table ' . $table . ' absente.');
            }
        }
        return $tables;
    }

    private static function checkUploads(array $manifest): array
    {
        $uploads = $manifest['uploads'] ?? null;
        if (!is_array($uploads)) {
            throw new \RuntimeException('Liste des pièces jointes invalide.');
        }
        foreach ($uploads as $upload) {
            if (!is_array($upload) || !is_string($upload['name'] ?? null) || !preg_match(self::UPLOAD_NAME, $upload['name'])
                || !is_string($upload['sha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $upload['sha256'])) {
                throw new \RuntimeException('Nom ou empreinte de pièce jointe invalide dans l’archive.');
            }
        }
        return $uploads;
    }

    /** The target must be a fresh installation: migrated, but without any account or content. */
    private static function requireEmptyTarget(): void
    {
        foreach (['users' => 'utilisateur', 'projects' => 'projet', 'tickets' => 'ticket'] as $table => $label) {
            if ((int) DB::one('SELECT COUNT(*) n FROM `' . $table . '`')['n'] > 0) {
                throw new \RuntimeException('Ce site contient déjà au moins un ' . $label . ' : l’import est réservé à un site neuf (base migrée, sans compte ni contenu).');
            }
        }
    }

    /** Replaces the content of every transferred table in one transaction; returns rows per table. */
    private static function replaceData(\ZipArchive $zip, array $tables): array
    {
        $db = DB::connection();
        // Rows are loaded table by table, so references are only consistent once everything is in.
        $db->exec('SET FOREIGN_KEY_CHECKS=0');
        try {
            return DB::transaction(function () use ($zip, $tables, $db) {
                $counts = [];
                foreach ($tables as $table => $definition) {
                    // Removes rows seeded by migrations on the fresh target.
                    $db->exec('DELETE FROM `' . $table . '`');
                    $counts[$table] = self::loadTable($zip, $table, $definition);
                    if ($counts[$table] !== $definition['rows']) {
                        throw new \RuntimeException('Nombre de lignes inattendu pour la table ' . $table . '.');
                    }
                }
                return $counts;
            });
        } finally {
            $db->exec('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    /** Inserts the JSON lines of one table with multi-row prepared statements; returns the row count. */
    private static function loadTable(\ZipArchive $zip, string $table, array $definition): int
    {
        $columns = $definition['columns'];
        $binary = array_flip($definition['binary']);
        $width = count($columns);
        $columnList = implode(',', array_map(fn($column) => '`' . $column . '`', $columns));
        $placeholders = '(' . implode(',', array_fill(0, $width, '?')) . ')';
        $stream = $zip->getStream('data/' . $table . '.jsonl');
        if ($stream === false) {
            throw new \RuntimeException('Données absentes pour la table ' . $table . '.');
        }
        $rows = 0;
        $batch = [];
        $batchRows = 0;
        $batchBytes = 0;
        $flush = function () use (&$batch, &$batchRows, &$batchBytes, $table, $columnList, $placeholders) {
            if ($batchRows === 0) {
                return;
            }
            DB::run('INSERT INTO `' . $table . '` (' . $columnList . ') VALUES ' . implode(',', array_fill(0, $batchRows, $placeholders)), $batch);
            $batch = [];
            $batchRows = 0;
            $batchBytes = 0;
        };
        try {
            while (($line = fgets($stream)) !== false) {
                if (trim($line) === '') {
                    continue;
                }
                try {
                    $row = json_decode($line, true, 2, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    throw new \RuntimeException('Ligne illisible dans la table ' . $table . '.');
                }
                if (!is_array($row) || !array_is_list($row) || count($row) !== $width) {
                    throw new \RuntimeException('Ligne invalide dans la table ' . $table . '.');
                }
                foreach ($row as $i => $value) {
                    if ($value !== null && !is_scalar($value)) {
                        throw new \RuntimeException('Valeur invalide dans la table ' . $table . '.');
                    }
                    if ($value !== null && isset($binary[$columns[$i]])) {
                        $value = base64_decode((string) $value, true);
                        if ($value === false) {
                            throw new \RuntimeException('Valeur binaire invalide dans la table ' . $table . '.');
                        }
                    }
                    $batch[] = $value;
                    $batchBytes += is_string($value) ? strlen($value) : 8;
                }
                $batchRows++;
                $rows++;
                if ($batchRows >= self::BATCH_ROWS || $batchBytes >= self::BATCH_BYTES) {
                    $flush();
                }
            }
            $flush();
        } finally {
            fclose($stream);
        }
        return $rows;
    }

    /**
     * [table => [column => is binary]] for the base tables of the current database. Only computed
     * (VIRTUAL/STORED) columns are excluded, by their generation expression: EXTRA cannot be used,
     * since MySQL also marks DEFAULT CURRENT_TIMESTAMP columns "DEFAULT_GENERATED".
     */
    private static function schema(): array
    {
        $schema = [];
        foreach (DB::all("SELECT TABLE_NAME t,COLUMN_NAME c,DATA_TYPE d
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA=DATABASE()
                    AND TABLE_NAME IN (SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE')
                    AND GENERATION_EXPRESSION=''
                ORDER BY TABLE_NAME,ORDINAL_POSITION") as $column) {
            $table = (string) $column['t'];
            $name = (string) $column['c'];
            if (!preg_match(self::NAME, $table) || !preg_match(self::NAME, $name)) {
                throw new \RuntimeException('Nom de table ou de colonne non pris en charge : ' . $table . '.' . $name);
            }
            $schema[$table][$name] = in_array(strtolower((string) $column['d']), self::BINARY_TYPES, true);
        }
        return $schema;
    }

    /** Applied migration file names, sorted: equal lists mean equal schemas. */
    private static function migrations(): array
    {
        return array_map(fn($row) => (string) $row['version'], DB::all('SELECT version FROM migrations ORDER BY version'));
    }

    private static function uploadPath(string $name): string
    {
        return ROOT . '/storage/uploads/' . $name;
    }

    private static function entryHash(\ZipArchive $zip, string $entry): string
    {
        $stream = $zip->getStream($entry);
        if ($stream === false) {
            throw new \RuntimeException('Élément absent de l’archive : ' . $entry);
        }
        $context = hash_init('sha256');
        try {
            while (!feof($stream)) {
                $chunk = fread($stream, 1 << 20);
                if ($chunk === false) {
                    throw new \RuntimeException('Lecture impossible dans l’archive : ' . $entry);
                }
                hash_update($context, $chunk);
            }
        } finally {
            fclose($stream);
        }
        return hash_final($context);
    }

    /** Copies one archive entry to $target and checks its hash again on the bytes actually written. */
    private static function extractEntry(\ZipArchive $zip, string $entry, string $target, string $sha256): void
    {
        $in = $zip->getStream($entry);
        $out = fopen($target, 'xb');
        if ($in === false || $out === false) {
            throw new \RuntimeException('Extraction impossible : ' . $entry);
        }
        try {
            stream_copy_to_stream($in, $out);
        } finally {
            fclose($in);
            fclose($out);
        }
        if (hash_file('sha256', $target) !== $sha256) {
            throw new \RuntimeException('Pièce jointe altérée pendant l’extraction : ' . $entry);
        }
    }

    private static function requireZip(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('L’extension PHP zip est requise pour le transfert de site.');
        }
    }

    private static function readme(array $manifest): string
    {
        $rows = array_sum(array_column($manifest['tables'], 'rows'));
        return "Export complet de Sillage " . $manifest['version'] . " — " . $manifest['created_at'] . "\n"
            . "Site d'origine : " . $manifest['source_url'] . "\n"
            . count($manifest['tables']) . " tables, " . $rows . " lignes, " . count($manifest['uploads']) . " pièces jointes.\n\n"
            . "CE FICHIER CONTIENT DES DONNÉES PERSONNELLES (comptes, tickets, commentaires, pièces jointes).\n"
            . "Conservez-le hors du site, transmettez-le par un canal sûr et supprimez-le après l'import.\n\n"
            . "Import sur le nouveau site (même version de Sillage, base neuve) :\n"
            . "1. Reprendre la valeur APP_KEY du site d'origine dans le .env du nouveau site.\n"
            . "2. php bin/console.php migrate\n"
            . "3. php bin/console.php site:import <ce fichier>\n\n"
            . "Non transférés : connexions mémorisées, liens de validation ou de réinitialisation en cours,\n"
            . "limites de tentatives, journaux techniques. Chacun se reconnecte sur le nouveau site.\n"
            . "Si l'adresse du site change : mettre à jour APP_URL, les webhooks GitHub/Bitbucket et les clients OAuth.\n";
    }
}
