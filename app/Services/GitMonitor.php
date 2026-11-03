<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\DB;
use App\Models\{DevelopmentEvent,GitJob,TicketBranch};
final class GitMonitor
{
    public static function rows(array $c,string $resource,?string $field=null,int $size=25): array
    {
        $data=Development::request($c,$resource,8);$github=$c['provider']==='github';$rows=$github?($field===null?$data:($data[$field]??null)):($data['values']??null);
        if(!is_array($rows) || !array_is_list($rows) || count($rows)>$size) throw new \InvalidArgumentException('Page de suivi Git invalide.');
        foreach($rows as $row) if(!is_array($row)) throw new \InvalidArgumentException('Observation Git invalide.');
        return [$rows,$github?count($rows)===$size:!empty($data['next'])];
    }
    public static function save(array $c,array $event): int
    {
        $id=DevelopmentEvent::save($c,$event,false);
        if($event['kind']==='deployment' && $event['commit_sha']) GitJob::enqueue((int)$c['id'],'deployment_links','deployment:'.$id,['event_id'=>$id,'after'=>0]);
        return $id;
    }
    public static function conflict(array $event): array
    {
        $check=$event['metadata']['merge_check']??null;
        $fresh=$event['state']==='open' && $check && ($check['revision']??'')===GitPullRequest::revision($event) && ($check['checked_at']??'')>=gmdate('Y-m-d H:i:s',time()-900);
        return ['conflict'=>$fresh?($check['conflict']??null):null,'checked_at'=>$check['checked_at']??null];
    }
    private static function mergeCheck(array $c,array $event,array $raw): ?bool
    {
        $conflict=$event['state']==='open'?($c['provider']==='github'?DevelopmentRunPayload::conflict('github',$raw):DevelopmentRunPayload::conflict('bitbucket',Development::request($c,'pullrequests/'.substr($event['external_key'],3).'/mergeability/checks',8))):null;
        DB::transaction(function() use($c,$event,$conflict) {
            DevelopmentEvent::lockProjects((int)$c['id']);
            $row=DB::one('SELECT * FROM development_events WHERE connection_id=? AND external_key=? FOR UPDATE',[$c['id'],$event['external_key']]);
            if(!$row) return;$current=DevelopmentEvent::hydrate($row);
            if(GitPullRequest::revision($current)!==GitPullRequest::revision($event)) return;
            $meta=$current['metadata'];$meta['merge_check']=['revision'=>GitPullRequest::revision($event),'conflict'=>$conflict,'checked_at'=>gmdate('Y-m-d H:i:s')];
            DB::run('UPDATE development_events SET metadata_json=? WHERE id=?',[json_encode($meta,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$row['id']]);
        });return $conflict;
    }
    public static function pullRequest(array $c,array $p): ?array
    {
        $row=DB::one("SELECT e.* FROM development_events e JOIN development_event_tickets l ON l.event_id=e.id JOIN tickets t ON t.id=l.ticket_id JOIN project_connections pc ON pc.connection_id=e.connection_id AND pc.project_id=t.project_id WHERE e.id=? AND e.connection_id=? AND e.kind='pull_request' LIMIT 1",[$p['event_id'],$c['id']]);
        if(!$row) return null;$stored=DevelopmentEvent::hydrate($row);$number=substr($stored['external_key'],3);
        if(!ctype_digit($number)) throw new \InvalidArgumentException('Identité PR invalide.');
        $raw=Development::request($c,($c['provider']==='github'?'pulls/':'pullrequests/').$number,8);
        $event=DevelopmentPayload::object($c['provider'],'pull_request',$raw,$c['repository']);
        if($event['external_key']!==$stored['external_key']) throw new \InvalidArgumentException('La réponse concerne une autre pull request.');
        self::save($c,$event);$ticket=DB::one('SELECT l.ticket_id FROM development_event_tickets l JOIN tickets t ON t.id=l.ticket_id JOIN project_connections pc ON pc.project_id=t.project_id AND pc.connection_id=? WHERE l.event_id=? LIMIT 1',[$c['id'],$row['id']]);
        if(!$ticket) return null;
        if(($stored['metadata']['details']['revision']??'')!==GitPullRequest::revision($event)) GitPullRequest::sync((int)$ticket['ticket_id'],(int)$row['id'],$raw);
        foreach(array_unique(array_filter([$event['commit_sha'],$event['metadata']['merge_sha']??null])) as $sha) foreach($c['provider']==='github'?['check','status']:['status'] as $phase) GitJob::enqueue((int)$c['id'],'checks',$phase.':'.$sha,['sha'=>$sha,'phase'=>$phase,'page'=>1]);
        $conflict=self::mergeCheck($c,$event,$raw);
        if($event['state']==='open' && $conflict===null && ($p['merge_retry']??0)<3) return ['payload'=>['event_id'=>$row['id'],'merge_retry'=>($p['merge_retry']??0)+1],'delay'=>30];
        return null;
    }
    public static function execute(array $c,array $job): ?array
    {
        $p=$job['payload'];$page=(int)($p['page']??1);$github=$c['provider']==='github';
        if($page<1 || $page>100) throw new \InvalidArgumentException('Le rattrapage dépasse 100 pages. Consultez le fournisseur ou synchronisez une branche ciblée.');
        if($job['kind']==='pull_request') return self::pullRequest($c,$p);
        if($job['kind']==='branch') {
            $link=DB::one('SELECT b.* FROM ticket_branch_links b JOIN tickets t ON t.id=b.ticket_id JOIN project_connections pc ON pc.project_id=t.project_id AND pc.connection_id=b.connection_id WHERE b.connection_id=? AND BINARY b.branch_name=? ORDER BY b.id LIMIT 1',[$c['id'],$p['name']]);
            if(!$link) return null;$result=GitPullRequest::branch((int)$link['ticket_id'],(int)$link['id'],$page);
            foreach($result['pull_requests'] as $id) GitSync::queuePullRequest($c,(int)$id);
            return $result['has_more']?['payload'=>array_replace($p,['page'=>$page+1])]:null;
        }
        if($job['kind']==='repository') {
            $resource=$github?'pulls?state=all&sort=updated&direction=desc&per_page=50&page='.$page:'pullrequests?state=OPEN&state=MERGED&state=DECLINED&state=SUPERSEDED&sort=-updated_on&pagelen=50&page='.$page;
            [$rows,$more]=self::rows($c,$resource,null,50);$oldest=null;
            foreach($rows as $raw) {$event=DevelopmentPayload::object($c['provider'],'pull_request',$raw,$c['repository']);$id=self::save($c,$event);GitSync::queuePullRequest($c,$id);if($event['metadata']['date_known']) $oldest=$event['occurred_at'];}
            if($more && (!$oldest || $oldest>=($p['since']??'1970'))) return ['payload'=>array_replace($p,['page'=>$page+1])];
            return null;
        }
        if($job['kind']==='checks') {
            $sha=DevelopmentPayload::sha($p['sha']??null);if(!$sha) throw new \InvalidArgumentException('Commit de contrôle invalide.');
            $checks=$github && ($p['phase']??'')==='check';
            $resource=$github?'commits/'.$sha.($checks?'/check-runs?per_page=100&filter=latest':'/statuses?per_page=100').'&page='.$page:'commit/'.$sha.'/statuses?pagelen=100&page='.$page;
            [$rows,$more]=self::rows($c,$resource,$checks?'check_runs':null,100);
            foreach($rows as $raw) self::save($c,DevelopmentRunPayload::object($c['provider'],$checks?'check':'status',$raw,$c['repository'],['sha'=>$sha]));
            return $more?['payload'=>array_replace($p,['page'=>$page+1])]:null;
        }
        if($job['kind']==='pipelines') {
            [$rows,$more]=self::rows($c,$github?'actions/runs?per_page=25&page='.$page:'pipelines/?sort=-created_on&pagelen=25&page='.$page,$github?'workflow_runs':null);
            foreach($rows as $raw) self::save($c,DevelopmentRunPayload::object($c['provider'],$github?'workflow':'pipeline',$raw,$c['repository']));
            return $more?['payload'=>array_replace($p,['page'=>$page+1])]:null;
        }
        if($job['kind']==='deployments') {
            [$rows,$more]=self::rows($c,'deployments?'.($github?'per_page':'pagelen').'=25&page='.$page);
            foreach($rows as $raw) {
                if($github) {if(!is_int($raw['id']??null) || $raw['id']<1) throw new \InvalidArgumentException('Déploiement sans identité.');GitJob::enqueue((int)$c['id'],'deployment','deployment:'.$raw['id'],['id'=>$raw['id']]);}
                else self::save($c,DevelopmentRunPayload::object('bitbucket','deployment',$raw,$c['repository']));
            }
            return $more?['payload'=>array_replace($p,['page'=>$page+1])]:null;
        }
        if($job['kind']==='deployment') {
            $id=filter_var($p['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if(!$id || !$github) throw new \InvalidArgumentException('Déploiement GitHub invalide.');
            $raw=Development::request($c,'deployments/'.$id,8);if(($raw['id']??null)!==$id) throw new \InvalidArgumentException('Identité du déploiement différente.');
            [$statuses]=self::rows($c,'deployments/'.$id.'/statuses?per_page=1',null,1);
            self::save($c,DevelopmentRunPayload::object('github','deployment',$raw,$c['repository'],$statuses[0]??[]));return null;
        }
        if($job['kind']==='deployment_links') return self::deploymentLinks($c,$p);
        throw new \InvalidArgumentException('Traitement Git inconnu.');
    }
    private static function deploymentLinks(array $c,array $p): ?array
    {
        $deployment=DB::one("SELECT * FROM development_events WHERE id=? AND connection_id=? AND kind='deployment'",[$p['event_id'],$c['id']]);if(!$deployment || !$deployment['commit_sha']) return null;
        $rows=DB::all("SELECT DISTINCT e.id,JSON_UNQUOTE(JSON_EXTRACT(e.metadata_json,'$.merge_sha')) AS merge_sha FROM development_events e JOIN development_event_tickets l ON l.event_id=e.id JOIN tickets t ON t.id=l.ticket_id JOIN project_connections pc ON pc.project_id=t.project_id AND pc.connection_id=e.connection_id WHERE e.connection_id=? AND e.kind='pull_request' AND e.state='merged' AND e.id>? ORDER BY e.id LIMIT 5",[$c['id'],$p['after']??0]);
        foreach($rows as $row) {
            $base=DevelopmentPayload::sha($row['merge_sha']);if(!$base) continue;$head=$deployment['commit_sha'];
            if($base===$head) $contains=true;
            elseif($c['provider']==='github') {$comparison=Development::request($c,'compare/'.$base.'...'.$head,8);$contains=($comparison['merge_base_commit']['sha']??null)===$base && in_array($comparison['status']??'',['ahead','identical'],true);}
            else {$comparison=Development::request($c,'merge-base/'.$base.'..'.$head,8);$contains=($comparison['hash']??null)===$base;}
            if($contains) DevelopmentEvent::deploymentProof($c,(int)$deployment['id'],(int)$row['id'],$base,$head);
        }
        return count($rows)===5?['payload'=>['event_id'=>$deployment['id'],'after'=>$rows[array_key_last($rows)]['id']]]:null;
    }
}
