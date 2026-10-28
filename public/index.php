<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/mcp') {
    App\Mcp\Server::handle();
}
$oauthPath=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if (in_array($oauthPath,['/.well-known/oauth-authorization-server','/.well-known/oauth-protected-resource','/.well-known/oauth-protected-resource/mcp','/oauth/token','/oauth/revoke'],true)) App\Controllers\OAuthController::publicEndpoint($oauthPath);
App\Core\Security::start();
require ROOT . '/routes/web.php';
