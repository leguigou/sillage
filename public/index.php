<?php
declare(strict_types=1);
/**
 * Front controller: every HTTP request that is not a static asset enters here.
 * Dispatch order matters. Machine-to-machine endpoints are served first and exit without a PHP
 * session, cookies or CSRF: the MCP JSON-RPC endpoint (/mcp, bearer-token authenticated in
 * App\Mcp\Server) and the public OAuth endpoints (metadata, token, revoke). Only then is the
 * browser session started (Security::start also sends the security headers) and routes/web.php
 * dispatched. Both MCP and OAuth handlers end the request themselves (return type never).
 */
require dirname(__DIR__) . '/app/bootstrap.php';
App\Services\TechnicalLog::beginIncoming();
if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/mcp') {
    App\Mcp\Server::handle();
}
$oauthPath=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if (in_array($oauthPath,['/.well-known/oauth-authorization-server','/.well-known/oauth-protected-resource','/.well-known/oauth-protected-resource/mcp','/oauth/token','/oauth/revoke'],true)) App\Controllers\OAuthController::publicEndpoint($oauthPath);
App\Core\Security::start();
require ROOT . '/routes/web.php';
