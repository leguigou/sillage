<?php
declare(strict_types=1);
namespace App\Models;
use App\Core\DB;
/**
 * People working on a ticket: the responsible person and team (ticket columns, changed through
 * Ticket::patch) and additional collaborators (table ticket_collaborators). The assignee is never
 * also stored as a collaborator. Edits require the ticket version, are refused on archived tickets,
 * and record a history entry for collaborator changes.
 */
final class TicketPeople
{
    /** Active, verified collaborators of several tickets, as [ticket_id => list of {id, name}]. */
    public static function forTickets(array $ids): array
    {
        if(!$ids) return [];
        $result=[];
        foreach(DB::all('SELECT c.ticket_id,u.id,u.name
            FROM ticket_collaborators c
            JOIN users u ON u.id=c.user_id
            WHERE c.ticket_id IN ('.implode(',',array_fill(0,count($ids),'?')).') AND u.active=1 AND u.verified_at IS NOT NULL ORDER BY u.name,u.id',$ids) as $row) $result[$row['ticket_id']][]=$row;
        return $result;
    }
    /**
     * Saves assignee, team and the full collaborator list in one step. The ticket version is bumped
     * once, either by Ticket::patch (assignee/team changed) or directly (collaborators only).
     */
    public static function save(int $id,array $input,int $actor,int $version): array
    {
        return DB::transaction(function() use($id,$input,$actor,$version) {
            $old=Ticket::lock($id,$version);
            if($old['archived']) throw new \InvalidArgumentException('Restaurez ce ticket avant de modifier ses personnes.');
            $raw=$input['collaborators']??[];
            if(!is_array($raw) || !array_is_list($raw)) throw new \InvalidArgumentException('Choisissez les collaborateurs dans la liste.');
            $ids=[];
            foreach($raw as $value) {
                $user=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
                if(!$user || !DB::one('SELECT id
                    FROM users
                    WHERE id=? AND active=1 AND verified_at IS NOT NULL',[$user])) throw new \InvalidArgumentException('Un collaborateur est indisponible. Rechargez la liste.');
                $ids[$user]=$user;
            }
            $assignee=$input['assignee_id']??$old['assignee_id'];$team=$input['team_id']??$old['team_id'];
            if($assignee!==null && $assignee!=='' && filter_var($assignee,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])===false) throw new \InvalidArgumentException('Choisissez un responsable dans la liste.');
            // Reuse the ticket's validation, history, transition and notification boundary.
            $validated=Ticket::validate(array_replace($old,['labels'=>$old['labels_array'],'assignee_id'=>$assignee,'team_id'=>$team]),(int)$old['project_id'],$id);
            unset($ids[$validated['assignee_id']??0]);sort($ids,SORT_NUMERIC);
            $before=DB::all('SELECT u.id,u.name
                FROM ticket_collaborators c
                JOIN users u ON u.id=c.user_id
                WHERE ticket_id=?
                ORDER BY u.id',[$id]);$beforeIds=array_map('intval',array_column($before,'id'));
            $changed=$beforeIds!==$ids;$baseChanged=$old['assignee_id']!=$validated['assignee_id'] || $old['team_id']!=$validated['team_id'];
            if(!$changed && !$baseChanged) return Ticket::find($id);
            if($baseChanged) Ticket::patch($id,['assignee_id'=>$assignee,'team_id'=>$team],$actor,$version);
            else DB::run('UPDATE tickets SET version=version+1,updated_at=UTC_TIMESTAMP() WHERE id=?',[$id]);
            if($changed) {
                DB::run('DELETE FROM ticket_collaborators WHERE ticket_id=?',[$id]);
                foreach($ids as $user) DB::run('INSERT INTO ticket_collaborators(ticket_id,user_id) VALUES(?,?)',[$id,$user]);
                $after=self::forTickets([$id])[$id]??[];
                TicketHistory::event($id,$actor,(int)Ticket::find($id)['version'],'updated',[['field'=>'collaborators','label'=>'Collaborateurs','before'=>$beforeIds,'after'=>$ids,'before_label'=>implode(', ',array_column($before,'name'))?:'Aucun','after_label'=>implode(', ',array_column($after,'name'))?:'Aucun']]);
                Ticket::activity((int)$old['project_id'],$id,$actor,'a modifié les collaborateurs du ticket');
            }
            return Ticket::find($id);
        });
    }
}
