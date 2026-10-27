<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\{Auth, DB, View};
use App\Models\{Ticket, Project};
final class TicketController
{
    public function show(string $id): void
    {
        $user = Auth::requireUser();
        $ticket = Ticket::find((int) $id);
        $project = Project::find((int) $ticket['project_id']);
        $projects = Project::all();
        $members = Project::members();
        $parents = DB::all(
            'SELECT id,number,title,type FROM tickets WHERE project_id=? AND id<>? AND archived=0 ORDER BY number DESC LIMIT 500',
            [$project['id'], $ticket['id']],
        );
        $comments = DB::all(
            'SELECT c.*,u.name FROM comments c JOIN users u ON u.id=c.user_id WHERE ticket_id=? ORDER BY c.id',
            [$id],
        );
        $attachments = DB::all(
            'SELECT id,original_name,size_bytes,mime FROM attachments WHERE ticket_id=? ORDER BY id',
            [$id],
        );
        $links = DB::all(
            'SELECT l.*,t.number,t.title,p.project_key FROM ticket_links l JOIN tickets t ON t.id=l.target_id JOIN projects p ON p.id=t.project_id WHERE l.source_id=?',
            [$id],
        );
        $children = DB::all(
            'SELECT id,number,title,status,type FROM tickets WHERE parent_id=? AND archived=0 ORDER BY rank_value',
            [$id],
        );
        $events = DB::all(
            'SELECT * FROM development_events WHERE ticket_id=? ORDER BY occurred_at DESC LIMIT 50',
            [$id],
        );
        $title = $ticket['key'];
        $section = 'ticket';
        View::render(
            'ticket',
            compact(
                'ticket',
                'project',
                'projects',
                'members',
                'parents',
                'comments',
                'attachments',
                'links',
                'children',
                'events',
                'user',
                'title',
                'section',
            ),
        );
    }
    public function create(): void
    {
        $u = Auth::editor();
        $id = Ticket::create((int) ($_POST['project_id'] ?? 0), $_POST, (int) $u['id']);
        flash('Le ticket est créé.');
        if (!empty($_POST['another'])) {
            redirect('/list?project=' . (int) $_POST['project_id'] . '&create=1');
        }
        redirect('/tickets/' . $id);
    }
    public function update(string $id): void
    {
        $u = Auth::editor();
        Ticket::update((int) $id, $_POST, (int) $u['id'], (int) ($_POST['version'] ?? 0));
        if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
            json_response(['ok' => true, 'ticket' => Ticket::find((int) $id)]);
        }
        flash('Modifications enregistrées.');
        redirect('/tickets/' . (int) $id);
    }
    public function quick(string $id): void
    {
        $u = Auth::editor();
        $t = Ticket::find((int) $id);
        $field = (string) ($_POST['field'] ?? '');
        if (
            !in_array(
                $field,
                [
                    'status',
                    'title',
                    'priority',
                    'assignee_id',
                    'due_date',
                    'start_date',
                    'flagged',
                    'estimated_hours',
                    'progress_percent',
                ],
                true,
            )
        ) {
            throw new \InvalidArgumentException('Champ non modifiable.');
        }
        $data = array_replace(
            $t,
            ['labels' => $t['labels_array']],
            [$field => $_POST['value'] ?? ''],
        );
        Ticket::update((int) $id, $data, (int) $u['id'], (int) ($_POST['version'] ?? 0));
        json_response(['ok' => true, 'ticket' => Ticket::find((int) $id)]);
    }
    public function schedule(string $id): void
    {
        $u = Auth::editor();
        $t = Ticket::find((int) $id);
        $data = array_replace($t, [
            'labels' => $t['labels_array'],
            'start_date' => $_POST['start_date'] ?? null,
            'due_date' => $_POST['due_date'] ?? null,
        ]);
        Ticket::update((int) $id, $data, (int) $u['id'], (int) ($_POST['version'] ?? 0));
        json_response(['ok' => true, 'ticket' => Ticket::find((int) $id)]);
    }
    public function move(string $id): void
    {
        $u = Auth::editor();
        DB::transaction(function () use ($id, $u) {
            $t = Ticket::find((int) $id);
            DB::one('SELECT id FROM projects WHERE id=? FOR UPDATE', [$t['project_id']]);
            $status = (string) ($_POST['status'] ?? '');
            if (!isset(Ticket::STATUSES[$status])) {
                throw new \InvalidArgumentException('État invalide.');
            }
            $before = (int) ($_POST['before_id'] ?? 0);
            $rank = 0;
            if ($before && $before !== (int) $id) {
                $next = Ticket::find($before);
                if ($next['project_id'] !== $t['project_id'] || $next['status'] !== $status) {
                    throw new \InvalidArgumentException('Position de carte invalide.');
                }
                $previous = DB::one(
                    'SELECT MAX(rank_value) r FROM tickets WHERE project_id=? AND status=? AND rank_value<? AND id<>? AND archived=0',
                    [$t['project_id'], $status, $next['rank_value'], $id],
                );
                $rank =
                    ((float) ($previous['r'] ?? (float) $next['rank_value'] - 200) +
                        (float) $next['rank_value']) /
                    2;
            } else {
                $last = DB::one(
                    'SELECT MAX(rank_value) r FROM tickets WHERE project_id=? AND status=? AND archived=0',
                    [$t['project_id'], $status],
                );
                $rank = (float) ($last['r'] ?? 0) + 100;
            }
            Ticket::update(
                (int) $id,
                array_replace($t, ['labels' => $t['labels_array'], 'status' => $status]),
                (int) $u['id'],
                (int) ($_POST['version'] ?? 0),
            );
            DB::run('UPDATE tickets SET rank_value=? WHERE id=?', [$rank, $id]);
        });
        json_response(['ok' => true, 'ticket' => Ticket::find((int) $id)]);
    }
    public function archive(string $id): void
    {
        $u = Auth::editor();
        $t = Ticket::find((int) $id);
        $restore = !empty($_POST['restore']);
        Ticket::archive((int) $id, !$restore, (int) $u['id'], (int) $t['version']);
        flash(
            $restore
                ? 'Ticket restauré.'
                : 'Ticket archivé. Vous pouvez le restaurer depuis sa fiche.',
        );
        redirect('/tickets/' . (int) $id);
    }
    public function bulk(): void
    {
        $u = Auth::editor();
        $ids = array_unique(array_map('intval', (array) ($_POST['ids'] ?? [])));
        if (!$ids || count($ids) > 200) {
            throw new \InvalidArgumentException('Sélectionnez entre 1 et 200 tickets.');
        }
        $field = (string) ($_POST['field'] ?? '');
        if (!in_array($field, ['status', 'priority', 'assignee_id', 'flagged'], true)) {
            throw new \InvalidArgumentException('Champ non modifiable en groupe.');
        }
        DB::transaction(function () use ($ids, $field, $u) {
            foreach ($ids as $id) {
                $t = Ticket::find($id);
                $data = array_replace(
                    $t,
                    ['labels' => $t['labels_array']],
                    [$field => $_POST['value'] ?? ''],
                );
                Ticket::update($id, $data, (int) $u['id'], (int) $t['version']);
            }
        });
        flash(count($ids) . ' tickets modifiés.');
        redirect('/list');
    }
    public function comment(string $id): void
    {
        $u = Auth::editor();
        Ticket::comment((int) $id, (string) ($_POST['body'] ?? ''), (int) $u['id'], (string) ($_POST['body_format'] ?? 'text'));
        redirect('/tickets/' . (int) $id . '#activity');
    }
    public function link(string $id): void
    {
        $u = Auth::editor();
        Ticket::link(
            (int) $id,
            (int) ($_POST['target_id'] ?? 0),
            (string) ($_POST['relation'] ?? 'relates'),
            (int) $u['id'],
        );
        redirect('/tickets/' . (int) $id);
    }
    public function unlink(string $id): void
    {
        Auth::editor();
        $ticket = Ticket::unlink((int) $id);
        redirect('/tickets/' . $ticket);
    }
}
