<?php
declare(strict_types=1);
namespace App\Models;
use App\Core\DB;
final class SavedView
{
    public static function revision(array $row): string {return hash('sha256',$row['name']."\0".$row['settings']);}
    public static function save(int $user, int $project, string $name, array $settings,int $id=0,?string $revision=null): int
    {
        Project::find($project);
        $name = trim($name);
        if (!$name || mb_strlen($name) > 120) {
            throw new \InvalidArgumentException(
                'Donnez un nom à votre rapport (120 caractères maximum).',
            );
        }
        $settings=\App\Services\ListLayout::settings($project,$settings);
        if($id) return DB::transaction(function() use($id,$user,$project,$name,$settings,$revision) {
            $old=DB::one('SELECT * FROM saved_views WHERE id=? AND user_id=? AND project_id=? FOR UPDATE',[$id,$user,$project]);
            if(!$old) throw new \InvalidArgumentException('Rapport personnel introuvable.');
            if($revision===null || !hash_equals(self::revision($old),$revision)) throw new \InvalidArgumentException('Ce rapport a changé dans un autre onglet. Rechargez-le avant de le modifier.');
            DB::run('UPDATE saved_views SET name=?,settings=? WHERE id=?',[$name,json_encode($settings,JSON_THROW_ON_ERROR),$id]);return $id;
        });
        return DB::insert(
            'INSERT INTO saved_views(user_id,project_id,name,settings) VALUES(?,?,?,?)',
            [$user, $project, $name, json_encode($settings, JSON_THROW_ON_ERROR)],
        );
    }
    public static function delete(int $id, int $user): void
    {
        if (
            !DB::run('DELETE FROM saved_views WHERE id=? AND user_id=?', [$id, $user])->rowCount()
        ) {
            throw new \InvalidArgumentException('Rapport personnel introuvable.');
        }
    }
}
