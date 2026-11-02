<?php
use App\Services\TechnicalLog;
use App\Core\DB;

check(TechnicalLog::endpoint('https://user:secret@example.com:8443/v1/models?key=secret#secret')==='https://example.com:8443/v1/models','journal supprime identifiants URL, paramètres et fragment');
$logOriginalRetention=env('TECHNICAL_LOG_RETENTION_DAYS','');putenv('TECHNICAL_LOG_RETENTION_DAYS=invalid');
check(TechnicalLog::retention()===30,'conservation invalide revient à trente jours');putenv('TECHNICAL_LOG_RETENTION_DAYS=7');check(TechnicalLog::retention()===7,'conservation du journal configurable');putenv('TECHNICAL_LOG_RETENTION_DAYS='.$logOriginalRetention);
$logCorrelation=bin2hex(random_bytes(16));$logIds=[];$logWriter=DB::independent();
try {
    $logEntry=['request_id'=>$logCorrelation,'direction'=>'outgoing','channel'=>'development','provider'=>'github','method'=>'GET','endpoint'=>'https://api.github.com/repos/test/project?access_token=synthetic-secret','operation'=>'sync','outcome'=>'success','http_status'=>200,'duration_ms'=>12,'body'=>'synthetic-secret','authorization'=>'synthetic-secret'];
    DB::run('SAVEPOINT technical_log_test');
    $logIds[]=TechnicalLog::append($logEntry);
    DB::run('ROLLBACK TO SAVEPOINT technical_log_test');
    $stored=$logWriter->query('SELECT * FROM technical_logs WHERE id='.(int)$logIds[0])->fetch();
    check(!str_contains(json_encode($stored),'synthetic-secret') && $stored['endpoint']==='https://api.github.com/repos/test/project','journal persiste uniquement les métadonnées autorisées');
    check($stored['http_status']===200 && $stored['duration_ms']===12,'statut HTTP et durée sont conservés');
    check($logWriter->query('SELECT COUNT(*) FROM technical_logs WHERE id='.(int)$logIds[0])->fetchColumn()==1,'journal reste présent après annulation des écritures métier');
    for($i=0;$i<51;$i++) $logIds[]=TechnicalLog::append(array_replace($logEntry,['direction'=>$i%2?'incoming':'outgoing','provider'=>$i%2?'bitbucket':'github','outcome'=>$i%3?'success':'error','http_status'=>$i%3?200:403]));
    $logPage=TechnicalLog::page(['request_id'=>$logCorrelation]);$logOlder=TechnicalLog::page(['request_id'=>$logCorrelation,'before'=>$logPage['next']]);
    check(count($logPage['rows'])===50 && count($logOlder['rows'])===2,'journal paginé par curseur sans doublon');
    check(!array_intersect(array_column($logPage['rows'],'id'),array_column($logOlder['rows'],'id')),'pages du journal disjointes');
    $logFiltered=TechnicalLog::page(['request_id'=>$logCorrelation,'provider'=>'bitbucket','direction'=>'incoming','outcome'=>'error']);
    check(count($logFiltered['rows'])>0 && count(array_filter($logFiltered['rows'],fn($row)=>$row['provider']==='bitbucket' && $row['direction']==='incoming' && $row['outcome']==='error'))===count($logFiltered['rows']),'filtres combinés du journal appliqués en SQL');
    foreach([['channel'=>'sql'],['provider'=>"github' OR 1=1"],['request_id'=>'secret'],['from'=>'2026-02-30'],['from'=>'2026-11-02','to'=>'2026-11-01'],['before'=>-1],['outcome'=>[]]] as $invalid) rejects(fn()=>TechnicalLog::page($invalid),'filtre technique mal formé refusé');
    $logExpired=TechnicalLog::append($logEntry);$logIds[]=$logExpired;
    $logWriter->exec('UPDATE technical_logs SET created_at=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 400 DAY) WHERE id='.$logExpired);
    check(!in_array($logExpired,array_column(TechnicalLog::page(['request_id'=>$logCorrelation])['rows'],'id')),'données expirées exclues des consultations');
    TechnicalLog::prune($logWriter);
    check(!$logWriter->query('SELECT id FROM technical_logs WHERE id='.$logExpired)->fetch(),'purge retire uniquement les appels expirés');
    $logBefore=(int)$logWriter->query('SELECT COALESCE(MAX(id),0) FROM technical_logs')->fetchColumn();
    $logReturn=TechnicalLog::outgoing('development','bitbucket','GET','https://api.bitbucket.org/2.0/repositories/test/project?token=synthetic-secret',function(array &$trace): array {$trace['http_status']=200;return ['ok'=>true];});
    check($logReturn['ok'],'instrumentation préserve le résultat du transfert');
    try {TechnicalLog::outgoing('llm','ollama','POST','http://127.0.0.1:11434/api/chat',function(array &$trace): array {$trace['error_code']='transport:7';throw new \InvalidArgumentException('synthetic-secret');});} catch(\InvalidArgumentException $error) {check($error->getMessage()==='synthetic-secret','instrumentation préserve l’exception métier originale');}
    $logCaptured=$logWriter->query('SELECT * FROM technical_logs WHERE id>'.$logBefore.' ORDER BY id')->fetchAll();$logIds=array_merge($logIds,array_column($logCaptured,'id'));
    check(count($logCaptured)===2 && $logCaptured[0]['outcome']==='success' && $logCaptured[1]['outcome']==='error' && $logCaptured[1]['http_status']===null,'réussite et échec réseau capturés séparément sans inventer de réponse HTTP');
    check($logCaptured[0]['request_id']===$logCaptured[1]['request_id'] && !str_contains(json_encode($logCaptured),'synthetic-secret'),'corrélation partagée sans secret ni message brut');
} finally {
    foreach(array_unique($logIds) as $logId) $logWriter->exec('DELETE FROM technical_logs WHERE id='.(int)$logId);
}
