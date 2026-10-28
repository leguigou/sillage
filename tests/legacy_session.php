<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') exit;
if (!getenv('DB_DATABASE') && is_file(dirname(__DIR__).'/.runtime/test-env.json')) foreach(json_decode(file_get_contents(dirname(__DIR__).'/.runtime/test-env.json'),true) as $key=>$value) putenv($key.'='.$value);
require dirname(__DIR__).'/app/bootstrap.php';
use App\Core\{DB,Security,Auth,PersistentLogin};
if (env('DB_DATABASE')!=='sillage_test') throw new RuntimeException('Isolated database required.');
Security::start();
$account=DB::one("SELECT id,auth_version FROM users WHERE email='qa.admin@example.com'");
$_SESSION=['user_id'=>$account['id'],'auth_version'=>$account['auth_version'],'last_seen'=>time()];
$first=Auth::user();$second=Auth::user();
$result=['authenticated_twice'=>$first!==null && $second!==null,'cookie_created'=>isset($_COOKIE[PersistentLogin::cookieName()]),'expires_in_30_days'=>abs((int)($_SESSION['remember_until']??0)-time()-PersistentLogin::LIFETIME)<5];
Auth::logout();
echo json_encode($result,JSON_THROW_ON_ERROR);
