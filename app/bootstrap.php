<?php
declare(strict_types=1);
define('ROOT', dirname(__DIR__));
spl_autoload_register(function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        $file = ROOT . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
if (is_file(ROOT . '/.env')) {
    foreach (file(ROOT . '/.env', FILE_IGNORE_NEW_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        if (getenv(trim($key)) === false) {
            putenv(trim($key) . '=' . trim($value, " \t\n\r\0\x0B\"'"));
        }
    }
}
date_default_timezone_set('UTC');
function env(string $key, string $fallback = ''): string
{
    $v = getenv($key);
    return $v === false ? $fallback : $v;
}
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function url(string $path = ''): string
{
    return rtrim(env('APP_URL', 'http://127.0.0.1:8088'), '/') . '/' . ltrim($path, '/');
}
function ticket_url(int $id,string $fragment=''): string
{
    return App\Services\TicketNavigation::ticket($id,$fragment);
}
function ticket_context(): string
{
    $source=App\Services\TicketNavigation::current();
    return $source===null?'':'<input type="hidden" name="_ticket_return" value="'.e($source).'">';
}
function asset_url(string $name): string
{
    static $versions = [];
    if (!preg_match('#^[a-zA-Z0-9_./-]+$#D', $name) || str_contains($name, '..')) {
        throw new InvalidArgumentException('Nom de ressource invalide.');
    }
    $path = '/assets/' . ltrim($name, '/');
    $file = ROOT . '/public' . $path;
    $versions[$path] ??= is_file($file) ? substr(hash_file('sha256', $file), 0, 12) : '';
    return $path . ($versions[$path] !== '' ? '?v=' . $versions[$path] : '');
}
function redirect(string $path): never
{
    header('Location: ' . url($path), true, 303);
    exit();
}
function csrf(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(App\Core\Security::csrf()) . '">';
}
function flash(string $message, string $type = 'success'): void
{
    $_SESSION['flash'][] = ['message' => $message, 'type' => $type];
}
function current_user(): ?array
{
    return App\Core\Auth::user();
}
function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    exit();
}
set_exception_handler(function (Throwable $error): void {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (!is_dir(ROOT . '/storage')) {
        mkdir(ROOT . '/storage', 0700, true);
    }
    error_log('[' . gmdate('c') . '] ' . $error . PHP_EOL, 3, ROOT . '/storage/error.log');
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $error->getMessage() . PHP_EOL);
        exit(1);
    }
    http_response_code(500);
    $message =
        env('APP_ENV') === 'local'
            ? $error->getMessage()
            : 'Une erreur est survenue. Réessayez dans quelques instants.';
    require ROOT . '/app/Views/error.php';
});

// Local log transport is fast and deterministic; production mail is delivered by the CLI worker.
if (PHP_SAPI!=='cli' && env('APP_ENV')==='local' && env('MAIL_TRANSPORT')==='log') {
    register_shutdown_function(function(): void {
        try {
            if (!App\Core\DB::connection()->inTransaction()) App\Services\Notifications::deliver(20);
        } catch (Throwable) {
            error_log('Sillage : traitement local des notifications indisponible.');
        }
    });
}
