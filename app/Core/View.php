<?php
declare(strict_types=1);
namespace App\Core;
/**
 * Server-side HTML rendering with plain PHP templates from app/Views/. render() runs the page
 * template into a buffer exposed as $content, then wraps it in layout.php (or guest.php when the
 * data sets $guest, e.g. for login pages). Templates must escape output themselves with e().
 */
final class View
{
    /** Human-readable duration label for an hours value (delegates to Duration::label). */
    public static function hours(int|float|string|null $value): string
    {
        return \App\Services\Duration::label($value);
    }
    /** Render app/Views/$template.php with $data extracted as local variables, inside the page layout. */
    public static function render(string $template, array $data = []): void
    {
        require_once ROOT . '/app/Views/icons.php';
        // Collect the user ids the page is likely to show so UserAvatar can prime them in one batch.
        $avatarIds = [$data['user']['id'] ?? 0];
        foreach ($data['members'] ?? [] as $person) $avatarIds[] = $person['id'];
        foreach ($data['comments'] ?? [] as $comment) $avatarIds[] = $comment['user_id'];
        foreach ($data['activity'] ?? [] as $event) $avatarIds[] = $event['user_id'];
        foreach ($data['history']['entries'] ?? [] as $event) $avatarIds[] = $event['actor_id'];
        foreach ($data['tickets'] ?? [] as $ticket) $avatarIds[] = $ticket['assignee_id'];
        foreach ($data['teamMembers'] ?? [] as $people) foreach ($people as $person) $avatarIds[] = $person['id'];
        if (array_filter($avatarIds)) \App\Models\UserAvatar::prime($avatarIds);
        // EXTR_SKIP: data keys can never overwrite $template, $data or other locals already defined here.
        extract($data, EXTR_SKIP);
        ob_start();
        require ROOT . '/app/Views/' . $template . '.php';
        $content = ob_get_clean();
        require ROOT . '/app/Views/' . (isset($guest) && $guest ? 'guest' : 'layout') . '.php';
    }
}
