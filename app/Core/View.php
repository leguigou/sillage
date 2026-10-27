<?php
declare(strict_types=1);
namespace App\Core;
final class View
{
    public static function render(string $template, array $data = []): void
    {
        require_once ROOT . '/app/Views/icons.php';
        $avatarIds = [$data['user']['id'] ?? 0];
        foreach ($data['members'] ?? [] as $person) $avatarIds[] = $person['id'];
        foreach ($data['comments'] ?? [] as $comment) $avatarIds[] = $comment['user_id'];
        foreach ($data['activity'] ?? [] as $event) $avatarIds[] = $event['user_id'];
        foreach ($data['tickets'] ?? [] as $ticket) $avatarIds[] = $ticket['assignee_id'];
        foreach ($data['teamMembers'] ?? [] as $people) foreach ($people as $person) $avatarIds[] = $person['id'];
        if (array_filter($avatarIds)) \App\Models\UserAvatar::prime($avatarIds);
        extract($data, EXTR_SKIP);
        ob_start();
        require ROOT . '/app/Views/' . $template . '.php';
        $content = ob_get_clean();
        require ROOT . '/app/Views/' . (isset($guest) && $guest ? 'guest' : 'layout') . '.php';
    }
}
