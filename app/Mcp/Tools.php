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
                    'securitySchemes'=>[['type'=>'oauth2','scopes'=>[$scope]]],
                    '_meta'=>['securitySchemes'=>[['type'=>'oauth2','scopes'=>[$scope]]]],
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
            'type' => $str(20)+['description'=>'Clé du type dans project_workflow_get.'],
            'status' => $str(20)+['description'=>'Clé du statut dans project_workflow_get ; transitions vérifiées côté serveur.'],
            'priority' => $enum(array_keys(Ticket::PRIORITIES)),
            'assignee_id' => $nullableId,
            'team_id' => $nullableId,
            'parent_id' => $nullableId,
            'labels' => $labels,
            'start_date' => ['type' => ['string', 'null'], 'maxLength' => 10],
            'due_date' => ['type' => ['string', 'null'], 'maxLength' => 10],
            'flagged' => $bool,
            'progress_percent' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
            'release_id' => $nullableId,
            'resolution' => ['type'=>['string','null'],'enum'=>[null,...array_keys(\App\Models\TicketClosure::RESOLUTIONS)]],
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
                'statuses' => \App\Models\Workflow::labels('status'),
                'types' => \App\Models\Workflow::labels('type'),
                'priorities' => Ticket::PRIORITIES,
                'resolutions'=>\App\Models\TicketClosure::RESOLUTIONS,
                'validations'=>\App\Models\TicketClosure::VALIDATIONS,
                'remaining_hours_formula'=>'estimated_hours * (100 - progress_percent) / 100 ; null if no estimate, independent of spent_hours',
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
        $add('project_workflow_get','Lire les statuts, types, catégories et transitions d’un projet.','read',$obj(['project_id'=>$id],['project_id']),function($a){Project::find($a['project_id']);return \App\Models\Workflow::get($a['project_id']);});
        $add('project_workflow_save','Configurer le circuit d’un projet avec sa version courante. add_item crée une clé immuable ; save_item modifie un élément. Pour les transitions, fournir toutes les arêtes autorisées.','admin',
            $obj(['project_id'=>$id,'version'=>['type'=>'integer','minimum'=>0],'action'=>$enum(['add_item','save_item','transitions']),'kind'=>$enum(['status','type']),'code'=>$str(20),'label'=>$str(80),'category'=>$str(20),'icon'=>$str(32),'color'=>$str(7),'position'=>['type'=>'integer','minimum'=>0,'maximum'=>999],'enabled'=>$bool,'restricted'=>$bool,'edges'=>['type'=>'array','maxItems'=>900,'items'=>$obj(['from'=>$str(20),'to'=>$str(20)],['from','to'])]],['project_id','version','action']),
            function($a){if(isset($a['edges'])){$a['transitions']=[];foreach($a['edges'] as $edge)$a['transitions'][$edge['from']][]=$edge['to'];}return \App\Models\Workflow::save($a['project_id'],$a);});
        $add('tickets_follow','Suivre ou ne plus suivre un ticket avec le compte connecté.','write',$obj(['id'=>$id,'follow'=>$bool],['id','follow']),fn($a)=>\App\Models\TicketFollow::set($a['id'],(int)$user['id'],$a['follow']));
        $add('notifications_list','Mes notifications personnelles et leur état de lecture, tous projets.','read',$obj(['unread_only'=>$bool,'page'=>['type'=>'integer','minimum'=>1]],[]),fn($a)=>\App\Models\TicketNotification::page((int)$user['id'],$a['unread_only']??false,$a['page']??1));
        $add('notifications_mark','Marquer une de mes notifications comme lue ou non lue.','write',$obj(['id'=>$id,'read'=>$bool],['id','read']),fn($a)=>\App\Models\TicketNotification::mark($a['id'],(int)$user['id'],$a['read']));
        $projectProperties = [
            'name' => $str(),
            'key' => $str(10),
            'description' => $str(2000),
            'color' => $str(7),
            'repository_connection_id' => $nullableId,
            'team_ids' => ['type'=>'array','items'=>$id,'uniqueItems'=>true],
            'require_ticket_validation'=>$bool,
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
            'Modifier le nom, la description, la couleur ou le dépôt Git principal d’un projet. Une connexion existante peut être partagée.',
            'admin',
            $obj(
                ['id' => $id, 'name' => $str(), 'description' => $str(2000), 'color' => $str(7), 'repository_connection_id'=>$nullableId,'team_ids'=>['type'=>'array','items'=>$id,'uniqueItems'=>true],'require_ticket_validation'=>$bool],
                ['id'],
            ),
            fn($a) => Project::find(Project::save($a, $a['id'])),
        );
        $add(
            'project_notifications_get',
            'Lire les événements et destinataires e-mail configurés pour un projet.',
            'read',
            $obj(['project_id'=>$id],['project_id']),
            function($a) { Project::find($a['project_id']); return \App\Models\ProjectEmail::get($a['project_id']); },
        );
        $notificationRules=[];
        foreach(\App\Models\ProjectEmail::EVENTS as $event=>$label) $notificationRules[$event]=['type'=>'array','items'=>$enum(array_keys(\App\Models\ProjectEmail::RECIPIENTS)),'uniqueItems'=>true];
        $add(
            'project_notifications_save',
            'Configurer les événements et destinataires e-mail d’un projet ; version 0 pour la première configuration. Auteur exclu par défaut, préférences personnelles respectées.',
            'admin',
            $obj(['project_id'=>$id,'version'=>['type'=>'integer','minimum'=>0],'enabled'=>$bool,'notify_actor'=>$bool,'rules'=>$obj($notificationRules),'recipients'=>['type'=>'array','items'=>$id,'uniqueItems'=>true,'maxItems'=>200]],['project_id','version','enabled','rules']),
            function($a) { \App\Models\ProjectEmail::save($a['project_id'],$a); return \App\Models\ProjectEmail::get($a['project_id']); },
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
            'release'=>['type'=>['integer','string'],'description'=>'ID de version ou none pour les tickets sans version.'],
            'resolution'=>$enum(array_keys(\App\Models\TicketClosure::RESOLUTIONS)),
            'validation'=>$enum(array_keys(\App\Models\TicketClosure::VALIDATIONS)),
            'q' => $str(240),
            'jql' => $str(4000),
            'status' => $str(20),
            'priority' => $enum(array_keys(Ticket::PRIORITIES)),
            'type' => $str(20),
            'assignee' => ['type' => ['string', 'integer']],
            'team' => ['type'=>['string','integer']],
            'hide_done' => $bool,
            'pause' => $enum(['active','only','all']),
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
                'team',
                'type',
                'estimated_hours',
            ]),
            'direction' => $enum(['asc', 'desc']),
        ]);
        $add(
            'tickets_list',
            'Rechercher les tickets par projet ou dans toute l’installation. Les pauses sont masquées par défaut ; utiliser filters.pause=only ou all pour les retrouver. Les descriptions se lisent avec tickets_get.',
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
            'Lire un ticket, sa version, sa résolution et ses heures estimées, passées et restantes (calcul sur l’avancement).',
            'read',
            $obj(['id' => $id], ['id']),
            fn($a) => Ticket::find($a['id']),
        );
        $add('tickets_history','Lire les modifications détaillées avec valeurs avant/après, auteur et version. L’historique commence à l’activation de cette fonctionnalité.','read',$obj(['id'=>$id]+$page,['id']),fn($a)=>\App\Models\TicketHistory::page($a['id'],$a['offset']??0,$a['limit']??20));
        $add('tickets_time_list','Lire les saisies de temps et les totaux du ticket. Le reste à faire dépend uniquement de l’estimation et du pourcentage d’avancement.','read',$obj(['id'=>$id]+$page,['id']),fn($a)=>['ticket'=>Ticket::find($a['id'])]+\App\Models\TicketTime::page($a['id'],$a['offset']??0,$a['limit']??50));
        $timeFields=['hours'=>['type'=>['string','number'],'maxLength'=>5,'minimum'=>0.01,'maximum'=>24],'worked_on'=>$str(10),'note'=>$str(2000)];
        $add('tickets_time_save','Ajouter ou modifier sa propre saisie de temps ; un administrateur peut corriger celles des collaborateurs. Version actuelle du ticket obligatoire.','write',$obj(['id'=>$id,'version'=>$id,'entry_id'=>$id]+$timeFields,['id','version','hours','worked_on']),fn($a)=>\App\Models\TicketTime::save($a['id'],$a,(int)$user['id'],$a['version'],$a['entry_id']??null));
        $add('tickets_time_delete','Supprimer une saisie de temps. Sa trace et ses anciennes valeurs restent dans l’historique.','write',$obj(['id'=>$id,'version'=>$id,'entry_id'=>$id],['id','version','entry_id']),fn($a)=>\App\Models\TicketTime::save($a['id'],[],(int)$user['id'],$a['version'],$a['entry_id'],true),true);
        $add('tickets_validate','Valider le contenu actuel ou demander une reprise motivée. Une modification ultérieure annule la validation. La validation ne clôture pas le ticket.','write',$obj(['id'=>$id,'version'=>$id,'approved'=>$bool,'note'=>$str(2000)],['id','version','approved']),fn($a)=>\App\Models\TicketClosure::review($a['id'],$a['approved'],$a['note']??'',(int)$user['id'],$a['version']));
        $add('releases_list','Lister les versions cibles du projet et leur dernier résultat de livraison par environnement.','read',$obj(['project_id'=>$id,'archived'=>$bool]+$page,['project_id']),fn($a)=>\App\Models\Release::page($a['project_id'],$a['offset']??0,$a['limit']??50,$a['archived']??false));
        $add('releases_save','Créer une version cible, ou modifier/archiver une version existante avec sa version courante. Les tickets sont associés par tickets_update.release_id.','write',$obj(['project_id'=>$id,'id'=>$id,'version'=>$id,'name'=>$str(120),'description'=>$str(10000),'target_date'=>['type'=>['string','null'],'maxLength'=>10],'archived'=>$bool],['project_id']),fn($a)=>\App\Models\Release::save($a['project_id'],$a,(int)$user['id'],$a['id']??null));
        $add('release_deliveries_list','Lire l’historique immuable des livraisons déclarées pour une version.','read',$obj(['id'=>$id]+$page,['id']),fn($a)=>\App\Models\Release::deliveries($a['id'],$a['offset']??0,$a['limit']??30));
        $add('releases_deliver','Déclarer explicitement une livraison réalisée en préproduction ou production, avec son résultat et sa référence Git/build. Ne lance aucun déploiement et ne transforme pas une PR fusionnée en livraison.','write',$obj(['id'=>$id,'version'=>$id,'environment'=>$enum(array_keys(\App\Models\Release::ENVIRONMENTS)),'outcome'=>$enum(array_keys(\App\Models\Release::OUTCOMES)),'reference'=>$str(240),'note'=>$str(2000)],['id','version','environment','outcome','reference']),fn($a)=>\App\Models\Release::deliver($a['id'],$a,(int)$user['id'],$a['version']));
        $add(
            'tickets_create',
            'Créer un ticket. Les automatisations du projet s’appliquent avant le résultat.',
            'write',
            $obj(
                ['project_id' => $id, 'fields' => $obj($ticketFields, ['title'])],
                ['project_id', 'fields'],
            ),
            fn($a) => Ticket::find(
                Ticket::create($a['project_id'], $a['fields'], (int) $user['id'],null,'mcp'),
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
                Ticket::patch($a['id'], $a['fields'], (int) $user['id'], $a['version'],true,'mcp');
                return Ticket::find($a['id']);
            },
        );
        $add(
            'tickets_pause',
            'Mettre en pause un ticket (masqué des vues actives) ou reprendre son état précédent, sans modifier les autres champs.',
            'write',
            $obj(['id'=>$id,'version'=>$id,'paused'=>$bool],['id','version','paused']),
            function($a) use($user) {
                Ticket::setPaused($a['id'],$a['paused'],(int)$user['id'],$a['version']);
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
            $obj(['id' => $id, 'body' => $str(100000), 'body_format' => $enum(['text','html'])], ['id', 'body']),
            fn($a) => ['id' => Ticket::comment($a['id'], $a['body'], (int) $user['id'], $a['body_format'] ?? 'text')],
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
                        'SELECT c.id,c.body,c.body_format,c.created_at,u.name FROM comments c JOIN users u ON u.id=c.user_id WHERE ticket_id=? ORDER BY c.id LIMIT ' .
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
                    'ticket_type' => $str(20),
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
        $conditions = $obj(array_intersect_key($ticketFields, array_flip(Automation::CONDITIONS)) + ['development_repository'=>$str(200)]);
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
            'Lister les équipes, leurs nombres de membres, projets et tickets actifs ; filtrer par projet.',
            'read',
            $obj(['project_id'=>$id]+$page),
            fn($a) => self::page(
                'teams',
                't.*,(SELECT COUNT(*) FROM team_members m WHERE m.team_id=t.id) member_count,(SELECT COUNT(*) FROM team_projects tp WHERE tp.team_id=t.id) project_count,(SELECT COUNT(*) FROM tickets k WHERE k.team_id=t.id AND k.archived=0) ticket_count',
                'teams t'.(isset($a['project_id'])?' WHERE EXISTS(SELECT 1 FROM team_projects tp WHERE tp.team_id=t.id AND tp.project_id=?)':''),
                isset($a['project_id'])?[$a['project_id']]:[],
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
            'teams_projects_list',
            'Lister les projets auxquels une équipe participe.',
            'read',
            $obj(['id'=>$id]+$page,['id']),
            function($a) {
                Team::find($a['id']);
                return self::page('projects','p.*','team_projects tp JOIN projects p ON p.id=tp.project_id WHERE tp.team_id=?',[$a['id']],$a,'p.id');
            },
        );
        $add(
            'teams_project_membership',
            'Associer ou retirer une équipe d’un projet. Le retrait exige de réaffecter ses tickets, y compris archivés.',
            'admin',
            $obj(['team_id'=>$id,'project_id'=>$id,'add'=>$bool],['team_id','project_id','add']),
            function($a) { Team::projectMembership($a['team_id'],$a['project_id'],$a['add']); return ['ok'=>true]; },
        );
        $add(
            'teams_delete',
            'Supprimer une équipe et libérer ses affectations ; les comptes et les tickets sont conservés.',
            'admin',
            $obj(['id' => $id], ['id']),
            function ($a) use($user) {
                Team::delete($a['id'],(int)$user['id']);
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
                    'teams' => Query::teamWorkload($pid,$f),
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
                        'SELECT c.id,c.provider,c.repository,c.last_sync_at FROM connections c JOIN project_connections pc ON pc.connection_id=c.id WHERE pc.project_id=? ORDER BY c.id',
                        [$a['project_id']],
                    ),
                    'total' => (int) DB::one(
                        'SELECT COUNT(*) n FROM development_events d JOIN project_connections pc ON pc.connection_id=d.connection_id WHERE pc.project_id=?',
                        [$a['project_id']],
                    )['n'],
                    'events' => \App\Models\DevelopmentEvent::withTickets(DB::all(
                        'SELECT d.* FROM development_events d JOIN project_connections pc ON pc.connection_id=d.connection_id WHERE pc.project_id=? ORDER BY d.id DESC LIMIT ' .
                            $limit .
                            ' OFFSET ' .
                            $offset,
                        [$a['project_id']],
                    ),$a['project_id']),
                ];
            },
        );
        return $tools;
    }
}
