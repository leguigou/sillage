<?php
declare(strict_types=1);
namespace App\Core;
final class View
{
    public static function render(string $template, array $data = []): void
    {
        require_once ROOT . '/app/Views/icons.php';
        extract($data, EXTR_SKIP);
        ob_start();
        require ROOT . '/app/Views/' . $template . '.php';
        $content = ob_get_clean();
        require ROOT . '/app/Views/' . (isset($guest) && $guest ? 'guest' : 'layout') . '.php';
    }
}
