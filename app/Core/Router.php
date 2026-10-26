<?php
declare(strict_types=1);
namespace App\Core;
final class Router
{
    private array $routes = [];
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
            if ($method === 'POST' && $csrf) {
                Security::checkCsrf();
            }
            $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
            try {
                $handler(...array_values($params));
            } catch (\InvalidArgumentException $error) {
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
