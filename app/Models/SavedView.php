<?php
declare(strict_types=1);
namespace App\Models;
use App\Core\DB;
use App\Services\Filter;
final class SavedView
{
    public static function save(int $user, int $project, string $name, array $settings): int
    {
        Project::find($project);
        $name = trim($name);
        if (!$name || mb_strlen($name) > 120) {
            throw new \InvalidArgumentException(
                'Donnez un nom à votre vue (120 caractères maximum).',
            );
        }
        Filter::where($project, $settings);
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
            throw new \InvalidArgumentException('Vue introuvable.');
        }
    }
}
