<?php
declare(strict_types=1);
namespace App\Models;
use App\Core\DB;
use App\Services\DevelopmentPayload;

/**
 * Explicit branch choices are separate from the observations recomputed on each Git delivery.
 *
 * Manual ticket-to-branch links (table ticket_branch_links). After any change the branch event is
 * re-saved through DevelopmentEvent::save() so the computed links in development_event_tickets
 * reflect the new choice. Queries reading links require the repository to still be attached to the
 * ticket's project (project_connections). Writers lock the projects of the connection first.
 */
final class TicketBranch
{
    /**
     * Returns [ticket_id => providers] (github/bitbucket only) for the list's Git column: tickets with
     * a manual branch link, an observed active branch, or a linked pull request in any state. A PR
     * counts on its own because its ticket key is often only in the PR title, and branch pushes may
     * never reach Sillage (push trigger not enabled on the webhook, branch pushed before the webhook).
     */
    public static function providersForTickets(array $ids): array
    {
        if(!$ids) return [];$slots=implode(',',array_fill(0,count($ids),'?'));
        $rows=DB::all("SELECT DISTINCT b.ticket_id,c.provider
                FROM ticket_branch_links b
                JOIN tickets t ON t.id=b.ticket_id
                JOIN project_connections pc ON pc.project_id=t.project_id AND pc.connection_id=b.connection_id
                JOIN connections c ON c.id=b.connection_id
                WHERE b.ticket_id IN ($slots)
            UNION
            SELECT DISTINCT l.ticket_id,c.provider
                FROM development_event_tickets l
                JOIN development_events e ON e.id=l.event_id
                JOIN tickets t ON t.id=l.ticket_id
                JOIN project_connections pc ON pc.project_id=t.project_id AND pc.connection_id=e.connection_id
                JOIN connections c ON c.id=e.connection_id
                WHERE l.ticket_id IN ($slots)
                    AND (e.kind='pull_request' OR (e.kind='branch' AND e.state='active'))
            ORDER BY provider",[...$ids,...$ids]);
        $result=[];foreach($rows as $row) if(in_array($row['provider'],['github','bitbucket'],true)) $result[$row['ticket_id']][]=$row['provider'];return $result;
    }
    /** Returns the connection row, or fails if it is not attached to the ticket's project. */
    public static function connection(int $ticket,int $connection): array
    {
        $row=DB::one('SELECT c.*
            FROM connections c
            JOIN project_connections pc ON pc.connection_id=c.id
            JOIN tickets t ON t.project_id=pc.project_id
            WHERE t.id=? AND c.id=?',[$ticket,$connection]);
        if (!$row) throw new \InvalidArgumentException('Ce dépôt n’est pas lié au projet de ce ticket.');
        return $row;
    }
    public static function all(int $ticket): array
    {
        return DB::all('SELECT b.id,b.connection_id,b.branch_name,b.created_at,c.provider,c.repository
            FROM ticket_branch_links b
            JOIN tickets t ON t.id=b.ticket_id
            JOIN project_connections pc ON pc.connection_id=b.connection_id AND pc.project_id=t.project_id
            JOIN connections c ON c.id=b.connection_id
            WHERE b.ticket_id=?
            ORDER BY b.id',[$ticket]);
    }
    /**
     * Manually links a ticket to an active branch event (idempotent) and attaches the branch's already
     * known commits and PRs to the ticket. Returns the link id.
     */
    public static function link(int $ticket,array $connection,array $event,int $actor): int
    {
        if (($event['kind']??null)!=='branch' || ($event['state']??null)!=='active') throw new \InvalidArgumentException('La branche doit exister et être active chez le fournisseur.');
        $name=DevelopmentPayload::branchName($event['metadata']['branch']??null);
        if (($event['external_key']??null)!==DevelopmentPayload::branchKey($name)) throw new \InvalidArgumentException('Identité de branche incohérente.');
        return DB::transaction(function() use($ticket,$connection,$event,$actor,$name) {
            DevelopmentEvent::lockProjects((int)$connection['id']);
            self::connection($ticket,(int)$connection['id']);
            $id=DB::insert('INSERT INTO ticket_branch_links(ticket_id,connection_id,external_key,branch_name,created_by)
                VALUES(?,?,?,?,?)
                ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)',[$ticket,$connection['id'],$event['external_key'],$name,$actor]);
            DevelopmentEvent::save($connection,$event,false);
            DevelopmentEvent::attachKnownBranch($connection,$ticket,$name);
            return $id;
        });
    }
    /** An active branch of the project's repositories that is not yet linked to any ticket of the project. */
    private static function unassignedBranch(int $project, int $event): array
    {
        $branch = DB::one(
            "SELECT e.* FROM development_events e
            JOIN project_connections pc ON pc.connection_id=e.connection_id
            WHERE pc.project_id=? AND e.id=? AND e.kind='branch' AND e.state='active'",
            [$project, $event],
        );
        if (!$branch || DB::one(
            'SELECT 1 FROM development_event_tickets l JOIN tickets t ON t.id=l.ticket_id
             WHERE l.event_id=? AND t.project_id=? LIMIT 1',
            [$event, $project],
        )) {
            throw new \InvalidArgumentException('Cette branche n’est plus disponible pour une liaison dans ce projet.');
        }
        return DevelopmentEvent::hydrate($branch);
    }

    /** WHERE fragment (one parameter: project id) for open, non-archived tickets without any branch yet. */
    private static function eligibleTicketsSql(): string
    {
        return "t.project_id=? AND t.archived=0 AND ".Workflow::categorySql()."<>'done'
            AND NOT EXISTS (
                SELECT 1 FROM ticket_branch_links b JOIN project_connections pc
                ON pc.connection_id=b.connection_id AND pc.project_id=t.project_id
                WHERE b.ticket_id=t.id
            ) AND NOT EXISTS (
                SELECT 1 FROM development_event_tickets l JOIN development_events e ON e.id=l.event_id
                JOIN project_connections pc ON pc.connection_id=e.connection_id AND pc.project_id=t.project_id
                WHERE l.ticket_id=t.id AND e.kind='branch' AND e.state='active'
            )";
    }

    /** Up to 25 eligible tickets for linking an unassigned branch, filtered by title or key (LIKE, wildcards escaped). */
    public static function candidates(int $project, int $event, string $query): array
    {
        self::unassignedBranch($project, $event);
        $sql = "SELECT t.id, CONCAT(p.project_key,'-',t.number) AS `key`, t.title
                FROM tickets t JOIN projects p ON p.id=t.project_id WHERE ".self::eligibleTicketsSql();
        $params = [$project];
        if ($query !== '') {
            $pattern = '%'.strtr($query, ['!'=>'!!', '%'=>'!%', '_'=>'!_']).'%';
            $sql .= " AND (t.title LIKE ? ESCAPE '!' OR CONCAT(p.project_key,'-',t.number) LIKE ? ESCAPE '!')";
            array_push($params, $pattern, $pattern);
        }
        return DB::all($sql.' ORDER BY t.updated_at DESC,t.id DESC LIMIT 25', $params);
    }

    /**
     * Links an observed, unassigned branch to an eligible ticket, then requests a Git sync of the
     * connection. Returns the ticket id and key.
     */
    public static function linkObserved(int $project, int $event, int $ticket, int $actor): array
    {
        $branch = self::unassignedBranch($project, $event);
        return DB::transaction(function () use ($project, $event, $ticket, $actor, $branch) {
            DevelopmentEvent::lockProjects((int)$branch['connection_id']);
            // First lookup only found which connection to lock; re-check now that the projects are locked.
            $branch = self::unassignedBranch($project, $event);
            $row = DB::one('SELECT t.id,t.number FROM tickets t WHERE t.id=? AND '.self::eligibleTicketsSql().' FOR UPDATE', [$ticket, $project]);
            if (!$row) throw new \InvalidArgumentException('Choisissez un ticket ouvert du projet, sans branche associée.');
            $connection = self::connection($ticket, (int)$branch['connection_id']);
            $branch['trigger'] = null;
            self::link($ticket, $connection, $branch, $actor);
            \App\Services\GitSync::request((int)$connection['id']);
            return ['id'=>$ticket, 'key'=>Project::find($project)['project_key'].'-'.$row['number']];
        });
    }

    /** Removes a manual branch link and re-saves the stored branch event so its ticket links are recomputed. */
    public static function unlink(int $ticket,int $link): void
    {
        $row=DB::one('SELECT * FROM ticket_branch_links WHERE id=? AND ticket_id=?',[$link,$ticket]);
        if (!$row) throw new \InvalidArgumentException('Ce lien de branche n’existe plus.');
        DB::transaction(function() use($row,$ticket,$link) {
            DevelopmentEvent::lockProjects((int)$row['connection_id']);
            $connection=self::connection($ticket,(int)$row['connection_id']);
            DB::run('DELETE FROM ticket_branch_links WHERE id=? AND ticket_id=?',[$link,$ticket]);
            $stored=DB::one('SELECT * FROM development_events WHERE connection_id=? AND external_key=?',[$row['connection_id'],$row['external_key']]);
            if ($stored) {
                $event=DevelopmentEvent::hydrate($stored);
                $event['trigger']=null;
                DevelopmentEvent::save($connection,$event,false);
            }
        });
    }
}
