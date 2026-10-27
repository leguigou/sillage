<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    exit();
}
require dirname(__DIR__) . '/app/bootstrap.php';
use App\Core\{DB, Security, Migrator};
$command = $argv[1] ?? 'help';
if ($command === 'key:generate') {
    echo bin2hex(random_bytes(32)) . PHP_EOL;
    exit();
}
if ($command === 'migrate') {
    foreach (Migrator::run() as $version) {
        echo "$version appliquée.\n";
    }
    echo "Base à jour.\n";
    exit();
}
if ($command === 'seed') {
    if (env('APP_ENV') !== 'local') {
        throw new RuntimeException('Démonstration réservée au local.');
    }
    require ROOT . '/database/demo.php';
    exit();
}
if ($command === 'admin:create') {
    $email = Security::email($argv[2] ?? '');
    if (DB::one('SELECT id FROM users WHERE email=?', [$email])) {
        throw new RuntimeException('Ce compte existe déjà.');
    }
    $password = getenv('SILLAGE_ADMIN_PASSWORD') ?: 'Sillage-' . bin2hex(random_bytes(10)) . '!';
    DB::run(
        "INSERT INTO users(email,name,password_hash,role,verified_at) VALUES(?,?,?,'admin',UTC_TIMESTAMP())",
        [$email, Security::nameFromEmail($email), Security::password($password)],
    );
    echo "Administrateur créé : $email\nMot de passe initial : $password\nÀ modifier avant utilisation.\n";
    exit();
}
if ($command === 'user:role') {
    $email = Security::email($argv[2] ?? '', false);
    $role = $argv[3] ?? '';
    if (!in_array($role, ['admin', 'member', 'viewer'], true)) {
        throw new RuntimeException('Rôle attendu : admin, member, viewer.');
    }
    $u = DB::one('SELECT * FROM users WHERE email=?', [$email]);
    if (!$u) {
        throw new RuntimeException('Compte introuvable.');
    }
    App\Models\User::manage((int) $u['id'], $role, (bool) $u['active'], 0);
    echo "Rôle mis à jour.\n";
    exit();
}
if ($command === 'company:check') {
    $company = App\Core\Company::settings();
    echo 'Société : ' . $company['name'] . PHP_EOL . App\Core\Company::signupHint() . PHP_EOL;
    exit();
}
if ($command === 'health') {
    echo 'PHP ' .
        PHP_VERSION .
        ' · MySQL ' .
        DB::connection()->getAttribute(PDO::ATTR_SERVER_VERSION) .
        PHP_EOL;
    exit();
}
if ($command === 'mail:list') {
    if (env('APP_ENV') !== 'local') {
        throw new RuntimeException('Réservé au local.');
    }
    foreach (glob(ROOT . '/storage/mail/*.txt') as $mailFile) {
        echo basename($mailFile) . PHP_EOL;
    }
    exit();
}
echo "Sillage : migrate | seed | key:generate | health | company:check | mail:list | admin:create email | user:role email admin/member/viewer\n";
