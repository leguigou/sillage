<?php
declare(strict_types=1);
namespace App\Mcp;
use App\Core\{Company, DB};
use App\Models\{Ticket, Project, PublicForm, FormattingRule, Automation, Team, User};
use App\Services\Query;

final class Tools
{
    private static function page(
        string $key,
        string $fields,
        string $from,
        array $params,
        array $input,
        string $order,
    ): array {
        $limit = $input['limit'] ?? 50;
        $offset = $input['offset'] ?? 0;
        return [
            'total' => (int) DB::one('SELECT COUNT(*) n FROM ' . $from, $params)['n'],
            'limit' => $limit,
            'offset' => $offset,
            $key => DB::all(
                'SELECT ' .
                    $fields .
                    ' FROM ' .
                    $from .
                    ' ORDER BY ' .
                    $order .
                    ' LIMIT ' .
                    $limit .
                    ' OFFSET ' .
                    $offset,
                $params,
            ),
        ];
    }
    public static function catalog(array $user): array
    {
        $tools = [];
        $add = function (
            string $name,
            string $description,
            string $scope,
            array $schema,
            callable $handler,
            bool $destructive = false,
        ) use (&$tools, $user) {
            if (!in_array($scope, $user['scopes'], true)) {
                return;
            }
            $readOnly = $scope === 'read' || $name === 'users_list';
            $tools[$name] = [
                'definition' => [
                    'name' => $name,
                    'description' => $description,
                    'inputSchema' => $schema,
                    'annotations' => [
                        'readOnlyHint' => $readOnly,
                        'destructiveHint' => $destructive,
                        'idempotentHint' => $readOnly,
                        'openWorldHint' => false,
                    ],
                ],
                'handler' => $handler,
                'scope' => $scope,
            ];
        };
        $str = fn(int $max = 120) => ['type' => 'string', 'maxLength' => $max];
        $enum = fn(array $values) => ['type' => 'string', 'enum' => array_values($values)];
        $id = ['type' => 'integer', 'minimum' => 1, 'maximum' => PHP_INT_MAX];
        $bool = ['type' => 'boolean'];
        $nullableId = ['type' => ['integer', 'null'], 'minimum' => 1, 'maximum' => PHP_INT_MAX];
        $page = [
            'offset' => ['type' => 'integer', 'minimum' => 0, 'maximum' => PHP_INT_MAX],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
        ];
        $obj = fn(array $p = [], array $r = []) => Schema::object($p, $r);
        $labels = ['type' => 'array', 'items' => $str(40), 'maxItems' => 12];
        $ticketFields = [
            'title' => $str(240),
            'description' => $str(100000),
            'type' => $enum(array_keys(Ticket::TYPES)),
            'status' => $enum(array_keys(Ticket::STATUSES)),
            'priority' => $enum(array_keys(Ticket::PRIORITIES)),
            'assignee_id' => $nullableId,
            'parent_id' => $nullableId,
            'labels' => $labels,
            'start_date' => ['type' => ['string', 'null'], 'maxLength' => 10],
            'due_date' => ['type' => ['string', 'null'], 'maxLength' => 10],
            'flagged' => $bool,
            'estimated_hours' => [
                'type' => ['string', 'number', 'null'],
                'maxLength' => 9,
                'minimum' => 0,
                'maximum' => 999999.99,
            ],
        ];
        $add(
            'workspace_info',
            'Société, droits effectifs et valeurs autorisées pour les tickets.',
            'read',
            $obj(),
            fn($a) => [
                'company' => Company::name(),
                'user' => ['id' => $user['id'], 'name' => $user['name'], 'role' => $user['role']],
                'scopes' => $user['scopes'],
                'statuses' => Ticket::STATUSES,
                'types' => Ticket::TYPES,
                'priorities' => Ticket::PRIORITIES,
                'automation_events' => Automation::EVENTS,
            ],
        );
        $add(
            'projects_list',
            'Lister les projets de cette installation.',
            'read',
            $obj($page),
            fn($a) => self::page('projects', '*', 'projects', [], $a, 'id'),
        );
        $projectProperties = [
            'name' => $str(),
            'key' => $str(10),
            'description' => $str(2000),
            'color' => $str(7),
        ];
        $add(
            'projects_create',
            'Créer un projet avec une clé unique et immuable.',
            'admin',
            $obj($projectProperties, ['name', 'key']),
            fn($a) => Project::find(Project::save($a)),
        );
        $add(
            'projects_update',
            'Modifier le nom, la description ou la couleur d’un projet.',
            'admin',
            $obj(
                ['id' => $id, 'name' => $str(), 'description' => $str(2000), 'color' => $str(7)],
                ['id'],
            ),
            fn($a) => Project::find(Project::save($a, $a['id'])),
        );
        $add(
            'projects_delete_empty',
            'Supprimer uniquement un projet sans données associées.',
            'admin',
            $obj(['id' => $id], ['id']),
            function ($a) {
                Project::deleteEmpty($a['id']);
                return ['deleted' => true];
            },
            true,
        );
        $filters = $obj([
            'q' => $str(240),
            'jql' => $str(4000),
            'status' => $enum(array_keys(Ticket::STATUSES)),
            'priority' => $enum(array_keys(Ticket::PRIORITIES)),
            'type' => $enum(array_keys(Ticket::TYPES)),
            'assignee' => ['type' => ['string', 'integer']],
            'hide_done' => $bool,
            'flagged' => $bool,
            'label' => $str(40),
            'sort' => $enum([
                'rank',
                'title',
                'status',
                'priority',
                'due',
                'start',
                'created',
                'updated',
                'assignee',
                'type',
                'estimated_hours',
            ]),
            'direction' => $enum(['asc', 'desc']),
        ]);
        $add(
            'tickets_list',
            'Rechercher les tickets par projet ou dans toute l’installation, filtres simples ou JQL partiel. Les descriptions se lisent avec tickets_get.',
            'read',
            $obj(['project_id' => $id, 'filters' => $filters] + $page),
            function ($a) {
                $pid = $a['project_id'] ?? 0;
                if ($pid) {
                    Project::find($pid);
                }
                $filters = $a['filters'] ?? [];
                $limit = $a['limit'] ?? 50;
                $offset = $a['offset'] ?? 0;
                $rows = Query::tickets($pid, $filters, $limit, $offset);
                foreach ($rows as &$r) {
                    unset($r['description'], $r['labels']);
                }
                unset($r);
                return [
                    'total' => Query::count($pid, $filters),
                    'offset' => $offset,
                    'limit' => $limit,
                    'tickets' => $rows,
                ];
            },
        );
        $add(
            'tickets_get',
            'Lire un ticket, ses dates, sa version et son estimation horaire.',
            'read',
            $obj(['id' => $id], ['id']),
            fn($a) => Ticket::find($a['id']),
        );
        $add(
            'tickets_create',
            'Créer un ticket. Les automatisations du projet s’appliquent avant le résultat.',
            'write',
            $obj(
                ['project_id' => $id, 'fields' => $obj($ticketFields, ['title'])],
                ['project_id', 'fields'],
            ),
            fn($a) => Ticket::find(
                Ticket::create($a['project_id'], $a['fields'], (int) $user['id']),
            ),
        );
        $patch = $obj($ticketFields);
        $patch['minProperties'] = 1;
        $add(
            'tickets_update',
            'Modifier uniquement les champs fournis ; version actuelle obligatoire pour éviter les conflits.',
            'write',
            $obj(['id' => $id, 'version' => $id, 'fields' => $patch], ['id', 'version', 'fields']),
            function ($a) use ($user) {
                Ticket::patch($a['id'], $a['fields'], (int) $user['id'], $a['version']);
                return Ticket::find($a['id']);
            },
        );
        $add(
            'tickets_archive',
            'Archiver ou restaurer un ticket avec contrôle de version.',
            'write',
            $obj(
                ['id' => $id, 'version' => $id, 'archived' => $bool],
                ['id', 'version', 'archived'],
            ),
            function ($a) use ($user) {
                Ticket::archive($a['id'], $a['archived'], (int) $user['id'], $a['version']);
                return Ticket::find($a['id']);
            },
        );
        $add(
            'tickets_comment',
            'Ajouter un commentaire interne au ticket.',
            'write',
            $obj(['id' => $id, 'body' => $str(10000)], ['id', 'body']),
            fn($a) => ['id' => Ticket::comment($a['id'], $a['body'], (int) $user['id'])],
        );
        $add(
            'tickets_comments_list',
            'Lire les commentaires paginés d’un ticket.',
            'read',
            $obj(['id' => $id] + $page, ['id']),
            function ($a) {
                Ticket::find($a['id']);
                $limit = $a['limit'] ?? 20;
                $offset = $a['offset'] ?? 0;
                return [
                    'total' => (int) DB::one('SELECT COUNT(*) n FROM comments WHERE ticket_id=?', [
                        $a['id'],
                    ])['n'],
                    'comments' => DB::all(
                        'SELECT c.id,c.body,c.created_at,u.name FROM comments c JOIN users u ON u.id=c.user_id WHERE ticket_id=? ORDER BY c.id LIMIT ' .
                            $limit .
                            ' OFFSET ' .
                            $offset,
                        [$a['id']],
                    ),
                ];
            },
        );
        $add(
            'tickets_link',
            'Associer deux tickets du même projet ; les dépendances circulaires sont refusées.',
            'write',
            $obj(
                [
                    'id' => $id,
                    'target_id' => $id,
                    'relation' => $enum(['relates', 'blocks', 'duplicates']),
                ],
                ['id', 'target_id', 'relation'],
            ),
            function ($a) use ($user) {
                Ticket::link($a['id'], $a['target_id'], $a['relation'], (int) $user['id']);
                return ['linked' => true];
            },
        );
        $add(
            'tickets_links_list',
            'Lister les relations sortantes d’un ticket.',
            'read',
            $obj(['id' => $id] + $page, ['id']),
            function ($a) {
                Ticket::find($a['id']);
                $limit = $a['limit'] ?? 50;
                $offset = $a['offset'] ?? 0;
                return [
                    'total' => (int) DB::one(
                        'SELECT COUNT(*) n FROM ticket_links WHERE source_id=?',
                        [$a['id']],
                    )['n'],
                    'links' => DB::all(
                        'SELECT * FROM ticket_links WHERE source_id=? ORDER BY id LIMIT ' .
                            $limit .
                            ' OFFSET ' .
                            $offset,
                        [$a['id']],
                    ),
                ];
            },
        );
        $add(
            'tickets_unlink',
            'Retirer une relation entre tickets.',
            'write',
            $obj(['id' => $id], ['id']),
            fn($a) => ['ticket_id' => Ticket::unlink($a['id'])],
            true,
        );
        $add(
            'tickets_bulk_update',
            'Modifier jusqu’à 100 tickets dans une transaction, chaque version devant correspondre.',
            'write',
            $obj(
                [
                    'tickets' => [
                        'type' => 'array',
                        'minItems' => 1,
                        'maxItems' => 100,
                        'items' => $obj(['id' => $id, 'version' => $id], ['id', 'version']),
                    ],
                    'fields' => $patch,
                ],
                ['tickets', 'fields'],
            ),
            function ($a) use ($user) {
                foreach ($a['tickets'] as $t) {
                    Ticket::patch($t['id'], $a['fields'], (int) $user['id'], $t['version']);
                }
                return ['updated' => count($a['tickets'])];
            },
        );
        $add(
            'forms_list',
            'Lister les formulaires d’un projet, sans empreintes de liens.',
            'read',
            $obj(['project_id' => $id] + $page, ['project_id']),
            function ($a) {
                Project::find($a['project_id']);
                return self::page(
                    'forms',
                    'id,title,ticket_type,enabled,created_at',
                    'public_forms WHERE project_id=?',
                    [$a['project_id']],
                    $a,
                    'id',
                );
            },
        );
        $add(
            'forms_get',
            'Lire les champs, libellés et conditions d’un formulaire.',
            'read',
            $obj(['id' => $id], ['id']),
            fn($a) => PublicForm::find($a['id']),
        );
        $formFields = array_keys(PublicForm::FIELDS);
        $formLabels = [];
        $conditionFields = [];
        $conditionValues = [];
        foreach ($formFields as $f) {
            $formLabels[$f] = $str();
            $conditionFields[$f] = $enum(['', 'priority']);
            $conditionValues[$f] = $enum(array_keys(Ticket::PRIORITIES));
        }
        $add(
            'forms_save',
            'Créer ou modifier un formulaire. Les champs omis sont conservés à la modification. Le lien public est renvoyé une seule fois à la création ; il permet uniquement de soumettre une demande.',
            'admin',
            $obj(
                [
                    'id' => $id,
                    'project_id' => $id,
                    'title' => $str(160),
                    'description' => $str(5000),
                    'ticket_type' => $enum(['task', 'story', 'bug', 'epic']),
                    'fields' => ['type' => 'array', 'items' => $enum($formFields), 'maxItems' => 5],
                    'required' => [
                        'type' => 'array',
                        'items' => $enum($formFields),
                        'maxItems' => 5,
                    ],
                    'labels' => $obj($formLabels),
                    'condition_field' => $obj($conditionFields),
                    'condition_value' => $obj($conditionValues),
                ],
                ['project_id', 'title'],
            ),
            fn($a) => PublicForm::save($a, (int) $user['id']),
        );
        $add(
            'forms_set_enabled',
            'Activer ou désactiver un lien de collecte.',
            'admin',
            $obj(['id' => $id, 'enabled' => $bool], ['id', 'enabled']),
            fn($a) => PublicForm::enabled($a['id'], $a['enabled']),
        );
        $add(
            'forms_rotate_link',
            'Créer un nouveau lien public ; le précédent cesse immédiatement de fonctionner.',
            'admin',
            $obj(['id' => $id], ['id']),
            fn($a) => PublicForm::rotate($a['id']),
            true,
        );
        $add(
            'forms_delete',
            'Supprimer un formulaire et son lien public ; les tickets déjà reçus sont conservés.',
            'admin',
            $obj(['id' => $id], ['id']),
            function ($a) {
                PublicForm::delete($a['id']);
                return ['deleted' => true];
            },
            true,
        );
        $add(
            'formatting_list',
            'Lister les règles de couleur du projet, distinctes des automatisations.',
            'read',
            $obj(['project_id' => $id], ['project_id']),
            fn($a) => [
                'rules' => DB::all(
                    'SELECT * FROM formatting_rules WHERE project_id=? ORDER BY id',
                    [$a['project_id']],
                ),
            ],
        );
        $add(
            'formatting_create',
            'Créer une règle de mise en forme pour état, type, priorité ou blocage.',
            'admin',
            $obj(
                [
                    'project_id' => $id,
                    'field' => $enum(['status', 'priority', 'type', 'flagged']),
                    'value' => $str(),
                    'title' => $str(),
                    'color' => $str(7),
                ],
                ['project_id', 'field', 'value', 'title', 'color'],
            ),
            fn($a) => ['id' => FormattingRule::save($a)],
        );
        $add(
            'formatting_delete',
            'Supprimer une règle de mise en forme.',
            'admin',
            $obj(['id' => $id], ['id']),
            function ($a) {
                FormattingRule::delete($a['id']);
                return ['deleted' => true];
            },
            true,
        );
        $conditions = $obj(array_intersect_key($ticketFields, array_flip(Automation::CONDITIONS)));
        $actions = $obj(
            array_intersect_key($ticketFields, array_flip(Automation::ACTIONS)) + [
                'comment' => $str(10000),
            ],
        );
        $actions['minProperties'] = 1;
        $add(
            'automation_list',
            'Lister les automatisations dans leur ordre d’exécution ; détails avec automation_get.',
            'read',
            $obj(['project_id' => $id] + $page, ['project_id']),
            function ($a) {
                Project::find($a['project_id']);
                return self::page(
                    'rules',
                    'id,name,trigger_event,enabled,version,created_at',
                    'automation_rules WHERE project_id=?',
                    [$a['project_id']],
                    $a,
                    'id',
                );
            },
        );
        $add(
            'automation_get',
            'Lire une automatisation et sa version.',
            'read',
            $obj(['id' => $id], ['id']),
            fn($a) => Automation::find($a['id']),
        );
        $add(
            'automation_save',
            'Créer ou modifier une automatisation. Conditions en ET ; actions appliquées une fois, sans boucle de règles. Pour modifier, fournir id et version.',
            'admin',
            $obj(
                [
                    'id' => $id,
                    'version' => $id,
                    'project_id' => $id,
                    'name' => $str(),
                    'event' => $enum(array_keys(Automation::EVENTS)),
                    'conditions' => $conditions,
                    'actions' => $actions,
                    'enabled' => $bool,
                ],
                ['project_id', 'name', 'event', 'actions'],
            ),
            fn($a) => Automation::save($a, (int) $user['id']),
        );
        $add(
            'automation_set_enabled',
            'Activer ou suspendre une automatisation avec contrôle de version.',
            'admin',
            $obj(['id' => $id, 'version' => $id, 'enabled' => $bool], ['id', 'version', 'enabled']),
            fn($a) => Automation::enabled($a['id'], $a['enabled'], $a['version']),
        );
        $add(
            'automation_delete',
            'Supprimer une automatisation ; son journal d’exécution est conservé.',
            'admin',
            $obj(['id' => $id, 'version' => $id], ['id', 'version']),
            function ($a) {
                Automation::delete($a['id'], $a['version']);
                return ['deleted' => true];
            },
            true,
        );
        $add(
            'automation_runs',
            'Consulter les exécutions et erreurs automatiques d’un projet.',
            'read',
            $obj(['project_id' => $id] + $page, ['project_id']),
            function ($a) {
                Project::find($a['project_id']);
                $limit = $a['limit'] ?? 50;
                $offset = $a['offset'] ?? 0;
                $join =
                    ' FROM automation_runs r JOIN tickets t ON t.id=r.ticket_id WHERE t.project_id=?';
                return [
                    'total' => (int) DB::one('SELECT COUNT(*) n' . $join, [$a['project_id']])['n'],
                    'runs' => DB::all(
                        'SELECT r.*' .
                            $join .
                            ' ORDER BY r.id DESC LIMIT ' .
                            $limit .
                            ' OFFSET ' .
                            $offset,
                        [$a['project_id']],
                    ),
                ];
            },
        );
        $add(
            'members_list',
            'Personnes actives et vérifiées pouvant être assignées.',
            'read',
            $obj($page),
            fn($a) => self::page(
                'members',
                'id,name,email,role',
                'users WHERE active=1 AND verified_at IS NOT NULL',
                [],
                $a,
                'name,id',
            ),
        );
        $add(
            'teams_list',
            'Lister les équipes et leur nombre de membres.',
            'read',
            $obj($page),
            fn($a) => self::page(
                'teams',
                't.*,(SELECT COUNT(*) FROM team_members m WHERE m.team_id=t.id) member_count',
                'teams t',
                [],
                $a,
                't.id',
            ),
        );
        $add(
            'teams_members_list',
            'Lister les membres d’une équipe.',
            'read',
            $obj(['id' => $id] + $page, ['id']),
            function ($a) {
                Team::find($a['id']);
                return self::page(
                    'members',
                    'u.id,u.name,u.email,u.active,u.role',
                    'team_members m JOIN users u ON u.id=m.user_id WHERE m.team_id=?',
                    [$a['id']],
                    $a,
                    'u.name,u.id',
                );
            },
        );
        $add(
            'teams_save',
            'Créer ou renommer une équipe.',
            'admin',
            $obj(['id' => $id, 'name' => $str()], ['name']),
            fn($a) => ['id' => Team::save($a['id'] ?? null, $a['name'])],
        );
        $add(
            'teams_membership',
            'Ajouter ou retirer un utilisateur d’une équipe.',
            'admin',
            $obj(
                ['team_id' => $id, 'user_id' => $id, 'add' => $bool],
                ['team_id', 'user_id', 'add'],
            ),
            function ($a) {
                Team::membership($a['team_id'], $a['user_id'], $a['add']);
                return ['ok' => true];
            },
        );
        $add(
            'teams_delete',
            'Supprimer une équipe ; les comptes et les tickets sont conservés.',
            'admin',
            $obj(['id' => $id], ['id']),
            function ($a) {
                Team::delete($a['id']);
                return ['deleted' => true];
            },
            true,
        );
        $add(
            'users_list',
            'Consulter les comptes et accès, sans mot de passe ni jeton.',
            'admin',
            $obj($page),
            fn($a) => self::page(
                'users',
                'id,name,email,role,active,verified_at',
                'users',
                [],
                $a,
                'id',
            ),
        );
        $add(
            'users_update',
            'Changer le rôle ou désactiver/réactiver un compte. Les sessions et clés existantes de ce compte deviennent invalides.',
            'admin',
            $obj(
                ['id' => $id, 'role' => $enum(array_keys(User::ROLES)), 'active' => $bool],
                ['id', 'role', 'active'],
            ),
            function ($a) use ($user) {
                User::manage($a['id'], $a['role'], $a['active'], (int) $user['id']);
                return ['ok' => true];
            },
            true,
        );
        $add(
            'reports_summary',
            'Statistiques du dashboard sur l’ensemble des tickets filtrés.',
            'read',
            $obj(['project_id' => $id, 'filters' => $filters]),
            function ($a) {
                $pid = $a['project_id'] ?? 0;
                if ($pid) {
                    Project::find($pid);
                }
                $f = $a['filters'] ?? [];
                return [
                    'total' => Query::count($pid, $f),
                    'statuses' => Query::aggregate($pid, $f, 'status'),
                    'priorities' => Query::aggregate($pid, $f, 'priority'),
                    'types' => Query::aggregate($pid, $f, 'type'),
                    'assignees' => Query::aggregate($pid, $f, 'assignee'),
                ];
            },
        );
        $add(
            'views_list',
            'Vues enregistrées du compte propriétaire de la clé pour un projet.',
            'read',
            $obj(['project_id' => $id], ['project_id']),
            fn($a) => [
                'views' => DB::all(
                    'SELECT id,name,settings FROM saved_views WHERE user_id=? AND project_id=? ORDER BY id',
                    [$user['id'], $a['project_id']],
                ),
            ],
        );
        $add(
            'views_save',
            'Enregistrer une vue personnelle avec ses filtres.',
            'write',
            $obj(
                ['project_id' => $id, 'name' => $str(), 'filters' => $filters],
                ['project_id', 'name', 'filters'],
            ),
            fn($a) => [
                'id' => \App\Models\SavedView::save(
                    (int) $user['id'],
                    $a['project_id'],
                    $a['name'],
                    $a['filters'],
                ),
            ],
        );
        $add(
            'views_delete',
            'Supprimer une vue appartenant au propriétaire de la clé.',
            'write',
            $obj(['id' => $id], ['id']),
            function ($a) use ($user) {
                \App\Models\SavedView::delete($a['id'], (int) $user['id']);
                return ['deleted' => true];
            },
            true,
        );
        $add(
            'tickets_attachments',
            'Métadonnées et liens web des pièces jointes ; le téléchargement nécessite la session web.',
            'read',
            $obj(['id' => $id] + $page, ['id']),
            function ($a) {
                Ticket::find($a['id']);
                $limit = $a['limit'] ?? 50;
                $offset = $a['offset'] ?? 0;
                $files = DB::all(
                    'SELECT id,original_name,mime,size_bytes,created_at FROM attachments WHERE ticket_id=? ORDER BY id LIMIT ' .
                        $limit .
                        ' OFFSET ' .
                        $offset,
                    [$a['id']],
                );
                foreach ($files as &$file) {
                    $file['web_url'] = url('/attachments/' . $file['id']);
                }
                unset($file);
                return [
                    'total' => (int) DB::one(
                        'SELECT COUNT(*) n FROM attachments WHERE ticket_id=?',
                        [$a['id']],
                    )['n'],
                    'attachments' => $files,
                ];
            },
        );
        $add(
            'development_list',
            'Connexions et événements de développement sans leurs jetons secrets.',
            'read',
            $obj(['project_id' => $id] + $page, ['project_id']),
            function ($a) {
                Project::find($a['project_id']);
                $limit = $a['limit'] ?? 50;
                $offset = $a['offset'] ?? 0;
                return [
                    'connections' => DB::all(
                        'SELECT id,provider,repository,last_sync_at FROM connections WHERE project_id=? ORDER BY id',
                        [$a['project_id']],
                    ),
                    'total' => (int) DB::one(
                        'SELECT COUNT(*) n FROM development_events d JOIN connections c ON c.id=d.connection_id WHERE c.project_id=?',
                        [$a['project_id']],
                    )['n'],
                    'events' => DB::all(
                        'SELECT d.* FROM development_events d JOIN connections c ON c.id=d.connection_id WHERE c.project_id=? ORDER BY d.id DESC LIMIT ' .
                            $limit .
                            ' OFFSET ' .
                            $offset,
                        [$a['project_id']],
                    ),
                ];
            },
        );
        return $tools;
    }
}
