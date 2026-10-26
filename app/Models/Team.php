<?php
declare(strict_types=1);
namespace App\Models;
use App\Core\DB;

final class Team
{
    public static function save(?int $id, string $name): int
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120) {
            throw new \InvalidArgumentException(
                'Donnez un nom à l’équipe (120 caractères maximum).',
            );
        }
        return DB::transaction(function () use ($id, $name) {
            if ($id) {
                self::find($id);
            }
            if (DB::one('SELECT id FROM teams WHERE name=? AND id<>?', [$name, $id ?? 0])) {
                throw new \InvalidArgumentException('Une équipe porte déjà ce nom.');
            }
            try {
                if ($id) {
                    DB::run('UPDATE teams SET name=? WHERE id=?', [$name, $id]);
                    return $id;
                }
                return DB::insert('INSERT INTO teams(name) VALUES(?)', [$name]);
            } catch (\PDOException $e) {
                if (($e->errorInfo[1] ?? 0) === 1062) {
                    throw new \InvalidArgumentException('Une équipe porte déjà ce nom.');
                }
                throw $e;
            }
        });
    }

    public static function find(int $id): array
    {
        return DB::one('SELECT * FROM teams WHERE id=? FOR UPDATE', [$id]) ??
            throw new \InvalidArgumentException('Équipe introuvable.');
    }

    public static function membership(int $team, int $user, bool $add): void
    {
        DB::transaction(function () use ($team, $user, $add) {
            self::find($team);
            $account = DB::one('SELECT id,active,verified_at FROM users WHERE id=? FOR UPDATE', [
                $user,
            ]);
            if (!$account || ($add && (!$account['active'] || !$account['verified_at']))) {
                throw new \InvalidArgumentException(
                    'Choisissez un utilisateur actif dont l’adresse est vérifiée.',
                );
            }
            if ($add) {
                if (
                    !DB::one('SELECT user_id FROM team_members WHERE team_id=? AND user_id=?', [
                        $team,
                        $user,
                    ])
                ) {
                    DB::run('INSERT INTO team_members(team_id,user_id) VALUES(?,?)', [
                        $team,
                        $user,
                    ]);
                }
            } else {
                DB::run('DELETE FROM team_members WHERE team_id=? AND user_id=?', [$team, $user]);
            }
        });
    }
}
