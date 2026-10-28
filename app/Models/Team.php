<?php
declare(strict_types=1);
namespace App\Models;
use App\Core\DB;

final class Team
{
    public static function delete(int $id): void
    {
        DB::transaction(function () use ($id) {
            self::find($id);
            // Keep tickets and their individual owners, and invalidate stale edit forms.
            DB::run('UPDATE tickets SET team_id=NULL,version=version+1,updated_at=UTC_TIMESTAMP() WHERE team_id=?',[$id]);
            DB::run('DELETE FROM teams WHERE id=?', [$id]);
        });
    }
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

    public static function forProject(int $project): array
    {
        return DB::all('SELECT t.* FROM teams t JOIN team_projects tp ON tp.team_id=t.id WHERE tp.project_id=? ORDER BY t.name,t.id',[$project]);
    }

    public static function setProjectTeams(int $project,array $ids): void
    {
        DB::transaction(function() use($project,$ids) {
            if (!DB::one('SELECT id FROM projects WHERE id=? FOR UPDATE',[$project])) throw new \InvalidArgumentException('Projet introuvable.');
            $selected=[];
            foreach($ids as $id) {
                if ((!is_string($id) && !is_int($id)) || !ctype_digit((string)$id) || (int)$id<1) throw new \InvalidArgumentException('Équipe invalide.');
                $selected[]=(int)$id;
            }
            $selected=array_values(array_unique($selected)); sort($selected);
            foreach($selected as $id) self::find($id);
            $current=array_map('intval',array_column(self::forProject($project),'id'));
            foreach(array_diff($current,$selected) as $id) {
                if (DB::one('SELECT id FROM tickets WHERE project_id=? AND team_id=? LIMIT 1',[$project,$id])) throw new \InvalidArgumentException('Réaffectez les tickets de cette équipe avant de la retirer du projet (y compris les tickets archivés).');
            }
            foreach(array_diff($current,$selected) as $id) {
                DB::run('DELETE FROM team_projects WHERE team_id=? AND project_id=?',[$id,$project]);
            }
            foreach(array_diff($selected,$current) as $id) DB::run('INSERT INTO team_projects(team_id,project_id) VALUES(?,?)',[$id,$project]);
        });
    }

    public static function projectMembership(int $team,int $project,bool $add): void
    {
        DB::transaction(function() use($team,$project,$add) {
            if (!DB::one('SELECT id FROM projects WHERE id=? FOR UPDATE',[$project])) throw new \InvalidArgumentException('Projet introuvable.');
            self::find($team);
            $ids=array_map('intval',array_column(self::forProject($project),'id'));
            self::setProjectTeams($project,$add?[...$ids,$team]:array_values(array_diff($ids,[$team])));
        });
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
