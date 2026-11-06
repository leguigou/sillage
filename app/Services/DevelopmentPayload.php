<?php
declare(strict_types=1);
namespace App\Services;

/**
 * Provider payloads are interpreted once, before persistence or automation.
 * Converts raw GitHub/Bitbucket JSON (API responses and webhook bodies) for branches, commits and pull
 * requests into one normalised "development event" array (external_key, kind, title, url, state,
 * occurred_at in UTC, commit_sha, metadata, references, trigger). It owns branch name validation, branch
 * keys and SHA normalisation; matching events to tickets belongs to the DevelopmentEvent model.
 * CI and deployment payloads are delegated to DevelopmentRunPayload. No I/O happens here.
 */
final class DevelopmentPayload
{
    public const KINDS = ['branch'=>'Branches','commit'=>'Commits','pull_request'=>'Pull requests','build'=>'Tests et pipelines','deployment'=>'Déploiements'];
    public const STATES = ['active'=>'Active','deleted'=>'Supprimée','committed'=>'Enregistré','open'=>'Ouverte','merged'=>'Fusionnée','closed'=>'Fermée','declined'=>'Refusée','unknown'=>'État non fourni','success'=>'Réussi','failure'=>'Échec','pending'=>'En attente','waiting'=>'En attente de lancement','running'=>'En cours','cancelled'=>'Annulé','skipped'=>'Ignoré','inactive'=>'Inactif'];
    /** Stable external key for a branch; hashed so any valid name fits the key column. */
    public static function branchKey(string $name): string {return 'branch:'.hash('sha256',$name);}

    /** Validates a short branch name against Git ref-name rules (no control chars, "..", "@{", ".lock", etc.). */
    public static function branchName(mixed $name): string
    {
        if (!is_string($name) || $name==='' || mb_strlen($name)>1000 || preg_match('/[\x00-\x20\x7f~^:?*\[\\\\]/u',$name) || str_contains($name,'..') || str_contains($name,'@{') || str_contains($name,'//') || str_starts_with($name,'/') || str_ends_with($name,'/') || str_ends_with($name,'.') || $name==='@') throw new \InvalidArgumentException('Indiquez le nom exact d’une branche Git valide, sans URL ni préfixe refs/heads/.');
        foreach(explode('/',$name) as $part) if (str_starts_with($part,'.') || str_ends_with($part,'.lock')) throw new \InvalidArgumentException('Nom de branche Git invalide.');
        if (str_starts_with($name,'refs/heads/')) throw new \InvalidArgumentException('Indiquez le nom de branche sans le préfixe refs/heads/.');
        return $name;
    }

