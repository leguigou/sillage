<?php
declare(strict_types=1);
/**
 * Command-line entry point for operators, deployment scripts and background workers:
 * `php bin/console.php <command> [args]` (the full list is printed when no command matches).
 * Notable commands: migrate (applies database/*.sql via App\Core\Migrator, run on every deploy),
 * notifications:send (e-mail queue worker), git:work / git:watch (one pass or an endless 15-second
 * loop of Git synchronisation), admin:create and user:role (account bootstrap), key:generate (APP_KEY).
 * It refuses to run from a web request; seed and mail:list are restricted to APP_ENV=local.
 */
if (PHP_SAPI !== 'cli') {
    exit();
}
require dirname(__DIR__) . '/app/bootstrap.php';
use App\Core\{DB, Security, Migrator};
$command = $argv[1] ?? 'help';
if(in_array($command,['git:work','git:watch'],true)) {
    do {
        try {echo json_encode(\App\Services\GitSync::work(),JSON_THROW_ON_ERROR).PHP_EOL;}
        catch(\Throwable) {fwrite(STDERR,"Traitement Git indisponible. Vérifiez la base et les migrations.\n");if($command!=='git:watch') exit(1);}
        if($command==='git:watch') sleep(15);
    } while($command==='git:watch');
    exit();
}
if ($command==='logs:prune') {
    $removed=\App\Services\TechnicalLog::prune(null,10000);
    echo $removed.' appels expirés supprimés (conservation : '.\App\Services\TechnicalLog::retention().' jours).'.PHP_EOL;
    exit();
}
if (in_array($command,['version','history:check'],true)) {
    $history=\App\Services\ApplicationHistory::catalog();
    echo 'Sillage '.$history['current_version'].PHP_EOL;
    if ($command==='history:check') echo count($history['entries']).' entrées d’historique valides.'.PHP_EOL;
    exit();
}
if ($command === 'wiki:scan') {
    // Reconciles WIKI_PATH with the index: files added, changed or removed outside Sillage.
    $report = \App\Services\Wiki::scan();
    echo "Wiki : {$report['created']} page(s) ajoutée(s), {$report['external']} modification(s) externe(s), {$report['repaired']} fichier(s) réparé(s), {$report['deleted']} page(s) supprimée(s).
";
    foreach ($report['invalid'] as $line) echo "Ignoré : $line
";
    foreach ($report['rejected'] as [$path, $reason]) echo "Ignoré : $path — $reason
";
    exit();
}
if ($command === 'wiki:rebuild') {
    // Writes every active page from its latest revision into an empty directory (never the live wiki folder).
    $target = $argv[2] ?? '';
    if ($target === '') { fwrite(STDERR, "Usage : php bin/console.php wiki:rebuild <dossier vide>
"); exit(1); }
    echo \App\Services\Wiki::rebuild($target) . " page(s) écrite(s) dans $target.
";
    exit();
}
if ($command === 'notifications:send') {
    echo json_encode(\App\Services\Notifications::deliver(100),JSON_THROW_ON_ERROR).PHP_EOL;
    exit();
}
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
// The initial password comes from SILLAGE_ADMIN_PASSWORD or is generated, and is printed once to stdout.
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
    // Actor 0 is the CLI operator: it matches no account, so the self-demotion guard in User::manage never applies.
    App\Models\User::manage((int) $u['id'], $role, (bool) $u['active'], 0);
    echo "Rôle mis à jour.\n";
    exit();
}
if ($command === 'company:check') {
    $company = App\Core\Company::settings();
    echo 'Société : ' . $company['name'] . PHP_EOL . App\Core\Company::signupHint() . PHP_EOL;
    exit();
}
// Prints every MCP tool definition by building the catalog for a synthetic admin holding all scopes.
if ($command === 'mcp:tools') {
    $catalog = App\Mcp\Tools::catalog([
        'id' => 0,
        'name' => 'Opérateur CLI',
        'role' => 'admin',
        'scopes' => array_keys(App\Models\McpToken::SCOPES),
    ]);
    echo json_encode(
        array_values(array_map(fn($t) => $t['definition'], $catalog)),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
    ) . PHP_EOL;
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
echo "Sillage : version | history:check | logs:prune | migrate | seed | key:generate | health | mcp:tools | company:check | mail:list | notifications:send | git:work | git:watch | wiki:scan | wiki:rebuild dossier | admin:create email | user:role email admin/member/viewer\n";
