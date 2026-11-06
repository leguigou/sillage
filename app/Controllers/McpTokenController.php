<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\Auth;
use App\Models\McpToken;

/**
 * Personal API keys for the MCP server (Model Context Protocol, lets AI tools
 * access Sillage), managed in the "mcp" section of /settings.
 *
 * - POST /mcp/tokens: issue a key with a name, scopes and a lifetime (limited
 *   duration or none). Allowed scopes depend on the user's role (checked in
 *   App\Models\McpToken::issue).
 * - POST /mcp/tokens/{id}/delete: revoke one of the user's own keys.
 * Any signed-in user, for their own keys only.
 */
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
        // The new key is passed to the next page through the session so the
        // settings view can display it (the raw key is only available at creation).
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
        // Scoped to the owner; a key created through OAuth is revoked by deleting its grant.
        McpToken::remove((int) $id, (int) $user['id']);
        flash('Clé supprimée. Elle ne permet plus de se connecter au MCP.');
        redirect('/settings#mcp');
    }
}
