<?php
declare(strict_types=1);
use App\Core\{Company, DB, Security};
use App\Models\Ticket;
if (PHP_SAPI !== 'cli' || env('APP_ENV') !== 'local') {
    throw new RuntimeException('Démonstration réservée au local.');
}
if (DB::one('SELECT id FROM users LIMIT 1')) {
    echo "Données existantes conservées.\n";
    return;
}
$password = 'Sillage-' . bin2hex(random_bytes(8)) . '!';
$hash = Security::password($password);
$ids = [];
$people = [
    ['Camille Martin', 'camille.martin'],
    ['Léa Bernard', 'lea.bernard'],
    ['Hugo Petit', 'hugo.petit'],
    ['Thomas Laurent', 'thomas.laurent'],
];
DB::transaction(function () use ($people, $hash, &$ids) {
    foreach ($people as $i => [$name, $email]) {
        $ids[] = DB::insert(
            'INSERT INTO users(email,name,password_hash,role,verified_at) VALUES(?,?,?,?,UTC_TIMESTAMP())',
            [
                Security::email(
                    $email . '@' . (Company::settings()['domains'][0] ?? 'example.com'),
                ),
                $name,
                $hash,
                $i === 0 ? 'admin' : 'member',
            ],
        );
    }
    $p = DB::insert('INSERT INTO projects(name,project_key,description) VALUES(?,?,?)', [
        'Expérience digitale',
        'DIG',
        'Des expériences plus simples, du premier clic au magasin.',
    ]);
    DB::insert('INSERT INTO projects(name,project_key,description) VALUES(?,?,?)', [
        'Opérations magasin',
        'MAG',
        'Le quotidien des équipes, en mouvement.',
    ]);
    $date = fn(int $d) => (new DateTimeImmutable('today'))
        ->modify(($d >= 0 ? '+' : '') . $d . ' days')
        ->format('Y-m-d');
    $epic = Ticket::create(
        $p,
        [
            'title' => 'Une expérience client qui fait la différence',
            'type' => 'epic',
            'start_date' => $date(-8),
            'due_date' => $date(30),
            'labels' => 'expérience-client',
            'assignee_id' => $ids[0],
        ],
        $ids[0],
    );
    $rows = [
        ['Repenser la navigation mobile', 'story', 'progress', 'high', 1, -3, 6, 'design, mobile'],
        [
            'Simplifier le parcours de réservation',
            'task',
            'todo',
            'medium',
            2,
            2,
            12,
            'parcours-client',
        ],
        [
            'Corriger le filtre des tailles sur mobile',
            'bug',
            'todo',
            'highest',
            2,
            -2,
            2,
            'mobile, catalogue',
        ],
        [
            'Maquetter les nouvelles fiches produits',
            'task',
            'review',
            'medium',
            1,
            -5,
            3,
            'design, catalogue',
        ],
        [
            'Améliorer le suivi de disponibilité en magasin',
            'story',
            'progress',
            'high',
            3,
            -4,
            9,
            'stock, magasin',
        ],
        ['Documenter les retours du terrain', 'task', 'todo', 'low', 0, 3, 15, 'équipe'],
        [
            'Valider les composants d’interface',
            'task',
            'done',
            'medium',
            1,
            -9,
            -2,
            'design-system',
        ],
        [
            'Accélérer le chargement du catalogue',
            'task',
            'progress',
            'high',
            2,
            -3,
            8,
            'performance',
        ],
        ['Harmoniser les messages de confirmation', 'task', 'todo', 'low', null, 5, 20, 'contenu'],
        ['Tester les parcours avec les équipes', 'story', 'review', 'medium', 3, -5, 4, 'tests'],
        ['Mettre à jour les visuels de collection', 'task', 'done', 'low', 1, -10, -3, 'contenu'],
        ['Clarifier les conditions de retrait', 'task', 'todo', 'medium', null, 4, 14, 'magasin'],
    ];
    $first = null;
    foreach ($rows as $i => [$title, $type, $status, $priority, $a, $start, $due, $labels]) {
        $id = Ticket::create(
            $p,
            [
                'title' => $title,
                'type' => $type,
                'status' => $status,
                'priority' => $priority,
                'description' =>
                    "Contexte\nAméliorer ce point du parcours pour une expérience plus simple et plus cohérente.\n\nRésultat attendu\nUne solution claire, testée avec l’équipe et prête à être partagée.",
                'assignee_id' => $a === null ? null : $ids[$a],
                'start_date' => $date($start),
                'due_date' => $date($due),
                'parent_id' => $epic,
                'labels' => $labels,
                'flagged' => $i === 2,
            ],
            $ids[0],
        );
        $first ??= $id;
        if ($i < 5) {
            DB::run('INSERT INTO comments(ticket_id,user_id,body) VALUES(?,?,?)', [
                $id,
                $ids[($i + 1) % 4],
                'Première version prête pour les retours de l’équipe.',
            ]);
        }
    }
    Ticket::create(
        $p,
        [
            'title' => 'Préparer les variantes de navigation',
            'type' => 'subtask',
            'parent_id' => $first,
            'assignee_id' => $ids[1],
            'start_date' => $date(0),
            'due_date' => $date(4),
            'labels' => 'mobile',
        ],
        $ids[0],
    );
    DB::run(
        'INSERT INTO formatting_rules(project_id,field,match_value,color,title) VALUES(?,?,?,?,?)',
        [$p, 'priority', 'highest', '#d98e81', 'Priorités critiques'],
    );
});
if (!is_dir(ROOT . '/.runtime')) {
    mkdir(ROOT . '/.runtime', 0700, true);
}
file_put_contents(
    ROOT . '/.runtime/credentials.txt',
    "Démonstration locale Sillage\nAdresse : " .
        Security::email('camille.martin@' . (Company::settings()['domains'][0] ?? 'example.com')) .
        "\nMot de passe : $password\nURL : " .
        url() .
        "\nNe pas utiliser ces comptes en production.\n",
);
echo "Démonstration créée. Accès dans .runtime/credentials.txt.\n";
