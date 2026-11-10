<?php
declare(strict_types=1);
namespace App\Models;
use App\Core\DB;

/**
 * Immutable snapshots: identities and the labels visible at change time are retained.
 *
 * Audit trail of ticket changes (table ticket_history). Each entry stores the ticket version it
 * produced, the action, the source (application, mcp, automation, external, ...) and a JSON list of
 * field changes with raw and human-readable before/after values. Entries must be written inside
 * the business transaction that made the change, so history and data cannot diverge.
 * changes() is also used by TicketClosure to detect content edits.
 */
final class TicketHistory
{
    public const FIELDS=['title'=>'Résumé','description'=>'Description','type'=>'Type','status'=>'État','priority'=>'Priorité','assignee_id'=>'Responsable','team_id'=>'Équipe','parent_id'=>'Parent','labels'=>'Étiquettes','start_date'=>'Date de début','due_date'=>'Échéance','flagged'=>'Blocage','estimated_hours'=>'Temps estimé','progress_percent'=>'Avancement','release_id'=>'Version cible','resolution'=>'Résolution','validation_state'=>'Validation','validated_by'=>'Validé par','validated_at'=>'Date de validation','validation_note'=>'Note de validation','archived'=>'Archive'];
    /** Lists the FIELDS whose normalized value differs between two ticket arrays ($after must contain project_id). */
    public static function changes(array $before,array $after): array
    {
        $changes=[];
        foreach(self::FIELDS as $field=>$label) {
            $a=self::normalize($field,$before[$field]??null);$b=self::normalize($field,$after[$field]??null);
            if ($a!==$b) $changes[]=['field'=>$field,'label'=>$label,'before'=>$a,'after'=>$b,'before_label'=>self::display($field,$a,(int)$after['project_id']),'after_label'=>self::display($field,$b,(int)$after['project_id'])];
        }
        return $changes;
    }
    /** Brings DB values and form values to one representation so that equal values compare equal. */
    private static function normalize(string $field,mixed $value): mixed
    {
        if ($value===null) return null;
        if ($field==='description') return Ticket::description((string)$value);
        if ($field==='labels') return is_array($value)?$value:json_decode($value,true,32,JSON_THROW_ON_ERROR);
        if ($field==='estimated_hours') return number_format((float)$value,2,'.','');
        if (in_array($field,['assignee_id','team_id','parent_id','release_id','validated_by','progress_percent','flagged','archived'],true)) return (int)$value;
        return (string)$value;
    }
    private static function display(string $field,mixed $value,int $project): string
    {
        if ($value===null || $value==='') return '—';
        return match($field) {
            'status','type'=>Workflow::label($field,$project,(string)$value),
            'priority'=>Ticket::PRIORITIES[$value]??(string)$value,
            'assignee_id','validated_by'=>DB::one('SELECT name FROM users WHERE id=?',[$value])['name']??'#'.$value,
            'team_id'=>DB::one('SELECT name FROM teams WHERE id=?',[$value])['name']??'#'.$value,
            'release_id'=>DB::one('SELECT name FROM releases WHERE id=?',[$value])['name']??'#'.$value,
            'parent_id'=>(function() use($value){$p=DB::one('SELECT p.project_key,t.number
                FROM tickets t
                JOIN projects p ON p.id=t.project_id
                WHERE t.id=?',[$value]);return $p?$p['project_key'].'-'.$p['number']:'#'.$value;})(),
            'resolution'=>TicketClosure::RESOLUTIONS[$value]??(string)$value,
            'validation_state'=>TicketClosure::VALIDATIONS[$value]??(string)$value,
            'labels'=>$value?implode(', ',$value):'—',
            'flagged','archived'=>$value?'Oui':'Non',
            'estimated_hours'=>\App\Services\Duration::label($value),
            'progress_percent'=>$value.' %',
            default=>(string)$value,
        };
    }
    /** Writes an entry for the differences between two ticket snapshots; skipped when nothing changed (except on creation). */
    public static function record(array $before,array $after,?int $actor,string $action='updated',string $source='application'): void
    {
        $changes=self::changes($before,$after);
        if ($changes || $action==='created') self::event((int)$after['id'],$actor,(int)$after['version'],$action,$changes,$source);
    }
    /** Writes a raw history entry (used for changes outside FIELDS, e.g. time logs or collaborators); requires an open transaction. */
    public static function event(int $ticket,?int $actor,int $version,string $action,array $changes,string $source='application'): void
    {
        if (!DB::connection()->inTransaction()) throw new \RuntimeException('Historique hors transaction métier.');
        DB::run('INSERT INTO ticket_history(ticket_id,actor_id,ticket_version,action,source,changes_json)
            VALUES(?,?,?,?,?,?)',[$ticket,$actor,$version,$action,$source,json_encode($changes,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]);
    }
    /** Paginated history, newest first; $changesOnly hides the creation entry and entries without field changes. */
    public static function page(int $ticket,int $offset=0,int $limit=30,bool $changesOnly=false): array
    {
        Ticket::find($ticket);$limit=max(1,min(100,$limit));$offset=max(0,$offset);
        $where=self::filter($changesOnly);
        $rows=DB::all("SELECT h.*,u.name actor_name FROM ticket_history h LEFT JOIN users u ON u.id=h.actor_id WHERE $where ORDER BY h.id DESC LIMIT $limit OFFSET $offset",[$ticket]);
        foreach($rows as &$row){$row['changes']=json_decode($row['changes_json'],true,64,JSON_THROW_ON_ERROR);unset($row['changes_json']);}
        return ['total'=>(int)DB::one('SELECT COUNT(*) n FROM ticket_history WHERE '.$where,[$ticket])['n'],'offset'=>$offset,'limit'=>$limit,'entries'=>$rows];
    }
    /** SQL condition shared by page() and withConversation() so both see the same entries. */
    private static function filter(bool $changesOnly): string
    {
        return 'ticket_id=?'.($changesOnly?" AND action<>'created' AND JSON_LENGTH(changes_json)>0":'');
    }

    /**
     * Interleaves the ticket conversation into a history page built by page(): each comment becomes an entry
     * (kind "comment") with its author, date, depth and the author it answers, never its content. Only the
     * comments dated inside the page's time window are added, so every comment shows on exactly one page.
     * History entries get kind "change". Returns $history with the merged entries, newest first.
     */
    public static function withConversation(int $ticket,array $history,bool $changesOnly=true): array
    {
        $where=self::filter($changesOnly);
        // Window: below the entry shown just before this page, down to the oldest entry of the page when older pages exist.
        $upper=$history['offset']>0?(DB::one("SELECT created_at FROM ticket_history WHERE $where ORDER BY id DESC LIMIT 1 OFFSET ".((int)$history['offset']-1),[$ticket])['created_at']??null):null;
        $lower=$history['offset']+count($history['entries'])<$history['total']?(end($history['entries'])['created_at']??null):null;
        $comments=DB::all('SELECT c.id,c.parent_id,c.user_id,c.created_at,COALESCE(u.name,?) name
            FROM comments c LEFT JOIN users u ON u.id=c.user_id
            WHERE c.ticket_id=? ORDER BY c.id',[app_name(),$ticket]);
        $byId=array_column($comments,null,'id');
        $entries=array_map(fn($row)=>$row+['kind'=>'change'],$history['entries']);
        foreach($comments as $comment){
            if(($upper!==null && $comment['created_at']>=$upper) || ($lower!==null && $comment['created_at']<$lower)) continue;
            $depth=0;$root=$comment;
            for($current=$comment;$current['parent_id']!==null && isset($byId[$current['parent_id']]) && $depth<Ticket::COMMENT_REPLY_DEPTH;$depth++) $current=$root=$byId[$current['parent_id']];
            $parent=$comment['parent_id']!==null?($byId[$comment['parent_id']]??null):null;
            $entries[]=['kind'=>'comment','comment_id'=>(int)$comment['id'],'created_at'=>$comment['created_at'],
                'actor_id'=>$comment['user_id'],'actor_name'=>$comment['name'],'depth'=>$parent?max(1,$depth):0,
                'parent_name'=>$parent['name']??null,'thread_name'=>$depth>1?$root['name']:null];
        }
        // Newest first; on the same second a comment is listed above the field changes of that moment.
        usort($entries,fn($a,$b)=>[$b['created_at'],$b['kind']==='comment',$b['comment_id']??$b['id']??0]<=>[$a['created_at'],$a['kind']==='comment',$a['comment_id']??$a['id']??0]);
        $history['entries']=$entries;
        return $history;
    }
}
