<?php
declare(strict_types=1);
namespace App\Models;
use App\Core\{DB,Security};

final class TicketTime
{
    public static function spentSql(string $alias='t'): string
    {
        return "(SELECT COALESCE(SUM(w.hours),0) FROM ticket_worklogs w WHERE w.ticket_id=$alias.id AND w.deleted=0)";
    }
    public static function remainingSql(string $alias='t'): string
    {
        return "ROUND($alias.estimated_hours * (100 - $alias.progress_percent) / 100,2)";
    }
    public static function selectSql(string $alias='t'): string
    {
        return self::spentSql($alias).' spent_hours,'.self::remainingSql($alias).' remaining_hours';
    }
    public static function page(int $ticket,int $offset=0,int $limit=50): array
    {
        Ticket::find($ticket);
        $limit=max(1,min(100,$limit));$offset=max(0,$offset);
        return ['total'=>(int)DB::one('SELECT COUNT(*) n FROM ticket_worklogs WHERE ticket_id=? AND deleted=0',[$ticket])['n'],'offset'=>$offset,'limit'=>$limit,'entries'=>DB::all("SELECT w.*,u.name FROM ticket_worklogs w JOIN users u ON u.id=w.user_id WHERE ticket_id=? AND deleted=0 ORDER BY worked_on DESC,id DESC LIMIT $limit OFFSET $offset",[$ticket])];
    }
    public static function save(int $ticket,array $input,int $actor,int $version,?int $entry=null,bool $delete=false): array
    {
        return DB::transaction(function() use($ticket,$input,$actor,$version,$entry,$delete) {
            $t=Ticket::lock($ticket,$version);
            $old=$entry?DB::one('SELECT * FROM ticket_worklogs WHERE id=? AND ticket_id=? AND deleted=0',[$entry,$ticket]):null;
            if ($entry && !$old) throw new \InvalidArgumentException('Saisie de temps introuvable.');
            $person=DB::one('SELECT role FROM users WHERE id=? AND active=1 AND verified_at IS NOT NULL',[$actor]);
            if (!$person || $person['role']==='viewer' || ($old && (int)$old['user_id']!==$actor && $person['role']!=='admin')) throw new \InvalidArgumentException('Vous pouvez modifier uniquement vos propres saisies de temps.');
            if ($t['archived']) throw new \InvalidArgumentException('Restaurez le ticket avant de saisir du temps.');
            if ($delete && !$old) throw new \InvalidArgumentException('Saisie requise pour la suppression.');
            if ($delete) DB::run('UPDATE ticket_worklogs SET deleted=1,updated_at=UTC_TIMESTAMP() WHERE id=?',[$entry]);
            else {
                $hours=str_replace(',','.',trim((string)($input['hours']??'')));
                $day=Security::date($input['worked_on']??null);
                $note=trim((string)($input['note']??''));
                if (!preg_match('/^\d{1,2}(?:\.\d{1,2})?$/D',$hours) || (float)$hours<=0 || (float)$hours>24 || !$day || $day>gmdate('Y-m-d') || mb_strlen($note)>2000) throw new \InvalidArgumentException('Saisissez de 0,01 à 24 heures, une date passée ou du jour et une note de 2 000 caractères maximum.');
                if ($entry) DB::run('UPDATE ticket_worklogs SET hours=?,worked_on=?,note=?,updated_at=UTC_TIMESTAMP() WHERE id=?',[$hours,$day,$note,$entry]);
                else $entry=DB::insert('INSERT INTO ticket_worklogs(ticket_id,user_id,hours,worked_on,note) VALUES(?,?,?,?,?)',[$ticket,$actor,$hours,$day,$note]);
            }
            DB::run('UPDATE tickets SET version=version+1,updated_at=UTC_TIMESTAMP() WHERE id=?',[$ticket]);
            $after=DB::one('SELECT * FROM ticket_worklogs WHERE id=?',[$entry]);
            TicketHistory::event($ticket,$actor,$version+1,$delete?'time.deleted':($old?'time.updated':'time.created'),[['field'=>'worklog','label'=>'Temps passé','before'=>$old,'after'=>$after,'before_label'=>$old?self::label($old):'—','after_label'=>$delete?'Supprimé':self::label($after)]]);
            \App\Services\Notifications::enqueue(Ticket::find($ticket),['updated'],$actor,['spent_hours']);
            return ['entry'=>$after,'ticket'=>Ticket::find($ticket)];
        });
    }
    private static function label(array $entry): string {return number_format((float)$entry['hours'],2,',',' ').' h · '.$entry['worked_on'].' · '.$entry['note'];}
}
