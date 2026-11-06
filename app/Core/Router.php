<?php
declare(strict_types=1);
namespace App\Core;
/**
 * Minimal HTTP router used by routes/web.php. Routes are tried in registration order and the first
 * one matching both method and full path wins; {name} placeholders match a single path segment and
 * are passed to the handler positionally. POST routes are CSRF-checked before the handler runs
 * unless registered with $csrf = false. An InvalidArgumentException thrown by a handler is treated
 * as a user-facing validation error: JSON 422, a 422 error page for GET, or a flash message and
 * redirect back for POST. Any other exception reaches the global handler in bootstrap.php.
 */
final class Router
{
    private array $routes = [];
    /** Register a route; $csrf = false is reserved for callers that authenticate otherwise (e.g. signed webhooks). */
    public function add(string $method, string $path, callable $handler, bool $csrf = true): self
    {
        $this->routes[] = [$method, $path, $handler, $csrf];
        return $this;
    }
    public function dispatch(): void
    {
        $path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
        foreach ($this->routes as [$method, $pattern, $handler, $csrf]) {
            if ($_SERVER['REQUEST_METHOD'] !== $method) {
                continue;
            }
            $regex = '#^' . preg_replace('/\{([a-z_]+)\}/', '(?P<$1>[^/]+)', $pattern) . '$#D';
            if (!preg_match($regex, $path, $matches)) {
                continue;
            }
            \App\Services\TechnicalLog::incoming(['endpoint'=>$pattern]);
            if ($method === 'POST' && $csrf) {
                Security::checkCsrf();
            }
            $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
            try {
                $handler(...array_values($params));
            } catch (\InvalidArgumentException $error) {
                \App\Services\TechnicalLog::incoming(['failed'=>true,'error_code'=>'invalid_response_or_configuration']);
                if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
                    json_response(['error' => $error->getMessage()], 422);
                }
                if ($method === 'GET') {
                    http_response_code(422);
                    $message = $error->getMessage();
                    require ROOT . '/app/Views/error.php';
                    return;
                }
                flash($error->getMessage(), 'error');
                $return = (string) ($_POST['_return'] ?? $path);
                // Only accept a local absolute path (no "//host", no header-splitting newlines):
                // _return is client-supplied and must not become an open redirect.
                if (
                    !str_starts_with($return, '/') ||
                    str_starts_with($return, '//') ||
                    str_contains($return, "\r") ||
                    str_contains($return, "\n")
                ) {
                    $return = '/';
                }
                redirect($return);
            }
            return;
        }
        http_response_code(404);
        $message = 'Cette page n’existe pas.';
        require ROOT . '/app/Views/error.php';
    }
}
