<?php
declare(strict_types=1);
namespace App\Models;
use App\Core\DB;

/**
 * Administrative management of user accounts (table users): role and active flag.
 * Invariants: at least one active, verified administrator always remains, and administrators
 * cannot demote or deactivate themselves. Any role or activation change bumps users.auth_version,
 * which invalidates MCP keys and OAuth grants, and revokes the user's persistent logins.
 */
final class User
{
    public const ROLES = [
        'admin' => 'Administrateur',
        'member' => 'Membre',
        'viewer' => 'Lecture seule',
    ];

    /** Sets a user's role and active flag on behalf of administrator $actor. */
    public static function manage(int $id, string $role, bool $active, int $actor): void
    {
        if (!isset(self::ROLES[$role])) {
            throw new \InvalidArgumentException('Rôle invalide.');
        }
        DB::transaction(function () use ($id, $role, $active, $actor) {
            // Lock all administrators in a stable order: concurrent changes must preserve one active admin.
            $admins = DB::all(
                "SELECT id FROM users WHERE role='admin' AND active=1 AND verified_at IS NOT NULL ORDER BY id FOR UPDATE",
            );
            $old = DB::one('SELECT id,role,active,verified_at FROM users WHERE id=? FOR UPDATE', [
                $id,
            ]);
            if (!$old) {
                throw new \InvalidArgumentException('Utilisateur introuvable.');
            }
            if ($id === $actor && (!$active || $role !== 'admin')) {
                throw new \InvalidArgumentException(
                    'Demandez à un autre administrateur de modifier vos propres accès.',
                );
            }
            if (
                $old['role'] === 'admin' &&
                $old['active'] &&
                $old['verified_at'] &&
                (!$active || $role !== 'admin') &&
                count($admins) < 2
            ) {
                throw new \InvalidArgumentException(
                    'Conservez au moins un administrateur actif et vérifié.',
                );
            }
            if ($old['role'] !== $role || (bool) $old['active'] !== $active) {
                DB::run('UPDATE users SET role=?,active=?,auth_version=auth_version+1 WHERE id=?', [
                    $role,
                    (int) $active,
                    $id,
                ]);
                \App\Core\PersistentLogin::revokeAll($id);
            }
        });
    }

    /** Whether the user accepts ticket e-mails (row with ticket_email_enabled, or null). */
    public static function emailPreference(int $id): ?array
    {
        return DB::one(
            'SELECT ticket_email_enabled
                FROM users
                WHERE id=?',
            [$id],
        );
    }
}
