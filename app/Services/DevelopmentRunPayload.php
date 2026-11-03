<?php
declare(strict_types=1);
namespace App\Services;
/** Only transport-independent run facts are retained; never scripts, variables or log bodies. */
final class DevelopmentRunPayload
{
    private static function string(mixed $value,int $max=500): string {if(!is_string($value) && !is_int($value)) return '';if(mb_strlen((string)$value)>$max) throw new \InvalidArgumentException('Information de pipeline trop longue.');return (string)$value;}
    public static function state(mixed $state): string
    {
        return match(strtolower(self::string($state,100))) {
            'success','successful','passed'=>'success','failure','failed','error','timed_out','startup_failure','action_required'=>'failure',
            'in_progress','running'=>'running','queued','pending','requested'=>'pending','waiting','undeployed'=>'waiting',
            'cancelled','canceled','stopped'=>'cancelled','skipped','neutral'=>'skipped','inactive'=>'inactive',default=>'unknown'};
    }
    public static function object(string $provider,string $type,array $item,string $repository,?array $context=null): array
    {
        if(!in_array($provider,['github','bitbucket'],true)) throw new \InvalidArgumentException('Fournisseur Git inconnu.');
        if($type==='status' && self::string($item['context']??$item['key']??'')==='') throw new \InvalidArgumentException('Contrôle Git sans identité.');
        $github=$provider==='github';$base='https://'.($github?'github.com':'bitbucket.org').'/'.$repository;
        $id=$type==='status'?hash('sha256',self::string($context['sha']??$item['sha']??$item['commit']['hash']??'',100)."\0".self::string($item['context']??$item['key']??'',500)):self::string($item['id']??$item['uuid']??'',100);if($id==='' || !preg_match('/^[a-zA-Z0-9{}_-]+$/D',$id)) throw new \InvalidArgumentException('Exécution Git sans identité valide.');
        $kind='build';$environment=null;$attempt=1;
        if($type==='deployment') {
            $kind='deployment';$status=$github?($context??[]):($item['state']??[]);
            $state=self::state($github?($status['state']??null):(self::string($status['status']['name']??'')?:($status['name']??null)));
            $sha=DevelopmentPayload::sha($github?($item['sha']??null):($item['release']['commit']['hash']??null));
            $environment=self::string($github?($status['environment']??$item['environment']??''):($item['environment']['name']??''),200)?:null;
            $name=$environment??'Environnement non fourni';$branch=$github?self::string($item['ref']??''):'';
            // A hexadecimal ref is a commit identity, not a branch name.
            if(DevelopmentPayload::sha($branch)) $branch='';
            $url=self::string($github?($status['log_url']??$status['target_url']??''):($status['url']??$item['release']['url']??''),1000);
            $date=$github?($status['updated_at']??$status['created_at']??$item['created_at']??null):($status['completion_date']??$status['start_date']??$item['release']['created_on']??null);
        } elseif($type==='workflow') {
            $state=self::state($item['conclusion']??$item['status']??null);$sha=DevelopmentPayload::sha($item['head_sha']??null);$name=self::string($item['name']??'GitHub Actions');$branch=self::string($item['head_branch']??'');$url=self::string($item['html_url']??'',1000);$date=$item['updated_at']??$item['created_at']??null;$attempt=max(1,(int)($item['run_attempt']??1));
        } elseif($type==='pipeline') {
            $state=self::state($item['state']['result']['name']??$item['state']['name']??null);$sha=DevelopmentPayload::sha($item['target']['commit']['hash']??null);$name='Pipeline #'.self::string($item['build_number']??$id);$branch=self::string($item['target']['ref_name']??'');$url=self::string($item['links']['html']['href']??$base.'/pipelines/results/'.rawurlencode($id),1000);$date=$item['completed_on']??$item['created_on']??null;
        } elseif($type==='check') {
            $state=self::state($item['conclusion']??$item['status']??null);$sha=DevelopmentPayload::sha($item['head_sha']??null);$name=self::string($item['name']??'Contrôle');$branch='';$url=self::string($item['details_url']??$item['html_url']??'',1000);$date=$item['completed_at']??$item['started_at']??null;
        } elseif($type==='status') {
            $state=self::state($item['state']??null);$sha=DevelopmentPayload::sha($context['sha']??$item['sha']??$item['commit']['hash']??null);$name=self::string($item['context']??$item['name']??$item['key']??'Contrôle');$branch='';$url=self::string($item['target_url']??$item['url']??'',1000);$date=$item['updated_at']??$item['updated_on']??$item['created_at']??null;
        } else throw new \InvalidArgumentException('Type d’exécution Git inconnu.');
        if(!$sha) throw new \InvalidArgumentException('Exécution Git sans commit identifié.');
        if(isset($context['sha']) && $sha!==$context['sha']) throw new \InvalidArgumentException('Le contrôle concerne un autre commit.');
        $event=DevelopmentPayload::event($kind,$type.':'.$id,$name,$url,$state,$date,$branch?:null,$sha);
        $event['metadata']+=['run_type'=>$type,'environment'=>$environment,'attempt'=>$attempt];
        return $event;
    }
    public static function conflict(string $provider,array $data): ?bool
    {
        if($provider==='github') {
            if(($data['mergeable_state']??null)==='dirty' || (($data['mergeable']??null)===false && in_array($data['mergeable_state']??'',['','unknown'],true))) return true;
            return ($data['mergeable']??null)===true?false:null;
        }
        if(!is_array($data['values']??null) || !array_is_list($data['values'])) throw new \InvalidArgumentException('Vérification de fusion Bitbucket invalide.');
        foreach($data['values'] as $check) if(($check['type']??'')==='git_mergeability_check') return match($check['reason']??'') {'conflicts'=>true,'clean'=>false,default=>null};
        return null;
    }
}
