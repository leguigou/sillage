<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    exit();
}
$root = dirname(__DIR__);
$admin = json_decode(
    file_get_contents($root . '/.runtime/mysql-admin.json'),
    true,
    512,
    JSON_THROW_ON_ERROR,
);
$db = new PDO(
    'mysql:host=127.0.0.1;port=3338;charset=utf8mb4',
    $admin['user'],
    $admin['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
$db->exec(
    'CREATE DATABASE IF NOT EXISTS sillage_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
);
$password = bin2hex(random_bytes(24));
$db->exec(
    "CREATE USER IF NOT EXISTS 'sillage_test'@'localhost' IDENTIFIED BY " . $db->quote($password),
);
$db->exec("ALTER USER 'sillage_test'@'localhost' IDENTIFIED BY " . $db->quote($password));
$db->exec(
    "GRANT SELECT,INSERT,UPDATE,DELETE,CREATE,ALTER,INDEX,REFERENCES ON sillage_test.* TO 'sillage_test'@'localhost'",
);
$configFile = $root . '/.runtime/test-env.json';
$environment = [
    'DB_DATABASE' => 'sillage_test',
    'DB_HOST' => '127.0.0.1',
    'DB_PORT' => '3338',
    'DB_USERNAME' => 'sillage_test',
    'DB_PASSWORD' => $password,
    'APP_ENV' => 'local',
    'APP_URL' => 'http://127.0.0.1:8089',
    'APP_KEY' => bin2hex(random_bytes(32)),
    'SESSION_SECURE' => 'false',
    'MAIL_TRANSPORT' => 'log',
];
// Préserver la clé des connexions de test déjà chiffrées lors d’une seconde exécution.
if (is_file($configFile)) {
    $environment['APP_KEY'] = json_decode(file_get_contents($configFile), true)['APP_KEY'];
}
file_put_contents($configFile, json_encode($environment, JSON_THROW_ON_ERROR));
$config = file_get_contents($root . '/.runtime/apache-local.conf');
$config = str_replace(
    ['127.0.0.1:8088', 'apache.pid', 'apache-error.log', 'apache-access.log'],
    ['127.0.0.1:8089', 'apache-test.pid', 'apache-test-error.log', 'apache-test-access.log'],
    $config,
);
foreach ($environment as $k => $v) {
    $config .= "\nSetEnv $k \"$v\"\n";
}
file_put_contents($root . '/.runtime/apache-test.conf', $config);
echo "Environnement de test isolé prêt.\n";