    private static function text(mixed $value, int $max = 1000): string
    {
        if (!is_string($value) && !is_int($value)) return '';
        if (mb_strlen((string)$value)>$max) throw new \InvalidArgumentException('Donnée de développement trop longue.');
        return (string)$value;
    }
    /** Lower-cased SHA-1 (40) or SHA-256 (64) hex id, or null when invalid or all zeros (Git's "no commit"). */
    public static function sha(mixed $sha): ?string
    {
        return is_string($sha) && preg_match('/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/iD',$sha) && trim($sha,'0')!=='' ? strtolower($sha) : null;
    }
    /**
     * Builds the normalised event array. A missing or unparsable date is replaced by "now" and flagged
     * with metadata.date_known=false so the UI does not present it as a real event time.
     * Titles over 500 characters are truncated, with the full text kept in metadata.full_title.
     */
    public static function event(string $kind,string $key,string $title,string $url,string $state,mixed $date,?string $branch=null,?string $sha=null,?string $trigger=null): array
    {
        if ($key==='' || strlen($key)>190 || !isset(self::KINDS[$kind])) throw new \InvalidArgumentException('Identité de développement invalide.');
        $known = is_string($date) && (bool)preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}/D',$date);
        try { $time=$known ? new \DateTimeImmutable($date) : new \DateTimeImmutable('now',new \DateTimeZone('UTC')); }
        catch (\Exception) { $known=false; $time=new \DateTimeImmutable('now',new \DateTimeZone('UTC')); }
        $event=['external_key'=>$key,'kind'=>$kind,'title'=>mb_substr($title?:$key,0,500),'external_url'=>$url,'state'=>$state,
            'occurred_at'=>$time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),'commit_sha'=>$sha,
            'metadata'=>['branch'=>$branch,'date_known'=>$known]+(mb_strlen($title)>500?['full_title'=>$title]:[]), 'trigger'=>$trigger];
        return $event;
    }
    /** Full title, source branch and normalized description keys used for ticket matching. */
    public static function references(array $event): string
    {
        $metadata=$event['metadata'];
        return implode("\n", [
            $metadata['full_title']??$event['title'],
            $metadata['branch']??'',
            ...($metadata['description_keys']??[]),
        ]);
    }
    /**
     * Normalises one branch, commit or pull request item. Field names differ between providers, hence the
     * "github ?? bitbucket" fallbacks. $live marks webhook data: only then is an automation trigger set
     * (branch created, commit pushed, PR opened or merged), so a catch-up sync never replays automations.
     */
    public static function object(string $provider,string $kind,array $item,string $repository,bool $live=false,?string $branch=null,?string $action=null): array
    {
        if (!in_array($provider,['github','bitbucket'],true)) throw new \InvalidArgumentException('Fournisseur inconnu.');
        $github=$provider==='github'; $base='https://'.($github?'github.com':'bitbucket.org').'/'.$repository;
        if ($kind==='branch') {
            $name=self::text($item['name']??$branch);
            if ($name==='') throw new \InvalidArgumentException('Branche sans nom.');
            $state=($item['deleted']??false)?'deleted':'active';
            return self::event('branch',self::branchKey($name),$name,self::text($item['links']['html']['href']??($base.($github?'/tree/':'/src/').rawurlencode($name))),$state,$item['date']??null,$name,self::sha($item['commit']['sha']??$item['target']['hash']??$item['sha']??null),$live&&($item['created']??false)?'development.branch_created':null);
        }
        if ($kind==='commit') {
            $sha=self::sha($item['sha']??$item['hash']??$item['id']??null);
            if (!$sha) throw new \InvalidArgumentException('Commit sans empreinte valide.');
            $message=self::text($item['commit']['message']??$item['message']??'',100000);
            $event=self::event('commit','commit:'.$sha,$message,self::text($item['html_url']??$item['links']['html']['href']??$item['url']??($base.($github?'/commit/':'/commits/').$sha)),'committed',$item['commit']['author']['date']??$item['timestamp']??$item['date']??null,$branch,$sha,$live?'development.commit_pushed':null);
            $event['metadata']['author']=self::text($item['commit']['author']['name']??$item['author']['user']['display_name']??$item['author']['raw']??$item['author']['name']??'',500)?:null;return $event;
        }
        if ($kind==='pull_request') {
            $id=self::text($item['number']??$item['id']??'',100);
            if (!ctype_digit($id) || (int)$id<1) throw new \InvalidArgumentException('Pull request sans identifiant.');
            $state=(!empty($item['merged'])||!empty($item['merged_at'])||strtoupper(self::text($item['state']??''))==='MERGED')?'merged':match(strtolower(self::text($item['state']??''))){'open'=>'open','closed','superseded'=>'closed','declined'=>'declined',default=>'unknown'};
            $branch=self::text($item['head']['ref']??$item['source']['branch']['name']??'')?:null;
            $trigger=$live && $state==='merged' ? 'development.pull_request_merged' : ($live && in_array($action,['opened','reopened','pullrequest:created'],true)?'development.pull_request_opened':null);
            $event=self::event('pull_request','pr:'.$id,self::text($item['title']??''),self::text($item['html_url']??$item['links']['html']['href']??''),$state,$item['updated_at']??$item['updated_on']??null,$branch,self::sha($item['head']['sha']??$item['source']['commit']['hash']??null),$trigger);
            $event['metadata']['branch_repository']=self::text($item['head']['repo']['full_name']??$item['source']['repository']['full_name']??'',300)?:null;
            $event['metadata']['target_branch']=self::text($item['base']['ref']??$item['destination']['branch']['name']??'')?:null;
            $event['metadata']['target_sha']=self::sha($item['base']['sha']??$item['destination']['commit']['hash']??null);
            // Bitbucket list payloads carry no mergeability; it is checked separately (GitMonitor).
            $event['metadata']['merge_conflict']=$github?DevelopmentRunPayload::conflict('github',$item):null;
            $event['metadata']['merge_sha']=self::sha($item['merge_commit_sha']??$item['merge_commit']['hash']??null);
            $descriptionField=$github?'body':'description';
            if (array_key_exists($descriptionField,$item) && (is_string($item[$descriptionField]) || $item[$descriptionField]===null)) {
                preg_match_all('/\b[A-Z][A-Z0-9]{1,9}-[1-9][0-9]*\b/i', $item[$descriptionField]??'', $matches);
                $event['metadata']['description_keys']=array_values(array_unique(array_map('strtoupper',$matches[0])));
            }
            return $event;
        }
        throw new \InvalidArgumentException('Type de suivi inconnu.');
    }

    /**
     * Expands one webhook delivery ($event = GitHub X-GitHub-Event or Bitbucket X-Event-Key value) into a
     * list of normalised events: CI runs/statuses/deployments, the pull request, and branch/commit events
     * from pushes. Oversized pushes are rejected and must be caught up with a manual sync.
     */
    public static function webhook(string $provider,string $event,array $data,string $repository): array
    {
        $events=[];
        if($provider==='github') {
            foreach(['workflow_run'=>'workflow','check_run'=>'check'] as $key=>$type) if($event===$key && is_array($data[$key]??null)) $events[]=DevelopmentRunPayload::object($provider,$type,$data[$key],$repository);
            if($event==='status') $events[]=DevelopmentRunPayload::object($provider,'status',$data,$repository);
            if($event==='deployment_status' && is_array($data['deployment']??null) && is_array($data['deployment_status']??null)) $events[]=DevelopmentRunPayload::object($provider,'deployment',$data['deployment'],$repository,$data['deployment_status']);
        } else {
            if(in_array($event,['repo:commit_status_created','repo:commit_status_updated'],true) && is_array($data['commit_status']??null)) $events[]=DevelopmentRunPayload::object($provider,'status',$data['commit_status'],$repository,['sha'=>$data['commit']['hash']??$data['commit_status']['commit']['hash']??null]);
            if(str_starts_with($event,'pipeline:') && is_array($data['pipeline']??null)) $events[]=DevelopmentRunPayload::object($provider,'pipeline',$data['pipeline'],$repository);
            if(str_starts_with($event,'deployment:') && is_array($data['deployment']??null)) $events[]=DevelopmentRunPayload::object($provider,'deployment',$data['deployment'],$repository);
        }
        if ($pr=$data['pull_request']??$data['pullrequest']??null) {
            if (!is_array($pr)) throw new \InvalidArgumentException('Pull request invalide.');
            $events[]=self::object($provider,'pull_request',$pr,$repository,true,null,$data['action']??$event);
        }
        if ($provider==='github') {
            if (($data['ref_type']??'')==='branch' && in_array($event,['create','delete'],true)) $events[]=self::object($provider,'branch',['name'=>$data['ref']??'', 'created'=>$event==='create','deleted'=>$event==='delete'],$repository,true);
            if (str_starts_with(self::text($data['ref']??''),'refs/heads/')) {
                $branch=substr($data['ref'],11);
                $events[]=self::object($provider,'branch',['name'=>$branch,'sha'=>$data['after']??null,'created'=>$data['created']??false,'deleted'=>$data['deleted']??false,'date'=>$data['head_commit']['timestamp']??null],$repository,true);
                if (!is_array($data['commits']??[]) || count($data['commits']??[])>200) throw new \InvalidArgumentException('Push invalide ou trop volumineux : synchronisez les commits depuis la page Développement.');
                foreach($data['commits']??[] as $commit) {
                    if (!is_array($commit)) throw new \InvalidArgumentException('Commit invalide.');
                    $events[]=self::object($provider,'commit',$commit,$repository,true,$branch);
                }
            }
        } else {
            if (!is_array($data['push']['changes']??[]) || count($data['push']['changes']??[])>50) throw new \InvalidArgumentException('Push invalide ou trop de branches.');
            foreach($data['push']['changes']??[] as $change) {
                if (!is_array($change)) throw new \InvalidArgumentException('Changement de branche invalide.');
                $ref=$change['new']??$change['old']??[];
                $branch=($ref['type']??'')==='branch'?self::text($ref['name']??''):null;
                if ($branch) $events[]=self::object($provider,'branch',$ref+['created'=>empty($change['old']),'deleted'=>empty($change['new'])],$repository,true);
                if (!is_array($change['commits']??[]) || count($change['commits']??[])>200) throw new \InvalidArgumentException('Push invalide ou trop volumineux : synchronisez les commits.');
                foreach($change['commits']??[] as $commit) {
                    if (!is_array($commit)) throw new \InvalidArgumentException('Commit invalide.');
                    $events[]=self::object($provider,'commit',$commit,$repository,true,$branch);
                }
            }
        }
        return $events;
    }
}
