<?php
declare(strict_types=1);
namespace App\Models;
use App\Core\DB;

final class User
{
    public const ROLES = [
        'admin' => 'Administrateur',
        'member' => 'Membre',
        'viewer' => 'Lecture seule',
    ];

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
}
