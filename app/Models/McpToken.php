<?php
declare(strict_types=1);
namespace App\Models;
use App\Core\DB;

/**
 * Bearer keys for the MCP endpoint (table mcp_tokens), either created by a user as personal keys or
 * issued by OAuth for a grant (oauth_grant_id set; deleting the grant cascades to its tokens).
 *
 * Only the SHA-256 hash of a key is stored; the raw key is returned once by issue(). A key stops
 * working when it expires, when its owner is disabled or unverified, or when the owner's
 * auth_version changes (User::manage bumps it on a role or activation change). Effective scopes are
 * re-derived at every request: the key's scopes, intersected with what the owner's current role
 * allows and, for OAuth keys, with the grant's scopes. audit() logs MCP tool calls to mcp_audit.
 */
final class McpToken
{
    public const SCOPES = [
        'read' => 'Consulter',
        'write' => 'Gérer les tickets',
        'admin' => 'Administrer projets, formulaires, règles et accès utilisateurs',
    ];
    public static function availableScopes(string $role): array
    {
        return match ($role) {
            'admin' => array_keys(self::SCOPES),
            'member' => ['read', 'write'],
            default => ['read'],
        };
    }
    /** Creates a key; $seconds null means no expiry. Returns the token id and the raw key (only time it is visible). */
    public static function issue(array $user, string $name, array $scopes, ?int $seconds): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120) {
            throw new \InvalidArgumentException('Nommez votre clé (120 caractères maximum).');
        }
        if (!$scopes || array_diff($scopes, self::availableScopes($user['role']))) {
            throw new \InvalidArgumentException('Droits non autorisés pour ce compte.');
        }
        if ($seconds !== null && ($seconds < 60 || $seconds > 3153600000)) {
            throw new \InvalidArgumentException(
                'Durée comprise entre une minute et 100 ans, ou sans expiration.',
            );
        }
        $raw = 'slg_mcp_' . bin2hex(random_bytes(32));
        $hash = hash('sha256', $raw);
        $id = DB::insert(
            'INSERT INTO mcp_tokens(user_id,name,token_hash,fingerprint,scopes,auth_version,expires_at) VALUES(?,?,?,?,?,?,?)',
            [
                $user['id'],
                $name,
                $hash,
                substr($hash, 0, 12),
                json_encode(array_values(array_unique($scopes)), JSON_THROW_ON_ERROR),
                $user['auth_version'],
                $seconds === null ? null : gmdate('Y-m-d H:i:s', time() + $seconds),
            ],
        );
        return ['id' => $id, 'key' => $raw];
    }
    /**
     * Resolves a raw key to its owner plus token_id and effective scopes, or null if invalid.
     * OAuth keys also require a live grant, issued for this server's /mcp resource and the owner's current auth_version.
     */
    public static function authenticate(string $raw): ?array
    {
        if (!preg_match('/^slg_mcp_[a-f0-9]{64}$/D', $raw)) {
            return null;
        }
        $row = DB::one(
            'SELECT k.id token_id,k.scopes,k.auth_version token_version,u.id,u.name,u.email,u.role,u.auth_version,g.scopes grant_scopes
                FROM mcp_tokens k
                JOIN users u ON u.id=k.user_id
                LEFT JOIN oauth_grants g ON g.id=k.oauth_grant_id
                WHERE k.token_hash=? AND (k.expires_at IS NULL OR k.expires_at>UTC_TIMESTAMP()) AND u.active=1 AND u.verified_at IS NOT NULL AND (k.oauth_grant_id IS NULL OR (g.id IS NOT NULL AND g.expires_at>UTC_TIMESTAMP() AND g.auth_version=u.auth_version AND g.resource=?))',
            [hash('sha256', $raw),url('/mcp')],
        );
        if (!$row || (int) $row['token_version'] !== (int) $row['auth_version']) {
            return null;
        }
        $row['scopes'] = array_values(
            array_intersect(
                json_decode($row['scopes'], true, 32, JSON_THROW_ON_ERROR),
                self::availableScopes($row['role']),
            ),
        );
        if ($row['grant_scopes']!==null) $row['scopes']=array_values(array_intersect($row['scopes'],json_decode($row['grant_scopes'],true,32,JSON_THROW_ON_ERROR)));
        unset($row['grant_scopes']);
        return $row;
    }
    /** Updates the last-used time of the key and of its OAuth grant, if any. */
    public static function touch(int $id): void
    {
        DB::run('UPDATE mcp_tokens SET last_used_at=UTC_TIMESTAMP() WHERE id=?', [$id]);
        DB::run('UPDATE oauth_grants g JOIN mcp_tokens k ON k.oauth_grant_id=g.id SET g.last_used_at=UTC_TIMESTAMP() WHERE k.id=?',[$id]);
    }
    /** Deletes one of the owner's keys; for an OAuth key the whole grant is revoked (with its refresh token). */
    public static function remove(int $id, int $owner): void
    {
        $row=DB::one('SELECT oauth_grant_id FROM mcp_tokens WHERE id=? AND user_id=?',[$id,$owner]);
        if (!empty($row['oauth_grant_id'])) { DB::run('DELETE FROM oauth_grants WHERE id=? AND user_id=?',[$row['oauth_grant_id'],$owner]);return; }
        if (
            !DB::run('DELETE FROM mcp_tokens WHERE id=? AND user_id=?', [$id, $owner])->rowCount()
        ) {
            throw new \InvalidArgumentException('Clé introuvable.');
        }
    }
    public static function audit(array $user, string $tool, bool $success): void
    {
        DB::run('INSERT INTO mcp_audit(token_id,user_id,tool_name,succeeded) VALUES(?,?,?,?)', [
            $user['token_id'],
            $user['id'],
            $tool,
            (int) $success,
        ]);
    }
}
