<?php
declare(strict_types=1);
namespace App\Models;
use App\Core\DB;

/** Active project membership lives in project_connections; connections.project_id retains creation provenance. */
final class RepositoryConnection
{
    public static function all(): array
    {
        return DB::all('SELECT id,provider,repository,last_sync_at FROM connections ORDER BY provider,repository,id');
    }
    public static function forProject(int $project): array
    {
        return DB::all('SELECT c.id,c.provider,c.repository,c.last_sync_at FROM connections c JOIN project_connections pc ON pc.connection_id=c.id WHERE pc.project_id=? ORDER BY c.id',[$project]);
    }
    public static function contextProject(int $connection,int $preferred=0): int
    {
        if ($preferred && DB::one('SELECT project_id FROM project_connections WHERE project_id=? AND connection_id=?',[$preferred,$connection])) return $preferred;
        $row=DB::one('SELECT project_id FROM project_connections WHERE connection_id=? ORDER BY project_id LIMIT 1',[$connection]);
        if (!$row) throw new \InvalidArgumentException('Cette connexion Git n’est liée à aucun projet.');
        return (int)$row['project_id'];
    }
    public static function attach(int $project,int $connection,bool $primary=false): void
    {
        DB::transaction(function() use($project,$connection,$primary) {
            if (!DB::one('SELECT id FROM projects WHERE id=? FOR UPDATE',[$project])) throw new \InvalidArgumentException('Projet introuvable.');
            if (!DB::one('SELECT id FROM connections WHERE id=? FOR UPDATE',[$connection])) throw new \InvalidArgumentException('Connexion Git introuvable.');
            DB::run('INSERT IGNORE INTO project_connections(project_id,connection_id) VALUES(?,?)',[$project,$connection]);
            if ($primary) DB::run('UPDATE projects SET repository_connection_id=? WHERE id=?',[$connection,$project]);
        });
    }
    public static function detach(int $project,int $connection): void
    {
        DB::transaction(function() use($project,$connection) {
            DB::one('SELECT id FROM projects WHERE id=? FOR UPDATE',[$project]);
            DB::one('SELECT id FROM connections WHERE id=? FOR UPDATE',[$connection]);
            if (!DB::run('DELETE FROM project_connections WHERE project_id=? AND connection_id=?',[$project,$connection])->rowCount()) throw new \InvalidArgumentException('Ce dépôt n’est pas lié au projet.');
            DB::run('UPDATE projects SET repository_connection_id=NULL WHERE id=? AND repository_connection_id=?',[$project,$connection]);
            if (!DB::one('SELECT project_id FROM project_connections WHERE connection_id=? LIMIT 1',[$connection])) DB::run('DELETE FROM connections WHERE id=?',[$connection]);
        });
    }
}
