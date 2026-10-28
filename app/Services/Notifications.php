<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\{DB,Mailer,Company};
use App\Models\{Ticket,ProjectEmail};

/** Transactional outbox: only committed ticket changes can produce an email. */
final class Notifications
{
    private static ?string $batch=null;
    private const FIELDS=['title'=>'Résumé','description'=>'Description','type'=>'Type','priority'=>'Priorité','assignee_id'=>'Responsable','team_id'=>'Équipe','parent_id'=>'Parent','labels'=>'Étiquettes','start_date'=>'Date de début','due_date'=>'Échéance','flagged'=>'Blocage','estimated_hours'=>'Temps estimé','progress_percent'=>'Avancement','status'=>'État'];
    public static function changed(array $before,array $after,?int $actor): void
    {
        $fields=[];
        foreach(self::FIELDS as $key=>$label) {
            $a=$before[$key]??null;$b=$after[$key]??null;
            if ($key==='estimated_hours') { $a=$a===null?null:number_format((float)$a,2,'.','');$b=$b===null?null:number_format((float)$b,2,'.',''); }
            if (($a===null)!==($b===null) || (string)$a!==(string)$b) $fields[]=$key;
        }
        if (!$fields) return;
        $events=[];
        if (array_intersect($fields,['assignee_id','team_id'])) $events[]='assigned';
        if (in_array('status',$fields,true)) $events[]=$after['status']==='done'?'completed':'status';
        if (array_diff($fields,['assignee_id','team_id','status'])) $events[]='updated';
        self::enqueue($after,$events,$actor,$fields,$before['assignee_id']?(int)$before['assignee_id']:null);
    }
    public static function enqueue(array $ticket,array $events,?int $actor,array $fields=[],?int $previousAssignee=null): void
    {
        if (!DB::connection()->inTransaction()) throw new \RuntimeException('La notification doit être enregistrée avec sa transaction métier.');
        $config=ProjectEmail::get((int)$ticket['project_id']);
        if (!$config['enabled']) return;
        self::$batch??=bin2hex(random_bytes(16));
        if (in_array('created',$events,true)) DB::run("DELETE FROM notification_outbox WHERE batch_key=? AND ticket_id=? AND state='pending'",[self::$batch,$ticket['id']]);
        $wanted=[];
        foreach($events as $event) {
            if (!isset(ProjectEmail::EVENTS[$event])) throw new \InvalidArgumentException('Événement e-mail inconnu.');
            foreach($config['rules'][$event]??[] as $role) {
                $ids=match($role) {
                    'reporter'=>empty($ticket['reporter_id'])?[]:[$ticket['reporter_id']],
                    'assignee'=>array_filter([$ticket['assignee_id'],$event==='assigned'?$previousAssignee:null]),
                    'team'=>empty($ticket['team_id'])?[]:array_column(DB::all('SELECT user_id FROM team_members WHERE team_id=?',[$ticket['team_id']]),'user_id'),
                    'project_teams'=>array_column(DB::all('SELECT DISTINCT m.user_id FROM team_members m JOIN team_projects tp ON tp.team_id=m.team_id WHERE tp.project_id=?',[$ticket['project_id']]),'user_id'),
                    'admins'=>array_column(DB::all("SELECT id FROM users WHERE role='admin'"),'id'),
                    'selected'=>$config['recipients'],
                    default=>[],
                };
                foreach($ids as $id) $wanted[(int)$id][$event]=true;
            }
        }
        foreach($wanted as $id=>$matching) {
            if (!$config['notify_actor'] && $actor===$id) continue;
            $person=DB::one('SELECT email,name FROM users WHERE id=? AND active=1 AND verified_at IS NOT NULL AND ticket_email_enabled=1',[$id]);
            if (!$person) continue;
            $old=DB::one('SELECT id,events_json,fields_json FROM notification_outbox WHERE batch_key=? AND ticket_id=? AND recipient_id=? AND state=? FOR UPDATE',[self::$batch,$ticket['id'],$id,'pending']);
            $merged=array_values(array_unique([...($old?json_decode($old['events_json'],true):[]),...array_keys($matching)]));
            $allFields=array_values(array_unique([...($old?json_decode($old['fields_json'],true):[]),...$fields]));
            $eventLabels=array_map(fn($event)=>ProjectEmail::EVENTS[$event],$merged);
            $actorName=$actor?DB::one('SELECT name FROM users WHERE id=?',[$actor])['name']??'Un membre':'Le système ou un demandeur externe';
            $subject=Company::name().' · '.$ticket['key'].' · '.implode(', ',$eventLabels);
            $subject=mb_substr($subject,0,450);
            $body="Bonjour ".$person['name'].",\n\n".$actorName." a fait évoluer le ticket ".$ticket['key']." dans le projet ".$ticket['project_name'].".\n\n";
            $body.=$ticket['title']."\n".implode(' · ',$eventLabels)."\n";
            if ($allFields) $body.='Champs modifiés : '.implode(', ',array_map(fn($field)=>self::FIELDS[$field],$allFields)).".\n";
            $body.='État : '.Ticket::STATUSES[$ticket['status']]."\nResponsable : ".($ticket['assignee_name']??'Non assigné')."\nÉquipe : ".($ticket['team_name']??'Sans équipe')."\n\nConsulter le ticket (connexion requise) :\n".url('/tickets/'.$ticket['id'])."\n\nVos préférences e-mail : ".url('/settings#email-preferences')."\nL’équipe ".Company::name().' — Sillage';
            if ($old) DB::run('UPDATE notification_outbox SET events_json=?,fields_json=?,subject=?,body=? WHERE id=?',[json_encode($merged,JSON_THROW_ON_ERROR),json_encode($allFields,JSON_THROW_ON_ERROR),$subject,$body,$old['id']]);
            else DB::run('INSERT INTO notification_outbox(batch_key,ticket_id,project_id,recipient_id,recipient_email,events_json,fields_json,subject,body) VALUES(?,?,?,?,?,?,?,?,?)',[self::$batch,$ticket['id'],$ticket['project_id'],$id,$person['email'],json_encode($merged,JSON_THROW_ON_ERROR),json_encode($allFields,JSON_THROW_ON_ERROR),$subject,$body]);
        }
    }
    public static function deliver(int $limit=50): array
    {
        if (DB::connection()->inTransaction()) throw new \RuntimeException('Envoi interdit avant validation de la transaction.');
        $result=['sent'=>0,'failed'=>0,'cancelled'=>0];
        for($i=0;$i<max(1,min(200,$limit));$i++) {
            $row=DB::transaction(function() {
                $row=DB::one("SELECT * FROM notification_outbox WHERE (state='pending' AND available_at<=UTC_TIMESTAMP() AND attempts<5) OR (state='sending' AND last_attempt_at<UTC_TIMESTAMP()-INTERVAL 10 MINUTE) ORDER BY id LIMIT 1 FOR UPDATE SKIP LOCKED");
                if (!$row) return null;
                if ((int)$row['attempts']>=5) {
                    DB::run("UPDATE notification_outbox SET state='failed',lease_token=NULL,last_error=? WHERE id=?",['Nombre de tentatives atteint après interruption du traitement.',$row['id']]);
                    return $row+['exhausted'=>true];
                }
                $lease=bin2hex(random_bytes(16));
                DB::run("UPDATE notification_outbox SET state='sending',attempts=attempts+1,lease_token=?,last_attempt_at=UTC_TIMESTAMP() WHERE id=?",[$lease,$row['id']]);
                return $row+['lease'=>$lease];
            });
            if (!$row) break;
            if (!empty($row['exhausted'])) { $result['failed']++;continue; }
            $person=DB::one('SELECT email FROM users WHERE id=? AND active=1 AND verified_at IS NOT NULL AND ticket_email_enabled=1',[$row['recipient_id']]);
            $config=ProjectEmail::get((int)$row['project_id']);
            if (!$person || $person['email']!==$row['recipient_email'] || !$config['enabled']) {
                DB::run("UPDATE notification_outbox SET state='cancelled',lease_token=NULL WHERE id=? AND lease_token=?",[$row['id'],$row['lease']]);$result['cancelled']++;continue;
            }
            try {
                Mailer::send($row['recipient_email'],$row['subject'],$row['body']);
                DB::run("UPDATE notification_outbox SET state='sent',sent_at=UTC_TIMESTAMP(),last_error=NULL,lease_token=NULL WHERE id=? AND lease_token=?",[$row['id'],$row['lease']]);
                $result['sent']++;
            } catch(\Throwable) {
                $attempt=(int)$row['attempts']+1;
                DB::run("UPDATE notification_outbox SET state=?,available_at=?,last_error=?,lease_token=NULL WHERE id=? AND lease_token=?",[$attempt>=5?'failed':'pending',gmdate('Y-m-d H:i:s',time()+min(3600,60*(2**min(5,$attempt-1)))),'Le transport de messagerie n’a pas confirmé l’envoi. Vérifiez MAIL_TRANSPORT et MAIL_FROM.',$row['id'],$row['lease']]);
                $result['failed']++;
            }
        }
        return $result;
    }
}
