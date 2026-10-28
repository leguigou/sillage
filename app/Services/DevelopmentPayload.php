<?php
declare(strict_types=1);
namespace App\Services;

/** Provider payloads are interpreted once, before persistence or automation. */
final class DevelopmentPayload
{
    public const KINDS = ['branch'=>'Branches','commit'=>'Commits','pull_request'=>'Pull requests'];
    public const STATES = ['active'=>'Active','deleted'=>'Supprimée','committed'=>'Enregistré','open'=>'Ouverte','merged'=>'Fusionnée','closed'=>'Fermée','declined'=>'Refusée','unknown'=>'État non fourni'];

    private static function text(mixed $value, int $max = 1000): string
    {
        if (!is_string($value) && !is_int($value)) return '';
        if (mb_strlen((string)$value)>$max) throw new \InvalidArgumentException('Donnée de développement trop longue.');
        return (string)$value;
    }
    private static function sha(mixed $sha): ?string
    {
        return is_string($sha) && preg_match('/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/iD',$sha) && trim($sha,'0')!=='' ? strtolower($sha) : null;
    }
    private static function event(string $kind,string $key,string $title,string $url,string $state,mixed $date,?string $branch=null,?string $sha=null,?string $trigger=null): array
    {
        if ($key==='' || strlen($key)>190 || !isset(self::KINDS[$kind])) throw new \InvalidArgumentException('Identité de développement invalide.');
        $known = is_string($date) && (bool)preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}/D',$date);
        try { $time=$known ? new \DateTimeImmutable($date) : new \DateTimeImmutable('now',new \DateTimeZone('UTC')); }
        catch (\Exception) { $known=false; $time=new \DateTimeImmutable('now',new \DateTimeZone('UTC')); }
        return ['external_key'=>$key,'kind'=>$kind,'title'=>mb_substr($title?:$key,0,500),'external_url'=>$url,'state'=>$state,
            'occurred_at'=>$time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),'commit_sha'=>$sha,
            'metadata'=>['branch'=>$branch,'date_known'=>$known]+(mb_strlen($title)>500?['full_title'=>$title]:[]), 'trigger'=>$trigger,'references'=>$title."\n".$branch];
    }
    public static function object(string $provider,string $kind,array $item,string $repository,bool $live=false,?string $branch=null,?string $action=null): array
    {
        if (!in_array($provider,['github','bitbucket'],true)) throw new \InvalidArgumentException('Fournisseur inconnu.');
        $github=$provider==='github'; $base='https://'.($github?'github.com':'bitbucket.org').'/'.$repository;
        if ($kind==='branch') {
            $name=self::text($item['name']??$branch);
            if ($name==='') throw new \InvalidArgumentException('Branche sans nom.');
            $state=($item['deleted']??false)?'deleted':'active';
            return self::event('branch','branch:'.hash('sha256',$name),$name,self::text($item['links']['html']['href']??($base.($github?'/tree/':'/src/').rawurlencode($name))),$state,$item['date']??null,$name,self::sha($item['commit']['sha']??$item['target']['hash']??$item['sha']??null),$live&&($item['created']??false)?'development.branch_created':null);
        }
        if ($kind==='commit') {
            $sha=self::sha($item['sha']??$item['hash']??$item['id']??null);
            if (!$sha) throw new \InvalidArgumentException('Commit sans empreinte valide.');
            $message=self::text($item['commit']['message']??$item['message']??'',100000);
            return self::event('commit','commit:'.$sha,$message,self::text($item['html_url']??$item['links']['html']['href']??$item['url']??($base.($github?'/commit/':'/commits/').$sha)),'committed',$item['commit']['author']['date']??$item['timestamp']??$item['date']??null,$branch,$sha,$live?'development.commit_pushed':null);
        }
        if ($kind==='pull_request') {
            $id=self::text($item['number']??$item['id']??'',100);
            if (!ctype_digit($id) || (int)$id<1) throw new \InvalidArgumentException('Pull request sans identifiant.');
            $state=(!empty($item['merged'])||!empty($item['merged_at'])||strtoupper(self::text($item['state']??''))==='MERGED')?'merged':match(strtolower(self::text($item['state']??''))){'open'=>'open','closed','superseded'=>'closed','declined'=>'declined',default=>'unknown'};
            $branch=self::text($item['head']['ref']??$item['source']['branch']['name']??'')?:null;
            $trigger=$live && $state==='merged' ? 'development.pull_request_merged' : ($live && in_array($action,['opened','reopened','pullrequest:created'],true)?'development.pull_request_opened':null);
            return self::event('pull_request','pr:'.$id,self::text($item['title']??''),self::text($item['html_url']??$item['links']['html']['href']??''),$state,$item['updated_at']??$item['updated_on']??null,$branch,self::sha($item['head']['sha']??$item['source']['commit']['hash']??null),$trigger);
        }
        throw new \InvalidArgumentException('Type de suivi inconnu.');
    }

    public static function webhook(string $provider,string $event,array $data,string $repository): array
    {
        $events=[];
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
