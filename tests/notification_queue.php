<?php
declare(strict_types=1);
if (!getenv('DB_DATABASE') && is_file(dirname(__DIR__).'/.runtime/test-env.json')) foreach(json_decode(file_get_contents(dirname(__DIR__).'/.runtime/test-env.json'),true) as $key=>$value) putenv($key.'='.$value);
require dirname(__DIR__).'/app/bootstrap.php';
use App\Core\{DB,Security};
use App\Models\{Project,ProjectEmail,Ticket};
if (env('DB_DATABASE')!=='sillage_test' || env('APP_ENV')!=='local') throw new RuntimeException('Only isolated local test database permitted.');
$command=$argv[1]??'fixture';
if ($command==='fixture') {
    $marker='Queue-'.bin2hex(random_bytes(5));
    $project=Project::save(['name'=>$marker,'key'=>'NQ'.strtoupper(bin2hex(random_bytes(3)))]);
    $user=DB::insert("INSERT INTO users(email,name,password_hash,role,verified_at) VALUES(?,?,?,'member',UTC_TIMESTAMP())",[strtolower($marker).'@example.com','Queue test',Security::password('Synthetic-Queue-2026!')]);
    ProjectEmail::save($project,['version'=>0,'enabled'=>1,'notify_actor'=>1,'rules'=>['created'=>['reporter']]]);
    $ticket=Ticket::create($project,['title'=>$marker],$user);
    $row=DB::one('SELECT id FROM notification_outbox WHERE ticket_id=?',[$ticket]);
    echo json_encode(['id'=>$row['id'],'project_id'=>$project,'marker'=>$marker],JSON_THROW_ON_ERROR);exit;
}
$id=(int)($argv[2]??0);
if (!DB::one('SELECT id FROM notification_outbox WHERE id=?',[$id])) throw new RuntimeException('Test notification not found.');
if ($command==='ready') DB::run("UPDATE notification_outbox SET state='pending',available_at=UTC_TIMESTAMP()-INTERVAL 1 MINUTE WHERE id=?",[$id]);
elseif ($command==='exhaust') DB::run("UPDATE notification_outbox SET attempts=4,state='pending',available_at=UTC_TIMESTAMP()-INTERVAL 1 MINUTE WHERE id=?",[$id]);
elseif ($command==='stale') DB::run("UPDATE notification_outbox SET state='sending',last_attempt_at=UTC_TIMESTAMP()-INTERVAL 11 MINUTE,lease_token=? WHERE id=?",[str_repeat('a',32),$id]);
elseif ($command==='optout') DB::run('UPDATE users u JOIN notification_outbox n ON n.recipient_id=u.id SET u.ticket_email_enabled=0 WHERE n.id=?',[$id]);
elseif ($command!=='status') throw new RuntimeException('Unknown test command.');
echo json_encode(DB::one('SELECT state,attempts,last_error FROM notification_outbox WHERE id=?',[$id]),JSON_THROW_ON_ERROR);
