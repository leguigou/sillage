<?php
declare(strict_types=1);
namespace App\Models;
use App\Core\DB;
final class GitJob
{
    public const KINDS=['repository','branch','pull_request','pipelines','deployments','deployment','checks','deployment_links'];
    public const LABELS=['repository'=>'Pull requests récentes','branch'=>'Historique de branche','pull_request'=>'Détails et fusion de PR','pipelines'=>'Pipelines','deployments'=>'Environnements','deployment'=>'État du déploiement','checks'=>'Tests et contrôles','deployment_links'=>'Tickets du déploiement'];
    public const STATES=['pending'=>'En attente / nouvelle tentative','running'=>'En cours','error'=>'À relancer','idle'=>'Terminé'];
    public static function enqueue(int $connection,string $kind,string $target,array $payload=[]): void
    {
        if(!in_array($kind,self::KINDS,true) || !preg_match('/^[a-zA-Z0-9:_-]{1,190}$/D',$target)) throw new \InvalidArgumentException('Tâche Git invalide.');
        if(strlen(json_encode($payload,JSON_THROW_ON_ERROR))>16000) throw new \InvalidArgumentException('Tâche Git trop volumineuse.');
        DB::run("INSERT INTO git_sync_jobs(connection_id,kind,target,payload_json) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE payload_json=VALUES(payload_json),requested_seq=requested_seq+1,attempts=IF(state='running',attempts,0),available_at=IF(state='running',available_at,UTC_TIMESTAMP()),last_error=IF(state='running',last_error,NULL),state=IF(state='running','running','pending'),updated_at=UTC_TIMESTAMP()",[$connection,$kind,$target,json_encode($payload,JSON_THROW_ON_ERROR)]);
    }
    public static function claim(): ?array
    {
        return DB::transaction(function() {
            $row=DB::one("SELECT j.* FROM git_sync_jobs j JOIN project_connections pc ON pc.connection_id=j.connection_id LEFT JOIN git_sync_state s ON s.connection_id=j.connection_id WHERE COALESCE(s.enabled,1)=1 AND ((j.state='pending' AND j.available_at<=UTC_TIMESTAMP()) OR (j.state='running' AND j.lease_until<UTC_TIMESTAMP())) ORDER BY j.available_at,j.id LIMIT 1 FOR UPDATE SKIP LOCKED");
            if(!$row) return null;
            $row['lease_owner']=bin2hex(random_bytes(16));
            $row['request_id']=bin2hex(random_bytes(16));
            DB::run("UPDATE git_sync_jobs SET state='running',lease_owner=?,request_id=?,lease_until=UTC_TIMESTAMP()+INTERVAL 120 SECOND WHERE id=?",[$row['lease_owner'],$row['request_id'],$row['id']]);
            $row['payload']=json_decode($row['payload_json'],true,32,JSON_THROW_ON_ERROR);return $row;
        });
    }
    /** Completion is fenced by owner and generation; a concurrent webhook cannot be lost. */
    public static function finish(array $job,?array $continuation=null,?string $error=null,int $wait=0): void
    {
        DB::transaction(function() use($job,$continuation,$error,$wait) {
            $row=DB::one('SELECT * FROM git_sync_jobs WHERE id=? AND lease_owner=? AND lease_until>=UTC_TIMESTAMP() FOR UPDATE',[$job['id'],$job['lease_owner']]);
            if(!$row) return;
            $newer=(int)$row['requested_seq']!==(int)$job['requested_seq'];$attempts=$error?(int)$row['attempts']+1:0;
            $state=$newer||$continuation!==null||($error && $attempts<5)?'pending':($error?'error':'idle');
            $delay=$error&&!$newer?min(3600,60*(2**min(6,$attempts-1))):($newer?0:max(0,min(3600,$wait)));
            DB::run("UPDATE git_sync_jobs SET state=?,attempts=?,available_at=UTC_TIMESTAMP()+INTERVAL ? SECOND,lease_owner=NULL,lease_until=NULL,payload_json=?,last_success_at=IF(? IS NULL,UTC_TIMESTAMP(),last_success_at),last_error=?,updated_at=UTC_TIMESTAMP() WHERE id=?",[$state,$newer?0:$attempts,$delay,$newer?$row['payload_json']:json_encode($continuation??$job['payload'],JSON_THROW_ON_ERROR),$error,$error,$job['id']]);
            if(!$error) {DB::run('UPDATE git_sync_state SET last_success_at=UTC_TIMESTAMP() WHERE connection_id=?',[$job['connection_id']]);DB::run('UPDATE connections SET last_sync_at=UTC_TIMESTAMP() WHERE id=?',[$job['connection_id']]);}
            if(!$newer && !$error && $continuation===null && $job['kind']==='repository' && isset($job['payload']['through'])) DB::run("UPDATE git_sync_state SET repository_watermark=GREATEST(COALESCE(repository_watermark,'1970-01-01'),?) WHERE connection_id=?",[$job['payload']['through'],$job['connection_id']]);
        });
    }
    public static function status(int $connection): array
    {
        $settings=DB::one('SELECT * FROM git_sync_state WHERE connection_id=?',[$connection])??['enabled'=>1,'interval_minutes'=>5,'last_poll_at'=>null,'last_success_at'=>null,'last_webhook_at'=>null];
        $worker=DB::one('SELECT seen_at,seen_at>=UTC_TIMESTAMP()-INTERVAL 2 MINUTE active FROM git_worker_state WHERE id=1');
        $jobs=DB::all("SELECT kind,state,attempts,last_error,last_success_at,updated_at,request_id FROM git_sync_jobs WHERE connection_id=? AND (state<>'idle' OR last_error IS NOT NULL) ORDER BY updated_at DESC LIMIT 20",[$connection]);
        $summary=DB::all('SELECT state,COUNT(*) n,SUM(last_error IS NOT NULL) errors FROM git_sync_jobs WHERE connection_id=? GROUP BY state',[$connection]);
        $counts=array_column($summary,'n','state');$problems=array_sum(array_column($summary,'errors'));
        return ['settings'=>$settings,'worker'=>$worker,'jobs'=>$jobs,'counts'=>$counts,'problems'=>$problems];
    }
}
