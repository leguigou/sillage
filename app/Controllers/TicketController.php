<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\{Auth, DB, View};
use App\Models\{Ticket, Project};
/**
 * Ticket page and ticket write actions.
 *
 * - GET /tickets/{id}: full ticket page (any signed-in user), with history,
 *   time entries, comments, attachments, links, sub-tickets and Git data.
 * - POST /tickets (create), /tickets/{id} (full edit form), /tickets/{id}/quick
 *   (inline edit of one field), /schedule (dates, e.g. from the timeline),
 *   /move (board drag and drop), /pause, /archive, /comments, /links,
 *   /links/{id}/delete, and POST /bulk (same change on up to 200 tickets).
 * All write actions require an editor (read-only accounts are refused).
 * Business rules, validation and optimistic locking (posted "version") are in
 * App\Models\Ticket; most partial edits rebuild the full ticket data and go
 * through Ticket::update().
 */
final class TicketController
{
    public function show(string $id): void
    {
        $user = Auth::requireUser();
        $ticket = Ticket::find((int) $id);
        $history=\App\Models\TicketHistory::page((int)$id,max(0,(int)($_GET['history_offset']??0)),30,true);
        $time=\App\Models\TicketTime::page((int)$id,max(0,(int)($_GET['time_offset']??0)),50);
        $targetRelease=$ticket['release_id']?\App\Models\Release::find((int)$ticket['release_id']):null;
        $project = Project::find((int) $ticket['project_id']);
        $parent = $ticket['parent_id'] ? DB::one('SELECT t.id,t.number,t.title,p.project_key
            FROM tickets t
            JOIN projects p ON p.id=t.project_id
            WHERE t.id=?',[$ticket['parent_id']]) : null;
        $projects = Project::all();
        $members = Project::members();
        $projectTeams=\App\Models\Team::forProject((int)$project['id']);
        $parents = DB::all(
            'SELECT id,number,title,type FROM tickets WHERE project_id=? AND id<>? AND archived=0 ORDER BY number DESC LIMIT 500',
            [$project['id'], $ticket['id']],
        );
        $comments = \App\Models\GitConversation::comments((int)$id);
        [$commentRoots, $commentReplies] = Ticket::commentThreads($comments, (string) ($project['comment_order'] ?? 'asc'));
        $attachments = DB::all(
            'SELECT id,original_name,size_bytes,mime FROM attachments WHERE ticket_id=? ORDER BY id',
            [$id],
        );
        $links = DB::all(
            'SELECT l.*,t.number,t.title,p.project_key
                FROM ticket_links l
                JOIN tickets t ON t.id=l.target_id
                JOIN projects p ON p.id=t.project_id
                WHERE l.source_id=?',
            [$id],
        );
        $children = DB::all(
            'SELECT id,number,title,status,type FROM tickets WHERE parent_id=? AND archived=0 ORDER BY rank_value',
            [$id],
        );
        extract(\App\Services\TicketDevelopment::data($ticket,$project));
        $title = $ticket['key'];
        $section = 'ticket';
        View::render(
            'ticket',
            compact(
                'ticket',
                'history','time','targetRelease',
                'project',
                'projects',
                'members',
                'projectTeams',
                'parents',
                'parent',
                'comments',
                'commentRoots',
                'commentReplies',
                'attachments',
                'links',
                'children',
                'events',
                'developmentCounts',
                'primaryRepository',
                'ticketRepositories','ticketBranches',
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
        // "Create another": back to the list with the creation form reopened.
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
        redirect(ticket_url((int)$id));
    }
    /** Inline edit of a single whitelisted field; the other fields keep their current values. JSON reply. */
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
                    'team_id',
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
    /** Updates only the start and due dates of the ticket. JSON reply. */
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
    /**
     * Board drag and drop: sets the ticket's status (column) and its position.
     * Position uses a float rank_value: placed halfway between the previous
     * card and "before_id", or after the last card of the column if no
     * before_id is given. JSON reply.
     */
    public function move(string $id): void
    {
        $u = Auth::editor();
        DB::transaction(function () use ($id, $u) {
            $t = Ticket::find((int) $id);
            // Lock the project row so concurrent moves in the same project compute ranks one at a time.
            DB::one('SELECT id FROM projects WHERE id=? FOR UPDATE', [$t['project_id']]);
            $status = (string) ($_POST['status'] ?? '');
            if (!isset(\App\Models\Workflow::labels('status',(int)$t['project_id'],true)[$status])) {
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
    /** Pauses the ticket (hidden from active views) or resumes it to its previous status. */
    public function pause(string $id): void
    {
        $user=Auth::editor();$action=$_POST['action']??'';
        if (!in_array($action,['pause','resume'],true)) throw new \InvalidArgumentException('Action de pause invalide.');
        Ticket::setPaused((int)$id,$action==='pause',(int)$user['id'],(int)($_POST['version']??0));
        flash($action==='pause'?'Ticket mis en pause. Il est masqué des vues actives.':'Le ticket a repris son état précédent.');
        redirect(ticket_url((int)$id));
    }
    /** Archives the ticket, or restores it when "restore" is posted. */
    public function archive(string $id): void
    {
        $u = Auth::editor();
        $t = Ticket::find((int) $id);
        $restore = !empty($_POST['restore']);
        // Uses the version just read from the database, not a posted one: no
        // conflict check against edits made since the page was loaded.
        Ticket::archive((int) $id, !$restore, (int) $u['id'], (int) $t['version']);
        flash(
            $restore
                ? 'Ticket restauré.'
                : 'Ticket archivé. Vous pouvez le restaurer depuis sa fiche.',
        );
        redirect(ticket_url((int)$id));
    }
    /**
     * Applies one change (status, priority, assignee or flag) to 1–200 selected
     * tickets in a single transaction: one invalid ticket cancels the whole batch.
     */
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
                    [$field => $field==='assignee_id' && ($_POST['value']??'')==='none'?'':($_POST['value'] ?? '')],
                );
                Ticket::update($id, $data, (int) $u['id'], (int) $t['version']);
            }
        });
        flash(count($ids) . ' tickets modifiés.');
        // Only return to /list or /all (with any query string): prevents open redirects.
        $return=(string)($_POST['_return']??'/list');
        redirect(preg_match('#^/(?:list|all)(?:\?[^\r\n]*)?$#D',$return)?$return:'/list');
    }
    public function comment(string $id): void
    {
        $u = Auth::editor();
        $parent = (int) ($_POST['parent_id'] ?? 0);
        $comment = Ticket::comment((int) $id, (string) ($_POST['body'] ?? ''), (int) $u['id'], (string) ($_POST['body_format'] ?? 'text'), $parent ?: null);
        redirect(ticket_url((int)$id,'#comment-'.$comment));
    }
    /** Creates a relation (default "relates") from ticket {id} to target_id. */
    public function link(string $id): void
    {
        $u = Auth::editor();
        Ticket::link(
            (int) $id,
            (int) ($_POST['target_id'] ?? 0),
            (string) ($_POST['relation'] ?? 'relates'),
            (int) $u['id'],
        );
        redirect(ticket_url((int)$id));
    }
    /** Deletes ticket link {id} (a link id, not a ticket id) and returns to the source ticket. */
    public function unlink(string $id): void
    {
        Auth::editor();
        $ticket = Ticket::unlink((int) $id);
        redirect(ticket_url((int)$ticket));
    }
}
