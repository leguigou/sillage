<?php
declare(strict_types=1);
namespace App\Models;
use App\Core\DB;

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
    public static function authenticate(string $raw): ?array
    {
        if (!preg_match('/^slg_mcp_[a-f0-9]{64}$/D', $raw)) {
            return null;
        }
        $row = DB::one(
            'SELECT k.id token_id,k.scopes,k.auth_version token_version,u.id,u.name,u.email,u.role,u.auth_version FROM mcp_tokens k JOIN users u ON u.id=k.user_id WHERE k.token_hash=? AND (k.expires_at IS NULL OR k.expires_at>UTC_TIMESTAMP()) AND u.active=1 AND u.verified_at IS NOT NULL',
            [hash('sha256', $raw)],
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
        return $row;
    }
    public static function touch(int $id): void
    {
        DB::run('UPDATE mcp_tokens SET last_used_at=UTC_TIMESTAMP() WHERE id=?', [$id]);
    }
    public static function remove(int $id, int $owner): void
    {
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
