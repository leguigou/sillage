<?php
declare(strict_types=1);
// Never used by the production front controller. No real Git request can leave this fixture.
if (PHP_SAPI!=='cli-server' || !str_starts_with($_SERVER['SERVER_ADDR']??'127.0.0.1','127.')) {http_response_code(503);exit();}
if (!getenv('DB_DATABASE') && is_file(dirname(__DIR__).'/.runtime/test-env.json')) foreach(json_decode(file_get_contents(dirname(__DIR__).'/.runtime/test-env.json'),true,512,JSON_THROW_ON_ERROR) as $key=>$value) putenv($key.'='.$value);
if (getenv('DB_DATABASE')!=='sillage_test') {http_response_code(503);exit();}
putenv('APP_URL=http://127.0.0.1:8089');
require __DIR__.'/git_transport.php';
return require dirname(__DIR__).'/public/router.php';
