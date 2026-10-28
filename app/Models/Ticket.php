<?php
declare(strict_types=1);
namespace App\Models;
use App\Core\{DB, Security};
final class Ticket
{
    public const STATUSES = [
        'todo' => 'À faire',
        'progress' => 'En cours',
        'review' => 'En revue',
        'done' => 'Terminé',
    ];
    public const TYPES = [
        'task' => 'Tâche',
        'story' => 'Story',
        'bug' => 'Bug',
        'subtask' => 'Sous-tâche',
        'epic' => 'Epic',
    ];
    public const PRIORITIES = [
        'highest' => 'Critique',
        'high' => 'Haute',
        'medium' => 'Moyenne',
        'low' => 'Basse',
        'lowest' => 'Très basse',
    ];
    public static function find(int $id): array
    {
        $t = DB::one(
            'SELECT t.*,p.project_key,p.name project_name,a.name assignee_name,u.name reporter_name,tm.name team_name FROM tickets t JOIN projects p ON p.id=t.project_id LEFT JOIN users a ON a.id=t.assignee_id LEFT JOIN users u ON u.id=t.reporter_id LEFT JOIN teams tm ON tm.id=t.team_id WHERE t.id=?',
            [$id],
        );
        if (!$t) {
            throw new \InvalidArgumentException('Ticket introuvable.');
        }
        $t['key'] = $t['project_key'] . '-' . $t['number'];
        $t['labels_array'] = json_decode($t['labels'], true);
        return $t;
    }
    public static function activity(int $project, ?int $ticket, ?int $user, string $message): void
    {
        DB::run('INSERT INTO activity(project_id,ticket_id,user_id,message) VALUES(?,?,?,?)', [
            $project,
            $ticket,
            $user,
            mb_substr($message, 0, 500),
        ]);
    }
    public static function validate(array $input, int $project, ?int $self = null): array
    {
        $title = trim((string) ($input['title'] ?? ''));
        $desc = trim((string) ($input['description'] ?? ''));
        if ($title === '' || mb_strlen($title) > 240) {
            throw new \InvalidArgumentException('Le résumé est requis (240 caractères maximum).');
        }
        if (strlen($desc) > 100000) {
            throw new \InvalidArgumentException('La description est trop longue.');
        }
        $type = (string) ($input['type'] ?? 'task');
        $status = (string) ($input['status'] ?? 'todo');
        $priority = (string) ($input['priority'] ?? 'medium');
        if (!isset(self::TYPES[$type], self::STATUSES[$status], self::PRIORITIES[$priority])) {
            throw new \InvalidArgumentException('Type, état ou priorité invalide.');
        }
        $assignee = filter_var($input['assignee_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
        if (
            $assignee &&
            !DB::one('SELECT id FROM users WHERE id=? AND active=1 AND verified_at IS NOT NULL', [
                $assignee,
            ])
        ) {
            throw new \InvalidArgumentException('Personne assignée introuvable.');
        }
        $rawTeam=$input['team_id']??null;
        $team=null;
        if ($rawTeam!==null && $rawTeam!=='') {
            if ((!is_int($rawTeam) && !is_string($rawTeam)) || !ctype_digit((string)$rawTeam) || (int)$rawTeam<1) throw new \InvalidArgumentException('Équipe invalide.');
            $team=(int)$rawTeam;
            if (!DB::one('SELECT team_id FROM team_projects WHERE team_id=? AND project_id=?',[$team,$project])) throw new \InvalidArgumentException('Cette équipe ne participe pas au projet.');
        }
        $parent = filter_var($input['parent_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
        if ($parent) {
            if ($self === $parent) {
                throw new \InvalidArgumentException(
                    'Un ticket ne peut pas être son propre parent.',
                );
            }
            $p = DB::one(
                'SELECT id,type,parent_id FROM tickets WHERE id=? AND project_id=? AND archived=0',
                [$parent, $project],
            );
            if (!$p) {
                throw new \InvalidArgumentException('Le parent doit appartenir au même projet.');
            }
            $seen = [];
            $cursor = $p;
            while ($cursor) {
                if ($self === (int) $cursor['id'] || isset($seen[$cursor['id']])) {
                    throw new \InvalidArgumentException('Cette hiérarchie créerait une boucle.');
                }
                $seen[$cursor['id']] = true;
                $cursor = $cursor['parent_id']
                    ? DB::one('SELECT id,parent_id FROM tickets WHERE id=?', [$cursor['parent_id']])
                    : null;
            }
        }
        if ($type === 'subtask' && !$parent) {
            throw new \InvalidArgumentException('Une sous-tâche doit avoir un parent.');
        }
        $start = Security::date($input['start_date'] ?? null);
        $due = Security::date($input['due_date'] ?? null);
        if ($start && $due && $start > $due) {
            throw new \InvalidArgumentException(
                'L’échéance doit être postérieure à la date de début.',
            );
        }
        $labels = is_array($input['labels'] ?? null)
            ? $input['labels']
            : explode(',', (string) ($input['labels'] ?? ''));
        $labels = array_values(array_unique(array_filter(array_map('trim', $labels))));
        if (
            count($labels) > 12 ||
            array_filter($labels, fn($l) => !is_string($l) || mb_strlen($l) > 40)
        ) {
            throw new \InvalidArgumentException('12 étiquettes maximum, de 40 caractères chacune.');
        }
        $hours = trim((string) ($input['estimated_hours'] ?? ''));
        $hours = str_replace(',', '.', $hours);
        if ($hours !== '' && !preg_match('/^\d{1,6}(?:\.\d{1,2})?$/D', $hours)) {
            throw new \InvalidArgumentException(
                'Le temps estimé doit être compris entre 0 et 999 999,99 heures, avec deux décimales maximum.',
            );
        }
        $progress = $input['progress_percent'] ?? 0;
        if ((!is_int($progress) && !is_string($progress)) || !preg_match('/^(?:100|[1-9]?[0-9])$/D', (string) $progress)) {
            throw new \InvalidArgumentException('L’avancement doit être un nombre entier entre 0 et 100 %.');
        }
        return [
            'title' => $title,
            'description' => $desc,
            'type' => $type,
            'status' => $status,
            'priority' => $priority,
            'assignee_id' => $assignee,
            'team_id' => $team,
            'parent_id' => $parent,
            'labels' => json_encode($labels, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'start_date' => $start,
            'due_date' => $due,
            'flagged' => !empty($input['flagged']) ? 1 : 0,
            'estimated_hours' => $hours === '' ? null : $hours,
            'progress_percent' => (int) $progress,
        ];
    }
    public static function create(
        int $project,
        array $input,
        ?int $reporter,
        ?string $external = null,
    ): int {
        return DB::transaction(function () use ($project, $input, $reporter, $external) {
            $p = DB::one('SELECT * FROM projects WHERE id=? FOR UPDATE', [$project]);
            if (!$p) {
                throw new \InvalidArgumentException('Projet introuvable.');
            }
            $data = self::validate($input,$project);
            DB::run('UPDATE projects SET next_number=next_number+1 WHERE id=?', [$project]);
            $id = DB::insert(
                'INSERT INTO tickets(project_id,number,title,description,type,status,priority,assignee_id,team_id,parent_id,labels,start_date,due_date,flagged,estimated_hours,progress_percent,reporter_id,external_email,rank_value,completed_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [
                    $project,
                    $p['next_number'],
                    ...array_values($data),
                    $reporter,
                    $external,
                    $p['next_number'] * 100,
                    $data['status'] === 'done' ? gmdate('Y-m-d H:i:s') : null,
                ],
            );
            self::activity(
                $project,
                $id,
                $reporter,
                $external ? 'Demande reçue via un formulaire externe' : 'a créé le ticket',
            );
            Automation::run('ticket.created', $id, $reporter);
            return $id;
        });
    }
    public static function update(
        int $id,
        array $input,
        int $actor,
        int $version,
        bool $automate = true,
    ): void {
        DB::transaction(function () use ($id, $input, $actor, $version, $automate) {
            $old = self::find($id);
            DB::one('SELECT id FROM projects WHERE id=? FOR UPDATE', [$old['project_id']]);
            $old = self::find($id);
            $data = self::validate(array_replace(['team_id'=>$old['team_id']],$input), (int) $old['project_id'], $id);
            $completed =
                $data['status'] === 'done' ? $old['completed_at'] ?? gmdate('Y-m-d H:i:s') : null;
            $set = implode(',', array_map(fn($k) => "$k=?", array_keys($data)));
            $s = DB::run(
                "UPDATE tickets SET $set,completed_at=?,updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=? AND version=?",
                [...array_values($data), $completed, $id, $version],
            );
            if ($s->rowCount() !== 1) {
                throw new \InvalidArgumentException(
                    'Ce ticket a changé depuis son ouverture. Rechargez-le avant de réessayer.',
                );
            }
            self::activity(
                (int) $old['project_id'],
                $id,
                $actor,
                'a mis à jour le ticket' .
                    ($old['status'] !== $data['status']
                        ? ' · ' . self::STATUSES[$data['status']]
                        : ''),
            );
            if ($automate) {
                Automation::run('ticket.updated', $id, $actor, $old['status'] !== $data['status']);
            }
        });
    }
    public static function patch(
        int $id,
        array $changes,
        int $actor,
        int $version,
        bool $automate = true,
    ): void {
        $old = self::find($id);
        $allowed = [
            'title',
            'description',
            'type',
            'status',
            'priority',
            'assignee_id',
            'team_id',
            'parent_id',
            'labels',
            'start_date',
            'due_date',
            'flagged',
            'estimated_hours',
            'progress_percent',
        ];
        if (!$changes || array_diff(array_keys($changes), $allowed)) {
            throw new \InvalidArgumentException('Champs de modification invalides.');
        }
        self::update(
            $id,
            array_replace($old, ['labels' => $old['labels_array']], $changes),
            $actor,
            $version,
            $automate,
        );
    }
    public static function archive(int $id, bool $archived, int $actor, int $version): void
    {
        DB::transaction(function () use ($id, $archived, $actor, $version) {
            $t = self::find($id);
            DB::one('SELECT id FROM projects WHERE id=? FOR UPDATE', [$t['project_id']]);
            if (
                !DB::run(
                    'UPDATE tickets SET archived=?,updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=? AND version=?',
                    [(int) $archived, $id, $version],
                )->rowCount()
            ) {
                throw new \InvalidArgumentException('Version du ticket obsolète.');
            }
            self::activity(
                (int) $t['project_id'],
                $id,
                $actor,
                $archived ? 'a archivé le ticket' : 'a restauré le ticket',
            );
        });
    }
    public static function comment(int $id, string $body, int $actor, string $format = 'text'): int
    {
        return DB::transaction(function () use ($id, $body, $actor, $format) {
            $t = Ticket::find((int) $id);
            $body = trim($body);
            if (!in_array($format, ['text', 'html'], true)) {
                throw new \InvalidArgumentException('Format de commentaire invalide.');
            }
            if ($format === 'html') {
                $body = \App\Services\Conversation::clean($body, $id);
            }
            if (!$body || ($format === 'text' && mb_strlen($body) > 10000)) {
                throw new \InvalidArgumentException(
                    'Le commentaire doit contenir entre 1 et 10 000 caractères.',
                );
            }
            $comment = DB::insert('INSERT INTO comments(ticket_id,user_id,body,body_format) VALUES(?,?,?,?)', [
                $id,
                $actor,
                $body,
                $format,
            ]);
            Ticket::activity(
                (int) $t['project_id'],
                (int) $id,
                (int) $actor,
                'a ajouté un commentaire',
            );
            return $comment;
        });
    }
    public static function link(int $id, int $targetId, string $relation, int $actor): void
    {
        DB::transaction(function () use ($id, $targetId, $relation, $actor) {
            $t = Ticket::find((int) $id);
            DB::one('SELECT id FROM projects WHERE id=? FOR UPDATE', [$t['project_id']]);
            $target = Ticket::find($targetId);
            if (
                $t['project_id'] !== $target['project_id'] ||
                $t['id'] === $target['id'] ||
                !in_array($relation, ['relates', 'blocks', 'duplicates'], true)
            ) {
                throw new \InvalidArgumentException('Relation invalide.');
            }
            if ($relation === 'blocks') {
                $pending = [$target['id']];
                $seen = [];
                while ($pending) {
                    $node = array_pop($pending);
                    if ((int) $node === (int) $id) {
                        throw new \InvalidArgumentException(
                            'Cette dépendance créerait une boucle.',
                        );
                    }
                    if (isset($seen[$node])) {
                        continue;
                    }
                    $seen[$node] = true;
                    foreach (
                        DB::all(
                            "SELECT target_id FROM ticket_links WHERE source_id=? AND relation='blocks'",
                            [$node],
                        )
                        as $l
                    ) {
                        $pending[] = $l['target_id'];
                    }
                }
            }
            DB::run('INSERT IGNORE INTO ticket_links(source_id,target_id,relation) VALUES(?,?,?)', [
                $id,
                $target['id'],
                $relation,
            ]);
            Ticket::activity(
                (int) $t['project_id'],
                (int) $id,
                (int) $actor,
                'a associé ' . $target['key'],
            );
        });
    }
    public static function unlink(int $id): int
    {
        $link = DB::one('SELECT * FROM ticket_links WHERE id=?', [$id]);
        if (!$link) {
            throw new \InvalidArgumentException('Relation introuvable.');
        }
        DB::run('DELETE FROM ticket_links WHERE id=?', [$id]);
        return (int) $link['source_id'];
    }
}
