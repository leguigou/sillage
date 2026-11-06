<?php
declare(strict_types=1);
namespace App\Models;
use App\Core\DB;
use App\Services\DevelopmentPayload;

final class DevelopmentEvent
{
    public static function summaryColumns(string $alias): string
    {
        if(!preg_match('/^[a-z]$/D',$alias)) throw new \InvalidArgumentException('Alias de lecture Git invalide.');
        return implode(',',array_map(fn($column)=>$alias.'.'.$column,['id','connection_id','ticket_id','external_key','kind','title','external_url','state','occurred_at','commit_sha'])).",JSON_REMOVE($alias.metadata_json,'$.details.files','$.details.commit_shas') AS metadata_json";
    }
    public static function lockProjects(int $connection): array
    {
        $projects=DB::all('SELECT p.id,p.project_key
            FROM projects p
            JOIN project_connections pc ON pc.project_id=p.id
            WHERE pc.connection_id=?
            ORDER BY p.id FOR UPDATE',[$connection]);
        if (!$projects) throw new \InvalidArgumentException('Cette connexion Git n’est liée à aucun projet.');
        return $projects;
    }
    public static function save(array $connection,array $event,bool $live=false): int
    {
        return DB::transaction(function() use($connection,$event,$live) {
            $projects=self::lockProjects((int)$connection['id']);
            $old=DB::one('SELECT * FROM development_events WHERE connection_id=? AND external_key=? FOR UPDATE',[$connection['id'],$event['external_key']]);
            $oldMeta=$old?json_decode($old['metadata_json']??'{}',true,64,JSON_THROW_ON_ERROR):[];
            $newAttempt=$event['kind']==='build' && (int)($event['metadata']['attempt']??1)>(int)($oldMeta['attempt']??1);
            if($old && $event['kind']==='build' && (int)($event['metadata']['attempt']??1)<(int)($oldMeta['attempt']??1)) return (int)$old['id'];
            if (!$newAttempt && $old && ($oldMeta['date_known']??true) && $event['metadata']['date_known'] && $old['occurred_at']>$event['occurred_at']) return (int)$old['id'];
            $tickets=[];
            foreach($projects as $project) {
                $numbers=[];
                preg_match_all('/\b'.preg_quote($project['project_key'],'/').'-([1-9][0-9]*)\b/i',$event['references'],$matches);
                foreach($matches[1] as $number) if (filter_var($number,FILTER_VALIDATE_INT)!==false) $numbers[]=(int)$number;
                $numbers=array_values(array_unique($numbers));
                if ($numbers) $tickets=[...$tickets,...array_column(DB::all('SELECT id
                    FROM tickets
                    WHERE project_id=? AND number IN ('.implode(',',array_fill(0,count($numbers),'?')).') ORDER BY id',[$project['id'],...$numbers]),'id')];
            }
            $branch=$event['metadata']['branch']??null;
            $localBranch=$event['kind']!=='pull_request' || strcasecmp((string)($event['metadata']['branch_repository']??''),$connection['repository'])===0;
            if ($localBranch && is_string($branch) && $branch!=='') {
                $manual=DB::all('SELECT b.ticket_id
                    FROM ticket_branch_links b
                    JOIN tickets t ON t.id=b.ticket_id
                    JOIN project_connections pc ON pc.project_id=t.project_id AND pc.connection_id=b.connection_id
                    WHERE b.connection_id=? AND b.external_key=?',[$connection['id'],DevelopmentPayload::branchKey($branch)]);
                $tickets=array_values(array_unique([...$tickets,...array_column($manual,'ticket_id')]));
            }
            if ($event['commit_sha'] && $event['kind']!=='branch') {
                $linked=DB::all("SELECT DISTINCT l.ticket_id
                    FROM development_event_tickets l
                    JOIN development_events e ON e.id=l.event_id
                    JOIN tickets t ON t.id=l.ticket_id
                    JOIN project_connections pc ON pc.project_id=t.project_id AND pc.connection_id=e.connection_id
                    WHERE e.connection_id=? AND (e.commit_sha=? OR (e.kind='pull_request' AND JSON_UNQUOTE(JSON_EXTRACT(e.metadata_json,'$.merge_sha'))=?)) AND e.kind IN ('branch','commit','pull_request')",[$connection['id'],$event['commit_sha'],$event['commit_sha']]);
                $tickets=array_values(array_unique([...$tickets,...array_column($linked,'ticket_id')]));
            }
            if($old && $event['kind']==='deployment') {
                $proven=DB::all('SELECT DISTINCT l.ticket_id
                    FROM development_deployment_prs d
                    JOIN development_events pr ON pr.id=d.pr_event_id AND pr.connection_id=?
                    JOIN development_event_tickets l ON l.event_id=pr.id
                    JOIN tickets t ON t.id=l.ticket_id
                    JOIN project_connections pc ON pc.project_id=t.project_id AND pc.connection_id=pr.connection_id
                    WHERE d.deployment_event_id=? AND d.head_sha=?',[$connection['id'],$old['id'],$event['commit_sha']]);
                $tickets=array_values(array_unique([...$tickets,...array_column($proven,'ticket_id')]));
            }
            $url=parse_url($event['external_url']);
            $expected=$connection['provider']==='github'?'github.com':'bitbucket.org';
            $safe=($url['scheme']??'')==='https' && strtolower($url['host']??'')===$expected && !isset($url['user']) && !isset($url['pass']) && !isset($url['port']);
            $metadata=$event['metadata'];
            // Detail snapshots are fetched separately; webhooks must not silently erase them.
            if($event['kind']==='pull_request') foreach(['details','merge_check'] as $key) if(isset($oldMeta[$key])) $metadata[$key]=$oldMeta[$key];
            if($event['kind']==='commit' && empty($metadata['author']) && !empty($oldMeta['author'])) $metadata['author']=$oldMeta['author'];
            if (!$safe && $event['external_url']!=='') $metadata['source_url']=$event['external_url'];
            $id=DB::insert('INSERT INTO development_events(connection_id,ticket_id,external_key,kind,title,external_url,state,occurred_at,commit_sha,metadata_json)
                VALUES(?,?,?,?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id),ticket_id=VALUES(ticket_id),title=VALUES(title),external_url=VALUES(external_url),state=VALUES(state),occurred_at=VALUES(occurred_at),commit_sha=VALUES(commit_sha),metadata_json=VALUES(metadata_json)',[
                $connection['id'],$tickets[0]??null,$event['external_key'],$event['kind'],$event['title'],$safe?$event['external_url']:'',$event['state'],$event['occurred_at'],$event['commit_sha'],json_encode($metadata,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),
            ]);
            DB::run('DELETE FROM development_event_tickets WHERE event_id=?',[$id]);
            foreach($tickets as $ticket) DB::run('INSERT INTO development_event_tickets(event_id,ticket_id) VALUES(?,?)',[$id,$ticket]);
            if(in_array($event['kind'],['branch','commit','pull_request'],true)) foreach(array_unique(array_filter([$event['commit_sha'],$event['metadata']['merge_sha']??null])) as $sha) {
                DB::run("INSERT IGNORE INTO development_event_tickets(event_id,ticket_id) SELECT r.id,l.ticket_id
                    FROM development_events r
                    JOIN development_event_tickets l ON l.event_id=?
                    JOIN tickets t ON t.id=l.ticket_id
                    JOIN project_connections pc ON pc.project_id=t.project_id AND pc.connection_id=r.connection_id
                    WHERE r.connection_id=? AND r.kind IN ('build','deployment') AND r.commit_sha=?",[$id,$connection['id'],$sha]);
            }
            if ($live && $tickets && $event['kind']==='pull_request') {
                GitConversation::observe($connection, array_replace($event, ['id'=>$id,'external_url'=>$safe?$event['external_url']:'']), $tickets);
            }
            // History imports only establish observations. Live transitions may run opt-in rules once.
            if ($live && $event['trigger'] && (!$old || $old['state']!==$event['state'])) {
                foreach($tickets as $ticket) Automation::run($event['trigger'],(int)$ticket,null,false,['development_repository'=>$connection['repository']]);
            }
            return $id;
        });
    }
    public static function deploymentProof(array $c,int $deployment,int $pr,string $base,string $head): void
    {
        DB::transaction(function() use($c,$deployment,$pr,$base,$head) {
            self::lockProjects((int)$c['id']);$current=DB::one("SELECT *
                FROM development_events
                WHERE id=? AND connection_id=? AND kind='deployment' FOR UPDATE",[$deployment,$c['id']]);
            $source=DB::one("SELECT * FROM development_events WHERE id=? AND connection_id=? AND kind='pull_request'",[$pr,$c['id']]);
            if(!$current || !$source || $current['commit_sha']!==$head || (self::hydrate($source)['metadata']['merge_sha']??null)!==$base) return;
            DB::run('INSERT INTO development_deployment_prs(deployment_event_id,pr_event_id,base_sha,head_sha)
                VALUES(?,?,?,?)
                ON DUPLICATE KEY UPDATE base_sha=VALUES(base_sha),head_sha=VALUES(head_sha),verified_at=UTC_TIMESTAMP()',[$deployment,$pr,$base,$head]);
            DB::run('INSERT IGNORE INTO development_event_tickets(event_id,ticket_id) SELECT ?,l.ticket_id
                FROM development_event_tickets l
                JOIN tickets t ON t.id=l.ticket_id
                JOIN project_connections pc ON pc.project_id=t.project_id AND pc.connection_id=?
                WHERE l.event_id=?',[$deployment,$c['id'],$pr]);
            DB::run('UPDATE development_events
                SET ticket_id=(SELECT MIN(ticket_id) FROM development_event_tickets WHERE event_id=?)
                WHERE id=?',[$deployment,$deployment]);
        });
    }
    public static function conflictsForTickets(array $ids): array
    {
        if(!$ids) return [];$result=[];
        $rows=DB::all('SELECT '.self::summaryColumns('e').',l.ticket_id AS linked_ticket
            FROM development_events e
            JOIN development_event_tickets l ON l.event_id=e.id
            JOIN tickets t ON t.id=l.ticket_id
            JOIN project_connections pc ON pc.project_id=t.project_id AND pc.connection_id=e.connection_id
            WHERE e.kind=\'pull_request\' AND e.state=\'open\' AND l.ticket_id IN ('.implode(',',array_fill(0,count($ids),'?')).')',$ids);
        foreach($rows as $row) if(\App\Services\GitMonitor::conflict(self::hydrate($row))['conflict']===true) $result[$row['linked_ticket']]=true;
        return $result;
    }

    public static function savePullRequestDetails(array $connection,array $event,array $commits,array $details,int $ticket): void
    {
        DB::transaction(function() use($connection,$event,$commits,$details,$ticket) {
            self::lockProjects((int)$connection['id']);TicketBranch::connection($ticket,(int)$connection['id']);
            $id=self::save($connection,$event,false);
            $current=self::hydrate(DB::one('SELECT * FROM development_events WHERE id=? FOR UPDATE',[$id]));
            if(\App\Services\GitPullRequest::revision($current)!==$details['revision']) throw new \InvalidArgumentException('Une mise à jour Git plus récente a été reçue. Relancez la synchronisation.');
            if(!DB::one('SELECT 1
                FROM development_event_tickets
                WHERE event_id=? AND ticket_id=?',[$id,$ticket])) throw new \InvalidArgumentException('La pull request n’est plus associée à ce ticket.');
            foreach($commits as $commit) {
                $commitId=self::save($connection,$commit,false);
                DB::run('INSERT IGNORE INTO development_event_tickets(event_id,ticket_id) SELECT ?,ticket_id
                    FROM development_event_tickets
                    WHERE event_id=?',[$commitId,$id]);
                DB::run('UPDATE development_events
                    SET ticket_id=(SELECT MIN(ticket_id) FROM development_event_tickets WHERE event_id=?)
                    WHERE id=?',[$commitId,$commitId]);
            }
            $metadata=$current['metadata'];$metadata['details']=$details;
            DB::run('UPDATE development_events SET metadata_json=? WHERE id=?',[json_encode($metadata,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$id]);
        });
    }

    public static function attachKnownBranch(array $connection,int $ticket,string $branch): void
    {
        $where="e.connection_id=? AND e.kind IN ('commit','pull_request') AND BINARY JSON_UNQUOTE(JSON_EXTRACT(e.metadata_json,'$.branch'))=? AND (e.kind<>'pull_request' OR LOWER(JSON_UNQUOTE(JSON_EXTRACT(e.metadata_json,'$.branch_repository')))=LOWER(?))";
        $args=[$connection['id'],$branch,$connection['repository']];
        DB::run('INSERT IGNORE INTO development_event_tickets(event_id,ticket_id) SELECT e.id,? FROM development_events e WHERE '.$where,[$ticket,...$args]);
        DB::run('UPDATE development_events e
            SET e.ticket_id=(SELECT MIN(l.ticket_id) FROM development_event_tickets l WHERE l.event_id=e.id)
            WHERE '.$where,$args);
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
        $sql='SELECT l.event_id,t.id,t.title,t.number,p.project_key
            FROM development_event_tickets l
            JOIN tickets t ON t.id=l.ticket_id
            JOIN projects p ON p.id=t.project_id
            JOIN project_connections pc ON pc.project_id=p.id
            JOIN development_events e ON e.id=l.event_id AND e.connection_id=pc.connection_id
            WHERE l.event_id IN ('.implode(',',array_fill(0,count($ids),'?')).')';
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
