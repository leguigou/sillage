<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli') exit(1);
foreach(json_decode(file_get_contents(dirname(__DIR__).'/.runtime/test-env.json'),true,512,JSON_THROW_ON_ERROR) as $key=>$value) putenv($key.'='.$value);
require __DIR__.'/git_transport.php';
require dirname(__DIR__).'/app/bootstrap.php';
if(env('DB_DATABASE')!=='sillage_test') throw new RuntimeException('Worker simulé réservé à sillage_test.');
if(($argv[1]??'')==='clear') {App\Core\DB::run('DELETE FROM git_sync_jobs');echo "File synthétique vidée.\n";exit();}
$initialExecutionLimit=ini_get('max_execution_time');$result=App\Services\GitSync::work(100,60,false);
if(ini_get('max_execution_time')!==$initialExecutionLimit) throw new RuntimeException('Une collecte a modifié la durée de vie du worker CLI.');
echo json_encode($result,JSON_THROW_ON_ERROR).PHP_EOL;
