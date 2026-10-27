<?php
declare(strict_types=1);
if (!getenv('DB_DATABASE') && is_file(dirname(__DIR__) . '/.runtime/test-env.json')) {
    foreach (
        json_decode(file_get_contents(dirname(__DIR__) . '/.runtime/test-env.json'), true)
        as $k => $v
    ) {
        putenv($k . '=' . $v);
    }
}
require dirname(__DIR__) . '/app/bootstrap.php';
use App\Core\{DB, Security, Migrator};
use App\Models\Ticket;
use App\Services\{Csv, Filter, Query};
if (env('DB_DATABASE') !== 'sillage_test') {
    throw new RuntimeException('Les tests nécessitent la base isolée sillage_test.');
}
Migrator::run();
if (($argv[1] ?? '') === 'fixtures') {
    DB::run('DELETE FROM rate_limits');
    foreach (
        [
            ['qa.admin@example.com', 'QA Admin', 'admin'],
            ['qa.viewer@example.com', 'QA Lecture', 'viewer'],
        ]
        as [$email, $name, $role]
    ) {
        if (!DB::one('SELECT id FROM users WHERE email=?', [$email])) {
            DB::run(
                'INSERT INTO users(email,name,password_hash,role,verified_at) VALUES(?,?,?,?,UTC_TIMESTAMP())',
                [$email, $name, Security::password('Tests-Sillage-2026!'), $role],
            );
        }
    }
    if (!DB::one("SELECT id FROM projects WHERE project_key='QA'")) {
        DB::run("INSERT INTO projects(name,project_key) VALUES('Projet de test HTTP','QA')");
    }
    $p = DB::one("SELECT id FROM projects WHERE project_key='QA'");
    if (
        !DB::one("SELECT id FROM connections WHERE repository='qa/repo' AND project_id=?", [
            $p['id'],
        ])
    ) {
        DB::run(
            'INSERT INTO connections(project_id,provider,repository,secret_cipher,webhook_cipher) VALUES(?,?,?,?,?)',
            [
                $p['id'],
                'github',
                'qa/repo',
                Security::encrypt('test-api-only'),
                Security::encrypt('test-webhook-secret'),
            ],
        );
    }
    echo "Fixtures HTTP isolées prêtes.\n";
    exit();
}
$passed = 0;
function check(bool $ok, string $name): void
{
    global $passed;
    if (!$ok) {
        throw new RuntimeException('ÉCHEC : ' . $name);
    }
    $passed++;
    echo "OK $name\n";
}
function rejects(callable $fn, string $name): void
{
    try {
        $fn();
    } catch (InvalidArgumentException) {
        check(true, $name);
        return;
    }
    throw new RuntimeException('ÉCHEC : ' . $name);
}
$originalCompanyEnvironment = [];
$originalSessionUrl = getenv('APP_URL');
$originalSessionKey = getenv('APP_KEY');
try {
    $sessionName = Security::sessionName();
    putenv('APP_URL=http://127.0.0.1:8091');
    check(Security::sessionName() !== $sessionName, 'sessions isolées entre URLs et ports');
    putenv('APP_URL=http://127.0.0.1:8091/');
    $otherSessionName = Security::sessionName();
    putenv('APP_URL=http://127.0.0.1:8091');
    check(Security::sessionName() === $otherSessionName, 'URL de session normalisée');
    putenv('APP_KEY=' . bin2hex(random_bytes(32)));
    check(Security::sessionName() !== $otherSessionName, 'sessions isolées par clé installation');
} finally {
    putenv($originalSessionUrl === false ? 'APP_URL' : 'APP_URL=' . $originalSessionUrl);
    putenv($originalSessionKey === false ? 'APP_KEY' : 'APP_KEY=' . $originalSessionKey);
}
foreach (
    ['COMPANY_NAME', 'ALLOWED_DOMAINS', 'ALLOWED_DOMAIN', 'EMAIL_DOMAIN_RESTRICTION']
    as $key
) {
    $originalCompanyEnvironment[$key] = getenv($key);
}
function rejectsCompanyConfiguration(callable $fn, string $name): void
{
    try {
        $fn();
    } catch (RuntimeException) {
        check(true, $name);
        return;
    }
    throw new RuntimeException('ÉCHEC : ' . $name);
}
try {
    putenv('COMPANY_NAME=Équipe Horizon & Associés');
    check(
        Security::sessionName() === $sessionName,
        'personnalisation société sans changement de session',
    );
    putenv('ALLOWED_DOMAINS= ATELIER.EXAMPLE , services.example,atelier.example ');
    putenv('EMAIL_DOMAIN_RESTRICTION=true');
    check(
        App\Core\Company::name() === 'Équipe Horizon & Associés' &&
            App\Core\Company::initial() === 'É',
        'nom de société et initiale Unicode',
    );
    check(
        App\Core\Company::settings()['domains'] === ['atelier.example', 'services.example'],
        'domaines normalisés et dédupliqués',
    );
    check(
        Security::email(' Nom.Prenom@ATELIER.EXAMPLE ') === 'nom.prenom@atelier.example',
        'premier domaine configurable accepté',
    );
    check(
        Security::email('test@services.example') === 'test@services.example',
        'second domaine configurable accepté',
    );
    rejects(
        fn() => Security::email('test@sub.atelier.example'),
        'sous-domaine non autorisé refusé',
    );
    rejects(
        fn() => Security::email('test@atelier.example.evil.test'),
        'domaine ressemblant refusé',
    );
    check(
        Security::email('ancien@ancienne-societe.example', false) ===
            'ancien@ancienne-societe.example',
        'syntaxe des comptes existants indépendante des nouveaux domaines',
    );
    foreach (
        [
            '*.atelier.example',
            '@atelier.example',
            'https://atelier.example',
            'atelier.example,',
            'atelier.example.',
        ]
        as $invalid
    ) {
        putenv('ALLOWED_DOMAINS=' . $invalid);
        rejectsCompanyConfiguration(
            fn() => App\Core\Company::settings(),
            'configuration de domaine invalide refusée : ' . $invalid,
        );
    }
    putenv('ALLOWED_DOMAINS=');
    rejectsCompanyConfiguration(
        fn() => App\Core\Company::settings(),
        'liste vide fermée par défaut',
    );
    putenv('EMAIL_DOMAIN_RESTRICTION=false');
    check(
        Security::email('libre@autre.example') === 'libre@autre.example',
        'désactivation explicite de la restriction',
    );
    rejects(fn() => Security::email('invalide'), 'adresse invalide refusée même sans restriction');
    putenv('EMAIL_DOMAIN_RESTRICTION=tru');
    rejectsCompanyConfiguration(
        fn() => App\Core\Company::settings(),
        'option mal orthographiée ne désactive pas la restriction',
    );
    putenv('EMAIL_DOMAIN_RESTRICTION=true');
    putenv('ALLOWED_DOMAINS');
    putenv('ALLOWED_DOMAIN=historique.example');
    check(
        Security::email('compte@historique.example') === 'compte@historique.example',
        'ancienne variable de domaine reconnue si utilisée seule',
    );
    putenv('ALLOWED_DOMAINS=nouvelle.example');
    rejects(
        fn() => Security::email('compte@historique.example'),
        'liste explicite prioritaire sur ancienne variable',
    );
    putenv('COMPANY_NAME=');
    rejectsCompanyConfiguration(fn() => App\Core\Company::settings(), 'nom vide refusé');
    putenv("COMPANY_NAME=Nom\nInjecté");
    rejectsCompanyConfiguration(fn() => App\Core\Company::settings(), 'nom multiligne refusé');
    putenv('COMPANY_NAME=Atelier <Horizon>');
    check(
        e(App\Core\Company::name()) === 'Atelier &lt;Horizon&gt;',
        'nom de société échappé au rendu',
    );
} finally {
    foreach ($originalCompanyEnvironment as $key => $value) {
        putenv($value === false ? $key : $key . '=' . $value);
    }
}
check(
    Security::email(' Nom.Prenom@EXAMPLE.COM ') === 'nom.prenom@example.com',
    'domaine exact normalisé',
);
foreach (
    ['test@example.com.evil.net', 'test@sub.example.com', 'test@gmail.com', 'test@evilexample.com']
    as $email
) {
    rejects(fn() => Security::email($email), 'refus de ' . $email);
}
check(
    Security::nameFromEmail('nom.prenom@example.com') === 'Nom Prenom',
    'nom déduit de l’adresse',
);
$hash = Security::password('UnePhraseUnique!2026');
check(password_verify('UnePhraseUnique!2026', $hash), 'hachage et vérification');
rejects(fn() => Security::password('court'), 'mot de passe court refusé');
rejects(fn() => Security::date('2026-02-30'), 'date impossible refusée');
check(
    Security::decrypt(Security::encrypt('secret-test')) === 'secret-test',
    'chiffrement authentifié',
);
[$sql, $params] = (new Filter())->parse(
    'status = "En cours" AND (priority IN (high, highest) OR title ~ "catalogue")',
);
check(count($params) === 4 && $params[0] === 'progress', 'requête composée paramétrée');
rejects(fn() => (new Filter())->parse('title = "x"; DROP TABLE users'), 'injection SQL refusée');
rejects(fn() => (new Filter())->parse('unknown = x'), 'champ inconnu refusé');
rejects(
    fn() => (new Filter())->parse('status = todo ORDER BY id'),
    'syntaxe non prise en charge refusée',
);
check(Csv::safe('=SUM(1,2)') === "'=SUM(1,2)", 'formule CSV neutralisée');
check(Csv::safe('  @SUM(A1)') === "'  @SUM(A1)", 'formule CSV après espaces neutralisée');
$temp = tempnam(sys_get_temp_dir(), 'sillage-csv-');
file_put_contents($temp, "title;status\nPremier ticket;todo\n");
check(Csv::parse($temp)['rows'][0]['title'] === 'Premier ticket', 'CSV point-virgule');
unlink($temp);
DB::connection()->beginTransaction();
try {
    $uid = DB::insert(
        "INSERT INTO users(email,name,password_hash,verified_at,role) VALUES('test.unit@example.com','Test unitaire',?,UTC_TIMESTAMP(),'admin')",
        [$hash],
    );
    $p = DB::insert("INSERT INTO projects(name,project_key) VALUES('Test isolé','UNIT')");
    $other = DB::insert("INSERT INTO projects(name,project_key) VALUES('Autre projet','OTHER')");
    $one = Ticket::create($p, ['title' => 'Premier ticket', 'labels' => 'alpha, beta'], $uid);
    $two = Ticket::create($p, ['title' => 'Deuxième ticket', 'parent_id' => $one], $uid);
    check(
        Ticket::find($one)['number'] === 1 && Ticket::find($two)['number'] === 2,
        'numérotation par projet',
    );
    check(Ticket::find($one)['labels_array'] === ['alpha', 'beta'], 'étiquettes normalisées');
    rejects(
        fn() => Ticket::create($other, ['title' => 'Parent étranger', 'parent_id' => $one], $uid),
        'parent étranger refusé',
    );
    check(Ticket::find($one)['estimated_hours'] === null, 'estimation absente distincte de zéro');
    $estimate = Ticket::create($p, ['title' => 'Estimation', 'estimated_hours' => '2,50'], $uid);
    check(
        Ticket::find($estimate)['estimated_hours'] === '2.50',
        'heures décimales françaises persistées',
    );
    foreach (['-1', '1.234', '1e3', '1000000', 'abc'] as $invalid) {
        rejects(
            fn() => Ticket::validate(['title' => 'Test', 'estimated_hours' => $invalid], $p),
            'estimation invalide refusée : ' . $invalid,
        );
    }
    check(
        Ticket::validate(['title' => 'Test', 'estimated_hours' => '0'], $p)['estimated_hours'] ===
            '0',
        'zéro heure accepté',
    );
    check(
        Ticket::validate(['title' => 'Test', 'estimated_hours' => '999999.99'], $p)[
            'estimated_hours'
        ] === '999999.99',
        'borne du format décimal acceptée',
    );
    DB::run('DELETE FROM tickets WHERE id=?', [$estimate]);
    $team = App\Models\Team::save(null, 'Équipe unitaire');
    App\Models\Team::membership($team, $uid, true);
    App\Models\Team::membership($team, $uid, true);
    check(
        (int) DB::one('SELECT COUNT(*) n FROM team_members WHERE team_id=?', [$team])['n'] === 1,
        'ajout de membre idempotent',
    );
    App\Models\Team::save($team, 'Équipe renommée');
    check(App\Models\Team::find($team)['name'] === 'Équipe renommée', 'équipe renommée');
    rejects(fn() => App\Models\Team::save(null, 'Équipe renommée'), 'nom équipe en double refusé');
    $member = DB::insert(
        "INSERT INTO users(email,name,password_hash,verified_at) VALUES('unit.member@example.com','Membre',?,UTC_TIMESTAMP())",
        [$hash],
    );
    App\Models\User::manage($member, 'viewer', false, $uid);
    $managed = DB::one('SELECT * FROM users WHERE id=?', [$member]);
    check(
        $managed['role'] === 'viewer' && !$managed['active'] && $managed['auth_version'] === 2,
        'rôle et désactivation invalident les sessions',
    );
    App\Models\User::manage($member, 'viewer', false, $uid);
    check(
        DB::one('SELECT auth_version FROM users WHERE id=?', [$member])['auth_version'] === 2,
        'accès inchangés sans fermeture supplémentaire',
    );
    rejects(fn() => App\Models\Team::membership($team, $member, true), 'membre désactivé refusé');
    App\Models\User::manage($member, 'member', true, $uid);
    App\Models\Team::membership($team, $member, true);
    DB::run('UPDATE users SET verified_at=NULL WHERE id=?', [$member]);
    rejects(
        fn() => App\Models\Team::membership($team, $member, true),
        'adresse non vérifiée refusée',
    );
    App\Models\Team::membership($team, $member, false);
    check(
        !DB::one('SELECT user_id FROM team_members WHERE team_id=? AND user_id=?', [
            $team,
            $member,
        ]),
        'retrait de membre',
    );
    rejects(
        fn() => App\Models\User::manage($uid, 'viewer', true, $uid),
        'auto-rétrogradation administrateur refusée',
    );
    DB::run("UPDATE users SET role='member' WHERE id<>?", [$uid]);
    rejects(
        fn() => App\Models\User::manage($uid, 'member', true, 0),
        'dernier administrateur protégé',
    );
    DB::run('DELETE FROM teams WHERE id=?', [$team]);
    check(
        !DB::one('SELECT user_id FROM team_members WHERE team_id=?', [$team]),
        'suppression équipe nettoie uniquement les adhésions',
    );
    $old = Ticket::find($one);
    rejects(
        fn() => Ticket::update(
            $one,
            array_replace($old, ['parent_id' => $two]),
            $uid,
            (int) $old['version'],
        ),
        'cycle hiérarchique refusé',
    );
    $data = array_replace($old, ['labels' => $old['labels_array'], 'status' => 'done']);
    Ticket::update($one, $data, $uid, (int) $old['version']);
    check(Ticket::find($one)['completed_at'] !== null, 'résolution datée');
    rejects(
        fn() => Ticket::update($one, $data, $uid, (int) $old['version']),
        'écriture obsolète refusée',
    );
    check(Query::count($p, ['status' => 'done']) === 1, 'filtrage MySQL réel');
    check(Query::count($p, ['q' => "' OR 1=1 --"]) === 0, 'valeurs de recherche non exécutées');
    check(count(Query::aggregate($p, [], 'status')) === 2, 'agrégations MySQL réelles');
    rejects(
        fn() => Ticket::create(
            $p,
            [
                'title' => 'Dates inversées',
                'start_date' => '2026-12-10',
                'due_date' => '2026-12-01',
            ],
            $uid,
        ),
        'dates inversées refusées',
    );
    rejects(
        fn() => Ticket::create($p, ['title' => 'Sous-tâche', 'type' => 'subtask'], $uid),
        'parent requis pour sous-tâche',
    );
    require __DIR__ . '/mcp_models.php';
    require __DIR__ . '/timeline_calendar.php';
} finally {
    DB::connection()->rollBack();
}
echo "\n$passed contrôles réussis. Écritures annulées.\n";
