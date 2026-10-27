<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    exit();
}
$root = dirname(__DIR__);
if (is_file($root . '/.env')) {
    require $root . '/app/bootstrap.php';
    if (in_array('--external', $argv, true)) {
        if (
            env('APP_ENV') !== 'local' ||
            !in_array(parse_url(env('APP_URL'), PHP_URL_HOST), ['127.0.0.1', 'localhost'], true) ||
            (int) parse_url(env('APP_URL'), PHP_URL_PORT) !== 8088
        ) {
            throw new RuntimeException(
                'Pour ce lanceur local, utilisez APP_ENV=local et APP_URL=http://127.0.0.1:8088.',
            );
        }
        echo "Base configurée dans .env conservée. Aucun serveur MySQL ni migration automatique.\n";
        exit();
    }
    if (
        env('APP_ENV') !== 'local' ||
        !in_array(env('DB_HOST'), ['127.0.0.1', 'localhost'], true) ||
        env('DB_PORT') !== '3338' ||
        !in_array(parse_url(env('APP_URL'), PHP_URL_HOST), ['127.0.0.1', 'localhost'], true)
    ) {
        throw new RuntimeException(
            'Le lanceur portable accepte uniquement une configuration locale sur le port MySQL 3338. Configuration existante conservée, aucune migration effectuée.',
        );
    }
    echo "Configuration existante conservée.\n";
    exit();
}
if (in_array('--external', $argv, true)) {
    throw new RuntimeException('Créez d’abord votre fichier .env à partir de .env.example.');
}
if (!is_dir($root . '/.runtime')) {
    mkdir($root . '/.runtime', 0700, true);
}
$rootPassword = bin2hex(random_bytes(32));
$password = bin2hex(random_bytes(24));
file_put_contents(
    $root . '/.runtime/mysql-admin.json',
    json_encode(['user' => 'root', 'password' => $rootPassword], JSON_THROW_ON_ERROR),
);
file_put_contents(
    $root . '/.runtime/bootstrap.sql',
    "ALTER USER 'root'@'localhost' IDENTIFIED BY '$rootPassword';\nCREATE DATABASE IF NOT EXISTS sillage CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\nCREATE USER IF NOT EXISTS 'sillage'@'localhost' IDENTIFIED BY '$password';\nGRANT SELECT,INSERT,UPDATE,DELETE,CREATE,ALTER,INDEX,REFERENCES ON sillage.* TO 'sillage'@'localhost';\n",
);
file_put_contents(
    $root . '/.env',
    "APP_ENV=local\nAPP_URL=http://127.0.0.1:8088\nAPP_KEY=" .
        bin2hex(random_bytes(32)) .
        "\nCOMPANY_NAME=Mon entreprise\nALLOWED_DOMAINS=example.com\nEMAIL_DOMAIN_RESTRICTION=true\nDB_HOST=127.0.0.1\nDB_PORT=3338\nDB_DATABASE=sillage\nDB_USERNAME=sillage\nDB_PASSWORD=$password\nMAIL_TRANSPORT=log\nMAIL_FROM=sillage@example.com\nSESSION_SECURE=false\nUPLOAD_MAX_MB=10\n",
);
echo "Configuration locale créée. Aucun secret ne doit être ajouté à Git.\n";
