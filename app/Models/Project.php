<?php
declare(strict_types=1);
namespace App\Models;
use App\Core\DB;
/**
 * Projects (table projects): name, immutable key (prefix of ticket references such as ABC-12),
 * description, icon/color, the "validation required before closing" option, the primary Git
 * connection, and participating teams (via Team::setProjectTeams).
 *
 * The project row also serves as the per-project lock: other models take
 * "SELECT ... FROM projects ... FOR UPDATE" before changing tickets, rules, workflows or releases
 * of that project. A project can only be deleted when no other data references it.
 * A project can also be archived (archived_at): it keeps its data but is hidden from menus and selectors.
 */
final class Project
{
    /** Creates ($id null) or updates a project; fields missing from $input keep their current value. */
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
                    if (array_key_exists('comment_order',$input)) DB::run('UPDATE projects SET comment_order=? WHERE id=?',[self::commentOrder($input['comment_order']),$id]);
                    if (array_key_exists('require_ticket_validation',$input)) DB::run('UPDATE projects
                        SET require_ticket_validation=?
                        WHERE id=?',[(int)(bool)$input['require_ticket_validation'],$id]);
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
                if (!empty($input['require_ticket_validation'])) DB::run('UPDATE projects SET require_ticket_validation=1 WHERE id=?',[$newId]);
                if (array_key_exists('comment_order',$input)) DB::run('UPDATE projects SET comment_order=? WHERE id=?',[self::commentOrder($input['comment_order']),$newId]);
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
    /** Validates the display order of ticket comments: "asc" (oldest first) or "desc" (newest first). */
    private static function commentOrder(mixed $order): string
    {
        if (!in_array($order, ['asc', 'desc'], true)) {
            throw new \InvalidArgumentException('Ordre des commentaires invalide.');
        }
        return $order;
    }
    /** Deletes a project that has no attached repository and no dependent rows (relies on foreign keys, MySQL error 1451). */
    public static function deleteEmpty(int $id): void
    {
        self::find($id);
        if (DB::one('SELECT connection_id
            FROM project_connections
            WHERE project_id=?
            LIMIT 1',[$id])) throw new \InvalidArgumentException('Détachez les dépôts Git avant de supprimer un projet vide.');
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
    /** Active projects, offered in menus and selectors. Archived projects are left out (see archived()). */
    public static function all(): array
    {
        return DB::all('SELECT * FROM projects WHERE archived_at IS NULL ORDER BY id');
    }
    /** Archived projects, only listed in the settings project directory so an admin can reactivate them. */
    public static function archived(): array
    {
        return DB::all('SELECT * FROM projects WHERE archived_at IS NOT NULL ORDER BY name,id');
    }
    /**
     * Archives ($archived true) or reactivates a project. Its tickets and settings are kept;
     * an archived project only disappears from menus, selectors and "all projects" lists.
     * The last active project cannot be archived, so the workspace always has one to open.
     */
    public static function setArchived(int $id, bool $archived): array
    {
        return DB::transaction(function () use ($id, $archived) {
            // Lock every project row in id order: two admins archiving the last two projects at once
            // are serialized instead of both seeing another active project.
            $rows = array_column(DB::all('SELECT id,archived_at FROM projects ORDER BY id FOR UPDATE'), null, 'id');
            $row = $rows[$id] ?? null;
            if (!$row) {
                throw new \InvalidArgumentException('Projet introuvable.');
            }
            if ($archived && $row['archived_at'] === null) {
                $active = array_filter($rows, static fn($r) => $r['archived_at'] === null);
                if (count($active) <= 1) {
                    throw new \InvalidArgumentException('Gardez au moins un projet actif : créez ou réactivez un autre projet avant d’archiver celui-ci.');
                }
                DB::run('UPDATE projects SET archived_at=UTC_TIMESTAMP() WHERE id=?', [$id]);
            } elseif (!$archived) {
                DB::run('UPDATE projects SET archived_at=NULL WHERE id=?', [$id]);
            }
            return self::find($id);
        });
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
    /** All active, verified users (installation-wide, not filtered by project). */
    public static function members(): array
    {
        return DB::all(
            'SELECT id,name,email,role FROM users WHERE active=1 AND verified_at IS NOT NULL ORDER BY name',
        );
    }
}
