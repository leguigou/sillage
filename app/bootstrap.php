<?php
declare(strict_types=1);
/**
 * Shared bootstrap, required first by every entry point (public/index.php and the bin/ CLI scripts).
 * It defines ROOT, registers a PSR-4-style autoloader mapping App\ to app/, loads .env into the
 * process environment (real environment variables win over the file), forces UTC, declares the
 * global helper functions used by controllers and views (env, e, url, redirect, csrf, flash, ...),
 * and installs the last-resort exception handler. It does not start the session: that is
 * App\Core\Security::start(), called by public/index.php only for browser routes.
 */
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
/** Read a configuration value; an unset variable yields $fallback, an empty one stays ''. */
function env(string $key, string $fallback = ''): string
{
    $v = getenv($key);
    return $v === false ? $fallback : $v;
}
/**
 * Product name shown to users (titles, header, e-mails, MCP), from APP_NAME. Unset or empty means
 * "Sillage"; a too long (> 60 characters) or control-character value is logged and falls back to the
 * default so that error pages can always render. Technical identifiers (HTTP user agent, export manifest, CSS/JS
 * names, database) deliberately keep the fixed "Sillage" name.
 */
function app_name(): string
{
    static $name = null;
    if ($name === null) {
        $value = trim(env('APP_NAME'));
        $value = $value === '' ? 'Sillage' : $value;
        $valid = mb_strlen($value) <= 60 && !preg_match('/[\x00-\x1f\x7f]/', $value);
        if (!$valid) {
            error_log('APP_NAME invalide (1 à 60 caractères, sans caractère de contrôle) : nom par défaut utilisé.');
        }
        $name = $valid ? $value : 'Sillage';
    }
    return $name;
}
/** First letter of app_name(), used as the product symbol on pages without company branding. */
function app_initial(): string
{
    return mb_strtoupper(mb_substr(app_name(), 0, 1));
}
/** HTML-escape any value for output in element content or a quoted attribute. */
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
/** Absolute URL under APP_URL. Always anchored to the configured origin, never to the Host header. */
function url(string $path = ''): string
{
    return rtrim(env('APP_URL', 'http://127.0.0.1:8088'), '/') . '/' . ltrim($path, '/');
}
function ticket_url(int $id,string $fragment='',array $query=[]): string
{
    return App\Services\TicketNavigation::ticket($id,$fragment,$query);
}
function query_url(string $path,array $query,array $changes=[]): string {
    // Choosing a different header starts a new primary sort; same-field direction toggles retain tie breakers.
    if(isset($changes['sort']) && $changes['sort']!==($query['sort']??'rank')) $changes+=['sort2'=>'','sort3'=>'','direction2'=>'asc','direction3'=>'asc'];
    return $path.'?'.http_build_query(array_replace($query,$changes));
}
/**
 * Link that opens personal report $view in the ticket view being displayed: dashboard, board, timeline and
 * charts keep their section (the timeline also keeps its scale and start date); the list and the
 * cross-project "all" page open the project list, since a report belongs to one project.
 */
function report_url(string $section,int $project,int $view,array $current=[]): string {
    $path=match($section){'dashboard'=>'/','board','timeline','charts'=>'/'.$section,default=>'/list'};
    $query=['project'=>$project,'view'=>$view];
    if($section==='timeline') foreach(['scale','from'] as $key) if(isset($current[$key]) && is_scalar($current[$key]) && (string)$current[$key]!=='') $query[$key]=(string)$current[$key];
    return $path.'?'.http_build_query($query);
}
function ticket_context(): string
{
    $source=App\Services\TicketNavigation::current();
    return $source===null?'':'<input type="hidden" name="_ticket_return" value="'.e($source).'">';
}
/**
 * Public URL of a file under public/assets/, with a content-hash query string for cache busting.
 * The name is restricted to a safe character set and may not contain "..", so it cannot escape assets/.
 */
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
/** 303 redirect to a path on this application (see url()), then stop the request. */
function redirect(string $path): never
{
    header('Location: ' . url($path), true, 303);
    exit();
}
/** Hidden form field carrying the session CSRF token checked by Router/Security on POST. */
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
    if (isset($data['error'])) App\Services\TechnicalLog::incoming(['failed'=>true]);
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    exit();
}
// Last-resort handler: discard any partial output, log the full error to storage/error.log, and show
// the details only when APP_ENV=local; other environments get a generic French message.
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
