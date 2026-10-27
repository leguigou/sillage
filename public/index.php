<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/mcp') {
    App\Mcp\Server::handle();
}
App\Core\Security::start();
require ROOT . '/routes/web.php';
