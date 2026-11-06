<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\DB;
use App\Models\GitJob;
final class GitSync
{
    public static function queuePullRequest(array $c,int $event): void
    {
        if(DB::one('SELECT 1
            FROM development_event_tickets
            WHERE event_id=?
            LIMIT 1',[$event])) GitJob::enqueue((int)$c['id'],'pull_request','pr:'.$event,['event_id'=>$event]);
    }
    public static function request(int $connection,bool $force=true): void
    {
        DB::run('INSERT IGNORE INTO git_sync_state(connection_id) VALUES(?)',[$connection]);
        $state=DB::one('SELECT * FROM git_sync_state WHERE connection_id=?',[$connection]);if(!$state['enabled']) return;
        $since=$state['repository_watermark']?gmdate('Y-m-d H:i:s',strtotime($state['repository_watermark'].' UTC')-600):gmdate('Y-m-d H:i:s',time()-30*86400);
        foreach(['repository'=>['page'=>1,'since'=>$since,'through'=>gmdate('Y-m-d H:i:s')],'pipelines'=>['page'=>1],'deployments'=>['page'=>1]] as $kind=>$payload) {
            if($force || !DB::one("SELECT 1
                FROM git_sync_jobs
                WHERE connection_id=? AND kind=? AND target='repository' AND state IN ('pending','running')",[$connection,$kind])) GitJob::enqueue($connection,$kind,'repository',$payload);
        }
        foreach(DB::all('SELECT DISTINCT b.branch_name
            FROM ticket_branch_links b
            JOIN tickets t ON t.id=b.ticket_id
            JOIN project_connections pc ON pc.project_id=t.project_id AND pc.connection_id=b.connection_id
            WHERE b.connection_id=?',[$connection]) as $row) {
            $target=hash('sha256',$row['branch_name']);
            if($force || !DB::one("SELECT 1
                FROM git_sync_jobs
                WHERE connection_id=? AND kind='branch' AND target=? AND state IN ('pending','running')",[$connection,$target])) GitJob::enqueue($connection,'branch',$target,['name'=>$row['branch_name'],'page'=>1]);
        }
        if($force) DB::run("UPDATE git_sync_jobs
            SET state='pending',attempts=0,available_at=UTC_TIMESTAMP(),last_error=NULL
            WHERE connection_id=? AND (state='error' OR (state='pending' AND last_error IS NOT NULL))",[$connection]);
    }
    public static function schedule(): void
    {
        DB::run('INSERT IGNORE INTO git_sync_state(connection_id) SELECT DISTINCT connection_id FROM project_connections');
        $rows=DB::all('SELECT s.*
            FROM git_sync_state s
            WHERE s.enabled=1 AND (s.last_poll_at IS NULL OR TIMESTAMPDIFF(MINUTE,s.last_poll_at,UTC_TIMESTAMP())>=s.interval_minutes)
            ORDER BY COALESCE(s.last_poll_at,\'1970-01-01\'),s.connection_id
            LIMIT 100');
        foreach($rows as $row) DB::transaction(function() use($row) {
            $state=DB::one('SELECT * FROM git_sync_state WHERE connection_id=? FOR UPDATE',[$row['connection_id']]);
            if($state['last_poll_at']!==$row['last_poll_at']) return;
            self::request((int)$row['connection_id'],false);DB::run('UPDATE git_sync_state
                SET last_poll_at=UTC_TIMESTAMP()
                WHERE connection_id=?',[$row['connection_id']]);
        });
    }
    public static function webhook(array $c,array $events): void
    {
        DB::run('INSERT INTO git_sync_state(connection_id,last_webhook_at)
            VALUES(?,UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE last_webhook_at=UTC_TIMESTAMP()',[$c['id']]);
        foreach($events as $event) {
            $row=DB::one('SELECT id FROM development_events WHERE connection_id=? AND external_key=?',[$c['id'],$event['external_key']]);if(!$row) continue;
            if($event['kind']==='pull_request') self::queuePullRequest($c,(int)$row['id']);
            if($event['kind']==='deployment') GitJob::enqueue((int)$c['id'],'deployment_links','deployment:'.$row['id'],['event_id'=>$row['id'],'after'=>0]);
            if($event['commit_sha']) foreach($c['provider']==='github'?['check','status']:['status'] as $phase) GitJob::enqueue((int)$c['id'],'checks',$phase.':'.$event['commit_sha'],['sha'=>$event['commit_sha'],'phase'=>$phase,'page'=>1]);
        }
        foreach(['pipelines','deployments'] as $kind) GitJob::enqueue((int)$c['id'],$kind,'repository',['page'=>1]);
    }
    public static function work(int $limit=20,int $seconds=45,bool $schedule=true): array
    {
        if($limit<1 || $limit>100 || $seconds<1 || $seconds>60) throw new \InvalidArgumentException('Budget de traitement Git invalide.');
        DB::run('INSERT INTO git_worker_state(id,seen_at) VALUES(1,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE seen_at=UTC_TIMESTAMP()');
        if($schedule) self::schedule();$start=microtime(true);$done=0;$failed=0;
        while($done+$failed<$limit && microtime(true)-$start<$seconds) {
            $job=GitJob::claim();if(!$job) break;
            TechnicalLog::background($job['request_id']);
            try {
                $c=DB::one('SELECT * FROM connections WHERE id=?',[$job['connection_id']]);
                $result=$c?GitMonitor::execute($c,$job):null;
                GitJob::finish($job,$result['payload']??null,null,(int)($result['delay']??0));$done++;
            } catch(\Throwable $error) {
                // Only controlled provider/domain messages are retained, never raw SQL/transport exceptions.
                $message=$error instanceof \InvalidArgumentException?mb_substr($error->getMessage(),0,240):'Erreur interne du traitement Git. Consultez les journaux techniques.';
                GitJob::finish($job,null,$message);$failed++;
            }
        }
        return ['processed'=>$done,'failed'=>$failed];
    }
}
