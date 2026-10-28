<?php
declare(strict_types=1);
namespace App\Models;
use App\Core\DB;
use App\Services\DevelopmentPayload;

final class DevelopmentEvent
{
    public static function save(array $connection,array $event,bool $live=false): int
    {
        return DB::transaction(function() use($connection,$event,$live) {
            $projects=DB::all('SELECT p.id,p.project_key FROM projects p JOIN project_connections pc ON pc.project_id=p.id WHERE pc.connection_id=? ORDER BY p.id FOR UPDATE',[$connection['id']]);
            if (!$projects) throw new \InvalidArgumentException('Cette connexion Git n’est liée à aucun projet.');
            $old=DB::one('SELECT * FROM development_events WHERE connection_id=? AND external_key=? FOR UPDATE',[$connection['id'],$event['external_key']]);
            $oldMeta=$old?json_decode($old['metadata_json']??'{}',true,64,JSON_THROW_ON_ERROR):[];
            if ($old && ($oldMeta['date_known']??true) && $event['metadata']['date_known'] && $old['occurred_at']>$event['occurred_at']) return (int)$old['id'];
            $tickets=[];
            foreach($projects as $project) {
                $numbers=[];
                preg_match_all('/\b'.preg_quote($project['project_key'],'/').'-([1-9][0-9]*)\b/i',$event['references'],$matches);
                foreach($matches[1] as $number) if (filter_var($number,FILTER_VALIDATE_INT)!==false) $numbers[]=(int)$number;
                $numbers=array_values(array_unique($numbers));
                if ($numbers) $tickets=[...$tickets,...array_column(DB::all('SELECT id FROM tickets WHERE project_id=? AND number IN ('.implode(',',array_fill(0,count($numbers),'?')).') ORDER BY id',[$project['id'],...$numbers]),'id')];
            }
            if ($event['commit_sha'] && $event['kind']!=='branch') {
                $linked=DB::all("SELECT DISTINCT l.ticket_id FROM development_event_tickets l JOIN development_events e ON e.id=l.event_id JOIN tickets t ON t.id=l.ticket_id JOIN project_connections pc ON pc.project_id=t.project_id AND pc.connection_id=e.connection_id WHERE e.connection_id=? AND e.commit_sha=? AND e.kind IN ('branch','commit','pull_request')",[$connection['id'],$event['commit_sha']]);
                $tickets=array_values(array_unique([...$tickets,...array_column($linked,'ticket_id')]));
            }
            $url=parse_url($event['external_url']);
            $expected=$connection['provider']==='github'?'github.com':'bitbucket.org';
            $safe=($url['scheme']??'')==='https' && strtolower($url['host']??'')===$expected && !isset($url['user']) && !isset($url['pass']) && !isset($url['port']);
            $metadata=$event['metadata'];
            if (!$safe && $event['external_url']!=='') $metadata['source_url']=$event['external_url'];
            $id=DB::insert('INSERT INTO development_events(connection_id,ticket_id,external_key,kind,title,external_url,state,occurred_at,commit_sha,metadata_json) VALUES(?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id),ticket_id=VALUES(ticket_id),title=VALUES(title),external_url=VALUES(external_url),state=VALUES(state),occurred_at=VALUES(occurred_at),commit_sha=VALUES(commit_sha),metadata_json=VALUES(metadata_json)',[
                $connection['id'],$tickets[0]??null,$event['external_key'],$event['kind'],$event['title'],$safe?$event['external_url']:'',$event['state'],$event['occurred_at'],$event['commit_sha'],json_encode($metadata,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),
            ]);
            DB::run('DELETE FROM development_event_tickets WHERE event_id=?',[$id]);
            foreach($tickets as $ticket) DB::run('INSERT INTO development_event_tickets(event_id,ticket_id) VALUES(?,?)',[$id,$ticket]);
            // History imports only establish observations. Live transitions may run opt-in rules once.
            if ($live && $event['trigger'] && (!$old || $old['state']!==$event['state'])) {
                foreach($tickets as $ticket) Automation::run($event['trigger'],(int)$ticket,null,false,['development_repository'=>$connection['repository']]);
            }
            return $id;
        });
    }

    public static function hydrate(array $event): array
    {
        $event['metadata']=json_decode($event['metadata_json']??'{}',true,64,JSON_THROW_ON_ERROR);
        unset($event['metadata_json']);
        $event['state_label']=DevelopmentPayload::STATES[$event['state']]??$event['state'];
        return $event;
    }

    public static function withTickets(array $events, int $project = 0): array
    {
        if (!$events) return [];
        $ids=array_column($events,'id');
        $sql='SELECT l.event_id,t.id,t.title,t.number,p.project_key FROM development_event_tickets l JOIN tickets t ON t.id=l.ticket_id JOIN projects p ON p.id=t.project_id JOIN project_connections pc ON pc.project_id=p.id JOIN development_events e ON e.id=l.event_id AND e.connection_id=pc.connection_id WHERE l.event_id IN ('.implode(',',array_fill(0,count($ids),'?')).')';
        $args=$ids;
        if ($project) { $sql.=' AND p.id=?'; $args[]=$project; }
        $refs=[];
        foreach(DB::all($sql.' ORDER BY t.id',$args) as $row) $refs[$row['event_id']][]=['id'=>$row['id'],'key'=>$row['project_key'].'-'.$row['number'],'title'=>$row['title']];
        foreach($events as &$event) {
            $event=self::hydrate($event); $event['ticket_refs']=$refs[$event['id']]??[];
            $event['ticket_id']=$event['ticket_refs'][0]['id']??null;
        }
        return $events;
    }
}
