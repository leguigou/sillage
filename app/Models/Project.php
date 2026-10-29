<?php
declare(strict_types=1);
namespace App\Models;
use App\Core\DB;
final class Project
{
    public static function save(array $input, ?int $id = null): int
    {
        return DB::transaction(function () use ($input, $id) {
            if ($id) {
                DB::one('SELECT id FROM projects WHERE id=? FOR UPDATE', [$id]);
            }
            $old = $id ? self::find($id) : null;
            if (array_key_exists('team_ids',$input) && !is_array($input['team_ids'])) throw new \InvalidArgumentException('Liste d’équipes invalide.');
            if (array_key_exists('repository_connection_id',$input) && $input['repository_connection_id']!==null && $input['repository_connection_id']!=='') {
                $repositoryId=filter_var($input['repository_connection_id'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
                if ($repositoryId===false || !DB::one('SELECT id FROM connections WHERE id=?',[$repositoryId])) throw new \InvalidArgumentException('Choisissez une connexion Git existante.');
                $input['repository_connection_id']=$repositoryId;
            }
            $name = trim((string) ($input['name'] ?? ($old['name'] ?? '')));
            $key = strtoupper(trim((string) ($input['key'] ?? ($old['project_key'] ?? ''))));
            $description = (string) ($input['description'] ?? ($old['description'] ?? ''));
            $appearance=Appearance::validate($input['icon']??($old['icon']??'folder'),$input['color']??($old['color']??'#7166eb'));
            $color=$appearance['color'];
            if (
                !$name ||
                mb_strlen($name) > 120 ||
                !preg_match('/^[A-Z][A-Z0-9]{1,9}$/D', $key) ||
                mb_strlen($description) > 2000
            ) {
                throw new \InvalidArgumentException(
                    'Nom, clé de 2 à 10 lettres/chiffres ou description de projet invalide.',
                );
            }
            if ($old && $old['project_key'] !== $key) {
                throw new \InvalidArgumentException(
                    'La clé du projet est immuable pour préserver les références de tickets.',
                );
            }
            if (
                DB::one('SELECT id FROM projects WHERE project_key=? AND id<>?', [$key, $id ?? 0])
            ) {
                throw new \InvalidArgumentException('Cette clé de projet existe déjà.');
            }
            try {
                if ($id) {
                    if (array_key_exists('team_ids',$input)) Team::setProjectTeams($id,$input['team_ids']);
                    DB::run('UPDATE projects SET name=?,description=?,color=?,icon=? WHERE id=?', [
                        $name,
                        $description,
                        $color,
                        $appearance['icon'],
                        $id,
                    ]);
                    if (array_key_exists('repository_connection_id',$input)) {
                        $connection=(int)($input['repository_connection_id']??0);
                        if ($connection) RepositoryConnection::attach($id,$connection,true);
                        else DB::run('UPDATE projects SET repository_connection_id=NULL WHERE id=?',[$id]);
                    }
                    return $id;
                }
                $newId = DB::insert(
                    'INSERT INTO projects(name,project_key,description,color,icon) VALUES(?,?,?,?,?)',
                    [$name, $key, $description, $color, $appearance['icon']],
                );
                if (!empty($input['repository_connection_id'])) RepositoryConnection::attach($newId,(int)$input['repository_connection_id'],true);
                if (array_key_exists('team_ids',$input)) Team::setProjectTeams($newId,(array)$input['team_ids']);
                return $newId;
            } catch (\PDOException $e) {
                if (($e->errorInfo[1] ?? 0) === 1062) {
                    throw new \InvalidArgumentException('Cette clé de projet existe déjà.');
                }
                throw $e;
            }
        });
    }
    public static function deleteEmpty(int $id): void
    {
        self::find($id);
        if (DB::one('SELECT connection_id FROM project_connections WHERE project_id=? LIMIT 1',[$id])) throw new \InvalidArgumentException('Détachez les dépôts Git avant de supprimer un projet vide.');
        try {
            DB::run('DELETE FROM projects WHERE id=?', [$id]);
        } catch (\PDOException $e) {
            if (($e->errorInfo[1] ?? 0) === 1451) {
                throw new \InvalidArgumentException(
                    'Seul un projet sans données associées peut être supprimé.',
                );
            }
            throw $e;
        }
    }
    public static function all(): array
    {
        return DB::all('SELECT * FROM projects ORDER BY id');
    }
    public static function find(int $id): array
    {
        $p = DB::one('SELECT * FROM projects WHERE id=?', [$id]);
        if (!$p) {
            throw new \InvalidArgumentException('Projet introuvable.');
        }
        $p['team_ids']=array_map('intval',array_column(Team::forProject($id),'id'));
        return $p;
    }
    public static function members(): array
    {
        return DB::all(
            'SELECT id,name,email,role FROM users WHERE active=1 AND verified_at IS NOT NULL ORDER BY name',
        );
    }
}
