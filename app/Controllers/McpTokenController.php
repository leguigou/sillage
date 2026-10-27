<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\Auth;
use App\Models\McpToken;

final class McpTokenController
{
    public function create(): void
    {
        $user = Auth::requireUser();
        if (!in_array($_POST['lifetime'] ?? '', ['limited', 'unlimited'], true)) {
            throw new \InvalidArgumentException('Choisissez une validité de clé.');
        }
        $seconds = null;
        if (($_POST['lifetime'] ?? '') !== 'unlimited') {
            $duration = filter_var($_POST['duration'] ?? null, FILTER_VALIDATE_INT);
            $unit =
                ['minutes' => 60, 'hours' => 3600, 'days' => 86400][$_POST['unit'] ?? ''] ?? null;
            if (!$duration || $duration < 1 || $duration > 36500 || !$unit) {
                throw new \InvalidArgumentException(
                    'Choisissez une durée valide ou « Sans expiration ».',
                );
            }
            $seconds = $duration * $unit;
        }
        $_SESSION['new_mcp_key'] = McpToken::issue(
            $user,
            (string) ($_POST['name'] ?? ''),
            (array) ($_POST['scopes'] ?? []),
            $seconds,
        );
        redirect('/settings#mcp');
    }
    public function delete(string $id): void
    {
        $user = Auth::requireUser();
        McpToken::remove((int) $id, (int) $user['id']);
        flash('Clé supprimée. Elle ne permet plus de se connecter au MCP.');
        redirect('/settings#mcp');
    }
}
