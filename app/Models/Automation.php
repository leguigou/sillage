<?php
declare(strict_types=1);
namespace App\Models;
use App\Core\DB;

final class Automation
{
    public const EVENTS = [
        'ticket.created' => 'Ticket créé',
        'ticket.updated' => 'Ticket modifié',
        'ticket.status_changed' => 'État modifié',
    ];
    public const CONDITIONS = ['type', 'status', 'priority', 'assignee_id', 'flagged'];
    public const ACTIONS = [
        'status',
        'priority',
        'assignee_id',
        'flagged',
        'labels',
        'estimated_hours',
        'comment',
    ];

    public static function find(int $id): array
    {
        $r = DB::one('SELECT * FROM automation_rules WHERE id=?', [$id]);
        if (!$r) {
            throw new \InvalidArgumentException('Automatisation introuvable.');
        }
        return self::hydrate($r);
    }
    private static function hydrate(array $r): array
    {
        $r['conditions'] = json_decode($r['conditions_json'], true, 32, JSON_THROW_ON_ERROR);
        $r['actions'] = json_decode($r['actions_json'], true, 32, JSON_THROW_ON_ERROR);
        unset($r['conditions_json'], $r['actions_json']);
        return $r;
    }
    public static function save(array $input, int $actor): array
    {
        return DB::transaction(function () use ($input, $actor) {
            $project = (int) ($input['project_id'] ?? 0);
            Project::find($project);
            DB::one('SELECT id FROM projects WHERE id=? FOR UPDATE', [$project]);
            $id = (int) ($input['id'] ?? 0);
            $old = $id ? self::find($id) : null;
            if ($old && (int) $old['project_id'] !== $project) {
                throw new \InvalidArgumentException('Projet de la règle immuable.');
            }
            if (
                !$id &&
                (int) DB::one('SELECT COUNT(*) n FROM automation_rules WHERE project_id=?', [
                    $project,
                ])['n'] >= 200
            ) {
                throw new \InvalidArgumentException(
                    '200 règles maximum par projet pour borner le travail automatique.',
                );
            }
            $name = trim((string) ($input['name'] ?? ''));
            $event = (string) ($input['event'] ?? '');
            $conditions = $input['conditions'] ?? [];
            $actions = $input['actions'] ?? [];
            if (
                !$name ||
                mb_strlen($name) > 120 ||
                !isset(self::EVENTS[$event]) ||
                !is_array($conditions) ||
                !is_array($actions) ||
                !$actions ||
                array_diff(array_keys($conditions), self::CONDITIONS) ||
                array_diff(array_keys($actions), self::ACTIONS)
            ) {
                throw new \InvalidArgumentException(
                    'Nom, événement, conditions ou actions invalides.',
                );
            }
            // Reuse the ticket owner's interpretation of enums, assignees, hours and labels.
            if (isset($conditions['type']) && !isset(Ticket::TYPES[$conditions['type']])) {
                throw new \InvalidArgumentException('Type de condition invalide.');
            }
            foreach (
                [
                    'conditions' => array_diff_key($conditions, ['type' => true]),
                    'actions' => array_diff_key($actions, ['comment' => true]),
                ]
                as $kind => $fields
            ) {
                $validated = Ticket::validate(
                    ['title' => 'Validation de règle'] + $fields,
                    $project,
                );
                foreach ($fields as $field => $value) {
                    if (is_array($value) && $field !== 'labels') {
                        throw new \InvalidArgumentException('Valeur de règle invalide.');
                    }
                    if (
                        $field === 'assignee_id' &&
                        $value !== null &&
                        (!is_numeric($value) || (int) $value < 1)
                    ) {
                        throw new \InvalidArgumentException('Personne assignée invalide.');
                    }
                    if ($field === 'flagged' && !in_array($value, [true, false, 0, 1], true)) {
                        throw new \InvalidArgumentException('Blocage invalide.');
                    }
                    $normalized =
                        $field === 'labels'
                            ? json_decode($validated[$field], true, 32, JSON_THROW_ON_ERROR)
                            : $validated[$field];
                    if ($kind === 'conditions') {
                        $conditions[$field] = $normalized;
                    } else {
                        $actions[$field] = $normalized;
                    }
                }
            }
            if (
                isset($actions['comment']) &&
                (!is_string($actions['comment']) ||
                    !trim($actions['comment']) ||
                    mb_strlen($actions['comment']) > 10000)
            ) {
                throw new \InvalidArgumentException(
                    'Commentaire automatique de 1 à 10 000 caractères requis.',
                );
            }
            $args = [
                $name,
                $event,
                json_encode((object) $conditions, JSON_THROW_ON_ERROR),
                json_encode($actions, JSON_THROW_ON_ERROR),
                (int) ($input['enabled'] ?? true),
            ];
            if ($id) {
                $s = DB::run(
                    'UPDATE automation_rules SET name=?,trigger_event=?,conditions_json=?,actions_json=?,enabled=?,version=version+1 WHERE id=? AND version=?',
                    [...$args, $id, (int) ($input['version'] ?? 0)],
                );
                if (!$s->rowCount()) {
                    throw new \InvalidArgumentException(
                        'Cette règle a changé. Rechargez sa version avant de la modifier.',
                    );
                }
            } else {
                $id = DB::insert(
                    'INSERT INTO automation_rules(name,trigger_event,conditions_json,actions_json,enabled,project_id,created_by) VALUES(?,?,?,?,?,?,?)',
                    [...$args, $project, $actor],
                );
            }
            return self::find($id);
        });
    }
    public static function enabled(int $id, bool $enabled, int $version): array
    {
        $r = self::find($id);
        return self::save(
            [
                'id' => $id,
                'project_id' => $r['project_id'],
                'name' => $r['name'],
                'event' => $r['trigger_event'],
                'conditions' => $r['conditions'],
                'actions' => $r['actions'],
                'enabled' => $enabled,
                'version' => $version,
            ],
            (int) $r['created_by'],
        );
    }
    public static function delete(int $id, int $version): void
    {
        DB::transaction(function () use ($id, $version) {
            $rule = self::find($id);
            DB::one('SELECT id FROM projects WHERE id=? FOR UPDATE', [$rule['project_id']]);
            if (
                !DB::run('DELETE FROM automation_rules WHERE id=? AND version=?', [
                    $id,
                    $version,
                ])->rowCount()
            ) {
                throw new \InvalidArgumentException('Règle absente ou version obsolète.');
            }
        });
    }
    public static function run(
        string $event,
        int $ticket,
        ?int $actor,
        bool $statusChanged = false,
    ): void {
        $current = Ticket::find($ticket);
        $events = [$event];
        if ($event === 'ticket.updated' && $statusChanged) {
            $events[] = 'ticket.status_changed';
        }
        $marks = implode(',', array_fill(0, count($events), '?'));
        $rules = DB::all(
            'SELECT * FROM automation_rules WHERE project_id=? AND enabled=1 AND trigger_event IN (' .
                $marks .
                ') ORDER BY id LIMIT 201',
            [$current['project_id'], ...$events],
        );
        if (count($rules) > 200) {
            throw new \RuntimeException('Trop de règles automatiques pour ce projet.');
        }
        foreach ($rules as $row) {
            $r = self::hydrate($row);
            $matches = true;
            foreach ($r['conditions'] as $field => $value) {
                if (
                    !in_array($field, self::CONDITIONS, true) ||
                    ($current[$field] === null
                        ? $value !== null
                        : (string) $current[$field] !== (string) $value)
                ) {
                    $matches = false;
                    break;
                }
            }
            if (!$matches) {
                continue;
            }
            DB::run('SAVEPOINT sillage_automation');
            try {
                $changes = array_diff_key($r['actions'], ['comment' => true]);
                if ($changes) {
                    Ticket::patch(
                        $ticket,
                        $changes,
                        $actor ?? (int) $r['created_by'],
                        (int) $current['version'],
                        false,
                    );
                }
                if (isset($r['actions']['comment'])) {
                    Ticket::comment(
                        $ticket,
                        $r['actions']['comment'],
                        $actor ?? (int) $r['created_by'],
                    );
                }
                $success = true;
                $message = 'Actions appliquées sans redéclencher les règles.';
            } catch (\InvalidArgumentException $e) {
                DB::run('ROLLBACK TO SAVEPOINT sillage_automation');
                $success = false;
                $message = mb_substr($e->getMessage(), 0, 500);
            }
            DB::run('RELEASE SAVEPOINT sillage_automation');
            if ($success && $changes) {
                $current = Ticket::find($ticket);
            }
            DB::run(
                'INSERT INTO automation_runs(rule_id,ticket_id,rule_name,event_name,succeeded,message) VALUES(?,?,?,?,?,?)',
                [$r['id'], $ticket, $r['name'], $event, (int) $success, $message],
            );
        }
    }
}
