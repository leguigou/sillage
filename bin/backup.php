<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    exit();
}
require dirname(__DIR__) . '/app/bootstrap.php';
$sslCA = App\Core\DB::sslCA();
$directory = $argv[1] ?? ROOT . '/storage/backups';
if (!is_dir($directory) && !mkdir($directory, 0700, true)) {
    throw new RuntimeException('Dossier de sauvegarde inaccessible.');
}
$name = $directory . '/sillage-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.sql';
$credentials = tempnam(sys_get_temp_dir(), 'sillage-db-');
chmod($credentials, 0600);
$quote = fn(string $s) => '"' .
    str_replace(['\\', "\"", "\n", "\r"], ['\\\\', "\\\"", "\\n", "\\r"], $s) .
    '"';
file_put_contents(
    $credentials,
    "[client]\nhost=" .
        $quote(env('DB_HOST', '127.0.0.1')) .
        "\nport=" .
        $quote(env('DB_PORT', '3306')) .
        "\nuser=" .
        $quote(env('DB_USERNAME')) .
        "\npassword=" .
        $quote(env('DB_PASSWORD')) .
        "\n" .
        ($sslCA !== '' ? "ssl-mode=VERIFY_IDENTITY\nssl-ca=" . $quote($sslCA) . "\n" : ''),
);
$binary = env(
    'MYSQLDUMP_PATH',
    PHP_OS_FAMILY === 'Windows'
        ? ROOT . '/.runtime/mysql-8.4.13-winx64/bin/mysqldump.exe'
        : 'mysqldump',
);
$out = fopen($name, 'xb');
chmod($name, 0600);
$errors = tempnam(sys_get_temp_dir(), 'sillage-dump-');
$process = null;
$success = false;
try {
    $process = proc_open(
        [
            $binary,
            '--defaults-extra-file=' . $credentials,
            '--single-transaction',
            '--hex-blob',
            '--no-tablespaces',
            '--skip-lock-tables',
            env('DB_DATABASE'),
        ],
        [0 => ['pipe', 'r'], 1 => $out, 2 => ['file', $errors, 'w']],
        $pipes,
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Impossible de lancer mysqldump.');
    }
    fclose($pipes[0]);
    $code = proc_close($process);
    $process = null;
    if ($code !== 0) {
        throw new RuntimeException(
            'Échec de sauvegarde MySQL (code ' .
                $code .
                '). La mise en production est interrompue.',
        );
    }
    $success = true;
    echo "Sauvegarde créée : $name\n";
} finally {
    fclose($out);
    if (is_resource($process)) {
        proc_close($process);
    }
    foreach ([$credentials, $errors, ...$success ? [] : [$name]] as $temporary) {
        if (is_file($temporary) && !unlink($temporary)) {
            fwrite(STDERR, 'Nettoyage manuel requis : ' . $temporary . PHP_EOL);
        }
    }
}
