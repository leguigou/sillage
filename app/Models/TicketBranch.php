<?php
declare(strict_types=1);
namespace App\Models;
use App\Core\DB;
use App\Services\DevelopmentPayload;

/** Explicit branch choices are separate from the observations recomputed on each Git delivery. */
final class TicketBranch
{
    public static function connection(int $ticket,int $connection): array
    {
        $row=DB::one('SELECT c.* FROM connections c JOIN project_connections pc ON pc.connection_id=c.id JOIN tickets t ON t.project_id=pc.project_id WHERE t.id=? AND c.id=?',[$ticket,$connection]);
        if (!$row) throw new \InvalidArgumentException('Ce dépôt n’est pas lié au projet de ce ticket.');
        return $row;
    }
    public static function all(int $ticket): array
    {
        return DB::all('SELECT b.id,b.connection_id,b.branch_name,b.created_at,c.provider,c.repository FROM ticket_branch_links b JOIN tickets t ON t.id=b.ticket_id JOIN project_connections pc ON pc.connection_id=b.connection_id AND pc.project_id=t.project_id JOIN connections c ON c.id=b.connection_id WHERE b.ticket_id=? ORDER BY b.id',[$ticket]);
    }
    public static function link(int $ticket,array $connection,array $event,int $actor): int
    {
        if (($event['kind']??null)!=='branch' || ($event['state']??null)!=='active') throw new \InvalidArgumentException('La branche doit exister et être active chez le fournisseur.');
        $name=DevelopmentPayload::branchName($event['metadata']['branch']??null);
        if (($event['external_key']??null)!==DevelopmentPayload::branchKey($name)) throw new \InvalidArgumentException('Identité de branche incohérente.');
        return DB::transaction(function() use($ticket,$connection,$event,$actor,$name) {
            DevelopmentEvent::lockProjects((int)$connection['id']);
            self::connection($ticket,(int)$connection['id']);
            $id=DB::insert('INSERT INTO ticket_branch_links(ticket_id,connection_id,external_key,branch_name,created_by) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)',[$ticket,$connection['id'],$event['external_key'],$name,$actor]);
            DevelopmentEvent::save($connection,$event,false);
            DevelopmentEvent::attachKnownBranch($connection,$ticket,$name);
            return $id;
        });
    }
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
                $event['references']=DevelopmentPayload::references($event);$event['trigger']=null;
                DevelopmentEvent::save($connection,$event,false);
            }
        });
    }
}
