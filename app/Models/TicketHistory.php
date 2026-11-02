<?php
declare(strict_types=1);
namespace App\Models;
use App\Core\DB;

/** Immutable snapshots: identities and the labels visible at change time are retained. */
final class TicketHistory
{
    public const FIELDS=['title'=>'Résumé','description'=>'Description','type'=>'Type','status'=>'État','priority'=>'Priorité','assignee_id'=>'Responsable','team_id'=>'Équipe','parent_id'=>'Parent','labels'=>'Étiquettes','start_date'=>'Date de début','due_date'=>'Échéance','flagged'=>'Blocage','estimated_hours'=>'Temps estimé','progress_percent'=>'Avancement','release_id'=>'Version cible','resolution'=>'Résolution','validation_state'=>'Validation','validated_by'=>'Validé par','validated_at'=>'Date de validation','validation_note'=>'Note de validation','archived'=>'Archive'];
    public static function changes(array $before,array $after): array
    {
        $changes=[];
        foreach(self::FIELDS as $field=>$label) {
            $a=self::normalize($field,$before[$field]??null);$b=self::normalize($field,$after[$field]??null);
            if ($a!==$b) $changes[]=['field'=>$field,'label'=>$label,'before'=>$a,'after'=>$b,'before_label'=>self::display($field,$a,(int)$after['project_id']),'after_label'=>self::display($field,$b,(int)$after['project_id'])];
        }
        return $changes;
    }
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
            'parent_id'=>(function() use($value){$p=DB::one('SELECT p.project_key,t.number FROM tickets t JOIN projects p ON p.id=t.project_id WHERE t.id=?',[$value]);return $p?$p['project_key'].'-'.$p['number']:'#'.$value;})(),
            'resolution'=>TicketClosure::RESOLUTIONS[$value]??(string)$value,
            'validation_state'=>TicketClosure::VALIDATIONS[$value]??(string)$value,
            'labels'=>$value?implode(', ',$value):'—',
            'flagged','archived'=>$value?'Oui':'Non',
            'estimated_hours'=>\App\Services\Duration::label($value),
            'progress_percent'=>$value.' %',
            default=>(string)$value,
        };
    }
    public static function record(array $before,array $after,?int $actor,string $action='updated',string $source='application'): void
    {
        $changes=self::changes($before,$after);
        if ($changes || $action==='created') self::event((int)$after['id'],$actor,(int)$after['version'],$action,$changes,$source);
    }
    public static function event(int $ticket,?int $actor,int $version,string $action,array $changes,string $source='application'): void
    {
        if (!DB::connection()->inTransaction()) throw new \RuntimeException('Historique hors transaction métier.');
        DB::run('INSERT INTO ticket_history(ticket_id,actor_id,ticket_version,action,source,changes_json) VALUES(?,?,?,?,?,?)',[$ticket,$actor,$version,$action,$source,json_encode($changes,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]);
    }
    public static function page(int $ticket,int $offset=0,int $limit=30): array
    {
        Ticket::find($ticket);$limit=max(1,min(100,$limit));$offset=max(0,$offset);
        $rows=DB::all("SELECT h.*,u.name actor_name FROM ticket_history h LEFT JOIN users u ON u.id=h.actor_id WHERE ticket_id=? ORDER BY h.id DESC LIMIT $limit OFFSET $offset",[$ticket]);
        foreach($rows as &$row){$row['changes']=json_decode($row['changes_json'],true,64,JSON_THROW_ON_ERROR);unset($row['changes_json']);}
        return ['total'=>(int)DB::one('SELECT COUNT(*) n FROM ticket_history WHERE ticket_id=?',[$ticket])['n'],'offset'=>$offset,'limit'=>$limit,'entries'=>$rows];
    }
}
