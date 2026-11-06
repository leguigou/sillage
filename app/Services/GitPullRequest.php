<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\DB;
use App\Models\{DevelopmentEvent,TicketBranch};

/** Reads exact PR membership, never the entire ancestry of a shared branch. */
final class GitPullRequest
{
    public static function revision(array $event): string
    {
        return hash('sha256',json_encode([$event['external_key'],$event['state'],$event['commit_sha'],$event['metadata']['branch']??null,$event['metadata']['branch_repository']??null,$event['metadata']['target_branch']??null,$event['metadata']['target_sha']??null,$event['metadata']['merge_sha']??null,($event['metadata']['date_known']??true)?$event['occurred_at']:null],JSON_THROW_ON_ERROR));
    }
    public static function file(string $provider,array $item): array
    {
        $github=$provider==='github';
        $path=$github?($item['filename']??null):($item['new']['path']??$item['old']['path']??null);
        $old=$github?($item['previous_filename']??null):($item['old']['path']??null);
        if(!is_string($path) || $path==='' || mb_strlen($path)>4096 || preg_match('/[\x00-\x1f\x7f]/',$path)) throw new \InvalidArgumentException('Chemin de fichier invalide dans les statistiques Git.');
        if($old!==null && (!is_string($old) || mb_strlen($old)>4096 || preg_match('/[\x00-\x1f\x7f]/',$old))) throw new \InvalidArgumentException('Ancien chemin de fichier invalide.');
        $counts=[];
        foreach([$github?'additions':'lines_added',$github?'deletions':'lines_removed'] as $key) {
            $value=$item[$key]??null;
            if($value!==null && (!is_int($value) || $value<0 || $value>2147483647)) throw new \InvalidArgumentException('Nombre de lignes invalide.');
            $counts[]=$value;
        }
        $status=$item['status']??'unknown';
        $labels=['added'=>'Ajouté','removed'=>'Supprimé','deleted'=>'Supprimé','modified'=>'Modifié','renamed'=>'Renommé','copied'=>'Copié','unchanged'=>'Inchangé'];
        return ['path'=>$path,'previous_path'=>$old!==$path?$old:null,'status'=>$labels[is_string($status)?$status:'unknown']??'Non précisé','additions'=>$counts[0],'deletions'=>$counts[1]];
    }
    /** A PR diffstat may redirect only to the same repository's statistics endpoint. */
    public static function diffstatResource(string $url,string $repository,int $page): string
    {
        $prefix='/2.0/repositories/'.$repository.'/diffstat/';
        // Bitbucket revspecs can contain a percent-encoded CR between revisions.
        // Validate the URL boundary before decoding, then re-encode each path segment.
        // An encoded separator in an opaque revspec must never become a literal HTTP control.
        if(strlen($url)>8192 || preg_match('/[\x00-\x20\x7f]/',$url)) throw new \InvalidArgumentException('Redirection des statistiques Bitbucket refusée.');
        $parts=parse_url($url);$path=$parts['path']??'';
        if(!$parts || ($parts['scheme']??'')!=='https' || ($parts['host']??'')!=='api.bitbucket.org' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['fragment']) || !str_starts_with($path,$prefix) || strlen($path)>2048) throw new \InvalidArgumentException('Redirection des statistiques Bitbucket refusée.');
        $spec=substr($path,strlen($prefix));if($spec==='') throw new \InvalidArgumentException('Comparaison Bitbucket sans référence.');
        $segments=[];
        foreach(explode('/',$spec) as $segment) {
            if(preg_match('/%(?![0-9a-f]{2})/i',$segment)) throw new \InvalidArgumentException('Référence de comparaison Bitbucket invalide.');
            $decoded=rawurldecode($segment);
            if($decoded==='' || in_array($decoded,['.','..'],true) || preg_match('~[\x00-\x0c\x0e-\x20\x7f\\\\/?#%]~',$decoded)) throw new \InvalidArgumentException('Référence de comparaison Bitbucket invalide.');
            $segments[]=rawurlencode($decoded);
        }
        $spec=implode('/',$segments);
        parse_str($parts['query']??'',$query);$safe=[];
        foreach($query as $key=>$value) {
            if(in_array($key,['page','pagelen'],true)) continue;
            if(!in_array($key,['topic','merge','from_pullrequest_id','ignore_whitespace','renames'],true) || !is_string($value) || !preg_match('/^(?:true|false|[0-9]+)$/D',$value)) throw new \InvalidArgumentException('Paramètres de statistiques Bitbucket inattendus.');
            $safe[$key]=$value;
        }
        return 'diffstat/'.$spec.'?'.http_build_query($safe+['pagelen'=>100,'page'=>$page]);
    }
    public static function eventForTicket(int $ticket,int $event): array
    {
        $row=DB::one("SELECT e.*
            FROM development_events e
            JOIN development_event_tickets l ON l.event_id=e.id
            JOIN tickets t ON t.id=l.ticket_id
            JOIN project_connections pc ON pc.project_id=t.project_id AND pc.connection_id=e.connection_id
            WHERE l.ticket_id=? AND e.id=? AND e.kind='pull_request'",[$ticket,$event]);
        if(!$row) throw new \InvalidArgumentException('Cette pull request n’est pas liée au projet et au ticket.');
        return DevelopmentEvent::hydrate($row);
    }
    public static function commitResource(string $url,string $repository,int $pr): string
    {
        $parts=parse_url($url);$expected='/2.0/repositories/'.$repository.'/pullrequests/'.$pr.'/commits';
        if(!$parts || ($parts['scheme']??'')!=='https' || ($parts['host']??'')!=='api.bitbucket.org' || ($parts['path']??'')!==$expected || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['fragment']) || empty($parts['query']) || strlen($url)>4096 || preg_match('/[\x00-\x20\x7f]/',$url)) throw new \InvalidArgumentException('Pagination des commits Bitbucket refusée.');
        return 'pullrequests/'.$pr.'/commits?'.$parts['query'];
    }
    private static function page(array $connection,string $resource,int $page,int $size,float $started,bool $files=false,int $pr=0): array
    {
        $left=20-(microtime(true)-$started);
        if($left<1) throw new \InvalidArgumentException('Synchronisation trop longue. Les dernières statistiques complètes sont conservées ; réessayez.');
        $data=$files?Development::pullRequestFiles($connection,$pr,$page,min(8,(int)ceil($left))):Development::request($connection,$resource,min(8,(int)ceil($left)));
        $github=$connection['provider']==='github';$rows=$github?$data:($data['values']??null);
        if(!is_array($rows) || !array_is_list($rows) || count($rows)>$size) throw new \InvalidArgumentException('Page Git invalide.');
        if(!$github && isset($data['next']) && !is_string($data['next'])) throw new \InvalidArgumentException('Pagination Git invalide.');
        return [$rows,$github?count($rows)===$size:!empty($data['next']),$github?null:($data['next']??null)];
    }
    public static function branch(int $ticket,int $link,int $page): array
    {
        if($page<1 || $page>10000) throw new \InvalidArgumentException('Page de pull requests invalide.');
        $branch=DB::one('SELECT * FROM ticket_branch_links WHERE id=? AND ticket_id=?',[$link,$ticket]);
        if(!$branch) throw new \InvalidArgumentException('Ce lien de branche n’existe plus.');
        $c=TicketBranch::connection($ticket,(int)$branch['connection_id']);$github=$c['provider']==='github';$name=$branch['branch_name'];
        $resource=$github?'pulls?'.http_build_query(['state'=>'all','head'=>explode('/',$c['repository'])[0].':'.$name,'sort'=>'updated','direction'=>'desc','per_page'=>20,'page'=>$page]):'pullrequests?'.http_build_query(['q'=>'source.branch.name = '.json_encode($name,JSON_THROW_ON_ERROR),'sort'=>'-updated_on','pagelen'=>20,'page'=>$page]).'&state=OPEN&state=MERGED&state=DECLINED&state=SUPERSEDED';
        [$rows,$more]=self::page($c,$resource,$page,20,microtime(true));$events=[];
        foreach($rows as $row) {
            if(!is_array($row)) throw new \InvalidArgumentException('Pull request invalide.');
            $event=DevelopmentPayload::object($c['provider'],'pull_request',$row,$c['repository']);
            // Provider filters are not authority: forks and case differences are checked again here.
            if(($event['metadata']['branch']??null)===$name && strcasecmp((string)($event['metadata']['branch_repository']??''),$c['repository'])===0) $events[]=$event;
        }
        $ids=DB::transaction(function() use($c,$ticket,$link,$events) {
            DevelopmentEvent::lockProjects((int)$c['id']);TicketBranch::connection($ticket,(int)$c['id']);$ids=[];
            if(!DB::one('SELECT 1
                FROM ticket_branch_links
                WHERE id=? AND ticket_id=?',[$link,$ticket])) throw new \InvalidArgumentException('Le lien de branche a été retiré pendant la synchronisation.');
            foreach($events as $event) $ids[]=DevelopmentEvent::save($c,$event,false);return $ids;
        });
        return ['ok'=>true,'pull_requests'=>$ids,'has_more'=>$more,'next_page'=>$page+1,'scanned'=>count($rows)];
    }
    public static function sync(int $ticket,int $eventId,?array $initial=null): array
    {
        if(PHP_SAPI!=='cli' && function_exists('set_time_limit')) @set_time_limit(30);
        $stored=self::eventForTicket($ticket,$eventId);$c=TicketBranch::connection($ticket,(int)$stored['connection_id']);$github=$c['provider']==='github';
        $number=substr($stored['external_key'],3);if(!ctype_digit($number)) throw new \InvalidArgumentException('Identifiant de pull request invalide.');
        $started=microtime(true);$base=($github?'pulls/':'pullrequests/').$number;
        $raw=$initial??Development::request($c,$base,8);$event=DevelopmentPayload::object($c['provider'],'pull_request',$raw,$c['repository']);
        if($event['external_key']!==$stored['external_key']) throw new \InvalidArgumentException('Le fournisseur a renvoyé une autre pull request.');
        $commits=[];$commitsComplete=false;$retainedBytes=0;
        // GitHub's PR API is limited to 250 commits. Never claim that a larger PR is complete.
        $expected=$github?($raw['commits']??null):null;
        if($expected!==null && (!is_int($expected) || $expected<0)) throw new \InvalidArgumentException('Nombre de commits Git invalide.');
        $commitResource=$base.'/commits?pagelen=100';$seenCommitPages=[];
        for($page=1;$page<=10;$page++) {
            if($github) $commitResource=$base.'/commits?per_page=100&page='.$page;
            if(isset($seenCommitPages[$commitResource])) throw new \InvalidArgumentException('Le fournisseur répète une page de commits.');
            $seenCommitPages[$commitResource]=true;
            [$rows,$more,$next]=self::page($c,$commitResource,$page,100,$started);
            foreach($rows as $row) {
                if(!is_array($row)) throw new \InvalidArgumentException('Commit de pull request invalide.');
                $commit=DevelopmentPayload::object($c['provider'],'commit',$row,$c['repository'],false,strcasecmp((string)($event['metadata']['branch_repository']??''),$c['repository'])===0?$event['metadata']['branch']:null);
                if(isset($commits[$commit['external_key']])) throw new \InvalidArgumentException('Le fournisseur répète un commit dans la pagination. Réessayez.');
                $retainedBytes+=strlen(json_encode($commit,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
                if($retainedBytes>4*1024*1024) throw new \InvalidArgumentException('Les détails de cette pull request sont trop volumineux. Consultez le fournisseur.');
                $commits[$commit['external_key']]=$commit;
            }
            if(!$more) {$commitsComplete=$expected===null || count($commits)===$expected;break;}
            if(!$github) $commitResource=self::commitResource($next,$c['repository'],(int)$number);
        }
        $files=[];$filesComplete=false;
        for($page=1;$page<=10;$page++) {
            [$rows,$more]=self::page($c,'',$page,100,$started,true,(int)$number);
            foreach($rows as $row) {if(!is_array($row)) throw new \InvalidArgumentException('Statistiques de fichier invalides.');$file=self::file($c['provider'],$row);if(isset($files[$file['path']])) throw new \InvalidArgumentException('Le fournisseur répète un fichier dans la pagination. Réessayez.');$retainedBytes+=strlen(json_encode($file,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));if($retainedBytes>4*1024*1024) throw new \InvalidArgumentException('Les détails de cette pull request sont trop volumineux. Consultez le fournisseur.');$files[$file['path']]=$file;}
            if($github && ($raw['changed_files']??null)===count($files)) {$filesComplete=true;break;}
            if(!$more) {$filesComplete=true;break;}
        }
        if(!$filesComplete) throw new \InvalidArgumentException('Plus de 1 000 fichiers : les dernières statistiques complètes sont conservées. Consultez le fournisseur.');
        if($github && isset($raw['changed_files']) && (!is_int($raw['changed_files']) || count($files)!==$raw['changed_files'])) throw new \InvalidArgumentException('La liste des fichiers est incomplète. Réessayez après actualisation de la pull request.');
        // Detect source/target movement during multi-page collection before replacing a complete snapshot.
        $latest=DevelopmentPayload::object($c['provider'],'pull_request',Development::request($c,$base,8),$c['repository']);
        if(self::revision($latest)!==self::revision($event)) throw new \InvalidArgumentException('La pull request a changé pendant la synchronisation. Réessayez.');
        $added=0;$deleted=0;$known=true;
        foreach($files as $file) {if($file['additions']===null || $file['deletions']===null) $known=false;$added+=$file['additions']??0;$deleted+=$file['deletions']??0;}
        $details=['revision'=>self::revision($event),'files'=>array_values($files),'file_count'=>count($files),'additions'=>$known?$added:null,'deletions'=>$known?$deleted:null,'commit_shas'=>array_column($commits,'commit_sha'),'commit_count'=>count($commits),'commits_complete'=>$commitsComplete,'fetched_at'=>gmdate('Y-m-d H:i:s')];
        DevelopmentEvent::savePullRequestDetails($c,$event,$commits,$details,$ticket);
        return ['ok'=>true,'commits'=>count($commits),'files'=>count($files),'commits_complete'=>$commitsComplete];
    }
}
