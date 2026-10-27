<?php
// Included inside the isolated test transaction by run.php.
use App\Models\{McpToken, Automation};
use App\Models\Ticket;
use App\Core\DB;
use App\Mcp\Schema;
$mcpUser = DB::one('SELECT * FROM users WHERE id=?', [$uid]);
$issued = McpToken::issue($mcpUser, 'Test métier', ['read', 'write', 'admin'], null);
$timed = McpToken::issue($mcpUser, 'Expiration dans une heure', ['read'], 3600);
$remaining = (int) DB::one(
    'SELECT TIMESTAMPDIFF(SECOND,UTC_TIMESTAMP(),expires_at) remaining_seconds FROM mcp_tokens WHERE id=?',
    [$timed['id']],
)['remaining_seconds'];
check($remaining > 3500 && $remaining <= 3600, 'durée de clé enregistrée en UTC');
rejects(
    fn() => McpToken::remove($timed['id'], $uid + 100000),
    'suppression de clé d’un autre compte refusée',
);
check(McpToken::authenticate($timed['key']) !== null, 'refus de suppression préserve la clé');
DB::run('UPDATE users SET active=0 WHERE id=?', [$uid]);
check(McpToken::authenticate($timed['key']) === null, 'compte désactivé refusé par le MCP');
DB::run('UPDATE users SET active=1,verified_at=NULL WHERE id=?', [$uid]);
check(McpToken::authenticate($timed['key']) === null, 'adresse non vérifiée refusée par le MCP');
DB::run('UPDATE users SET verified_at=UTC_TIMESTAMP() WHERE id=?', [$uid]);
McpToken::remove($timed['id'], $uid);
check(
    McpToken::authenticate($issued['key'])['id'] === $uid,
    'clé MCP sans expiration authentifiée',
);
check(
    DB::one('SELECT token_hash FROM mcp_tokens WHERE id=?', [$issued['id']])['token_hash'] !==
        $issued['key'],
    'clé MCP stockée sous forme d’empreinte',
);
DB::run("UPDATE users SET role='viewer' WHERE id=?", [$uid]);
check(
    McpToken::authenticate($issued['key'])['scopes'] === ['read'],
    'droits MCP limités par le rôle actuel',
);
rejects(
    fn() => McpToken::issue(
        DB::one('SELECT * FROM users WHERE id=?', [$uid]),
        'Escalade',
        ['admin'],
        null,
    ),
    'clé administrateur interdite au compte en lecture seule',
);
DB::run("UPDATE users SET role='admin' WHERE id=?", [$uid]);
DB::run('UPDATE mcp_tokens SET expires_at=UTC_TIMESTAMP()-INTERVAL 1 SECOND WHERE id=?', [
    $issued['id'],
]);
check(McpToken::authenticate($issued['key']) === null, 'clé expirée refusée à la frontière MySQL');
DB::run('UPDATE mcp_tokens SET expires_at=NULL WHERE id=?', [$issued['id']]);
DB::run('UPDATE users SET auth_version=auth_version+1 WHERE id=?', [$uid]);
check(McpToken::authenticate($issued['key']) === null, 'changement d’accès invalide la clé MCP');
DB::run('UPDATE users SET auth_version=auth_version-1 WHERE id=?', [$uid]);
McpToken::remove($issued['id'], $uid);
check(McpToken::authenticate($issued['key']) === null, 'suppression de clé coupe son accès');
check(McpToken::authenticate('clé-inconnue') === null, 'format de clé invalide refusé');
rejects(
    fn() => McpToken::issue($mcpUser, 'Trop court', ['read'], 1),
    'durée de clé trop courte refusée',
);
$shape = Schema::object(['id' => ['type' => 'integer', 'minimum' => 1]], ['id']);
check(
    Schema::validate((object) ['id' => 3], $shape) === ['id' => 3],
    'arguments MCP normalisés après validation',
);
check(
    Schema::validate((object) ['id' => 3.0], $shape) === ['id' => 3],
    'entier JSON écrit avec une décimale normalisé sans perte',
);
rejects(
    fn() => Schema::validate((object) ['id' => '3'], $shape),
    'MCP refuse une chaîne à la place d’un entier',
);
rejects(
    fn() => Schema::validate((object) ['id' => 3, 'sql' => 'DROP TABLE users'], $shape),
    'MCP refuse les arguments supplémentaires',
);
rejects(fn() => Schema::validate([], $shape), 'MCP distingue objet et tableau JSON');
$rule = Automation::save(
    [
        'project_id' => $p,
        'name' => 'Orienter les bugs',
        'event' => 'ticket.created',
        'conditions' => ['type' => 'bug'],
        'actions' => [
            'priority' => 'high',
            'flagged' => true,
            'estimated_hours' => '2,5',
            'labels' => ['auto'],
            'comment' => 'Pris en charge automatiquement.',
        ],
    ],
    $uid,
);
$automatic = Ticket::create($p, ['title' => 'Bug automatique', 'type' => 'bug'], $uid);
$automaticTicket = Ticket::find($automatic);
check(
    $automaticTicket['priority'] === 'high' &&
        $automaticTicket['flagged'] === 1 &&
        $automaticTicket['estimated_hours'] === '2.50',
    'automatisation applique les champs normalisés du ticket',
);
check(
    (int) DB::one('SELECT COUNT(*) n FROM comments WHERE ticket_id=?', [$automatic])['n'] === 1,
    'commentaire automatique ajouté une seule fois',
);
check(
    (int) DB::one('SELECT COUNT(*) n FROM automation_runs WHERE ticket_id=?', [$automatic])['n'] ===
        1,
    'automatisation sans récursion',
);
rejects(fn() => Automation::enabled($rule['id'], false, 9999), 'version obsolète de règle refusée');
$disabled = Automation::enabled($rule['id'], false, $rule['version']);
$noAutomation = Ticket::create($p, ['title' => 'Bug en pause', 'type' => 'bug'], $uid);
check(Ticket::find($noAutomation)['priority'] === 'medium', 'règle suspendue sans effet');
Automation::delete($disabled['id'], $disabled['version']);
check(
    DB::one('SELECT rule_id FROM automation_runs WHERE ticket_id=?', [$automatic])['rule_id'] ===
        null,
    'suppression de règle conserve son journal',
);
$target = DB::insert(
    "INSERT INTO users(email,name,password_hash,verified_at) VALUES('mcp.target@example.com','Cible automatique',?,UTC_TIMESTAMP())",
    [$hash],
);
Automation::save(
    [
        'project_id' => $p,
        'name' => 'Cible désactivée',
        'event' => 'ticket.created',
        'conditions' => ['type' => 'story'],
        'actions' => ['assignee_id' => $target, 'comment' => 'Ne doit pas être écrit.'],
    ],
    $uid,
);
DB::run('UPDATE users SET active=0 WHERE id=?', [$target]);
$failedAuto = Ticket::create($p, ['title' => 'Ticket conservé', 'type' => 'story'], $uid);
check(
    Ticket::find($failedAuto)['assignee_id'] === null &&
        (int) DB::one('SELECT COUNT(*) n FROM comments WHERE ticket_id=?', [$failedAuto])['n'] ===
            0,
    'échec automatique sans actions partielles et sans perte du ticket',
);
check(
    DB::one('SELECT succeeded FROM automation_runs WHERE ticket_id=?', [$failedAuto])[
        'succeeded'
    ] === 0,
    'erreur automatique consignée',
);
rejects(
    fn() => Automation::save(
        [
            'project_id' => $p,
            'name' => 'Code arbitraire',
            'event' => 'ticket.created',
            'actions' => ['sql' => 'DELETE FROM users'],
        ],
        $uid,
    ),
    'action automatique inconnue refusée',
);
Automation::save(
    [
        'project_id' => $p,
        'name' => 'Sous-tâche',
        'event' => 'ticket.status_changed',
        'conditions' => ['type' => 'subtask', 'status' => 'done'],
        'actions' => ['comment' => 'Sous-tâche terminée.'],
    ],
    $uid,
);
$sub = Ticket::create(
    $p,
    ['title' => 'Enfant automatique', 'type' => 'subtask', 'parent_id' => $one],
    $uid,
);
Ticket::patch($sub, ['status' => 'done'], $uid, Ticket::find($sub)['version']);
check(
    (int) DB::one('SELECT COUNT(*) n FROM comments WHERE ticket_id=?', [$sub])['n'] === 1,
    'condition de sous-tâche et changement d’état exécutés',
);
