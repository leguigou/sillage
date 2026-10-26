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
            ['qa.admin@cabesto.com', 'QA Admin', 'admin'],
            ['qa.viewer@cabesto.com', 'QA Lecture', 'viewer'],
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
check(
    Security::email(' Nom.Prenom@CABESTO.COM ') === 'nom.prenom@cabesto.com',
    'domaine exact normalisé',
);
foreach (
    ['test@cabesto.com.evil.net', 'test@sub.cabesto.com', 'test@gmail.com', 'test@evilcabesto.com']
    as $email
) {
    rejects(fn() => Security::email($email), 'refus de ' . $email);
}
check(
    Security::nameFromEmail('nom.prenom@cabesto.com') === 'Nom Prenom',
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
        "INSERT INTO users(email,name,password_hash,verified_at,role) VALUES('test.unit@cabesto.com','Test unitaire',?,UTC_TIMESTAMP(),'admin')",
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
        "INSERT INTO users(email,name,password_hash,verified_at) VALUES('unit.member@cabesto.com','Membre',?,UTC_TIMESTAMP())",
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
} finally {
    DB::connection()->rollBack();
}
echo "\n$passed contrôles réussis. Écritures annulées.\n";
