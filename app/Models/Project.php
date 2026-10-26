<?php
declare(strict_types=1);
namespace App\Models;
use App\Core\DB;
final class Project
{
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
        return $p;
    }
    public static function members(): array
    {
        return DB::all(
            'SELECT id,name,email,role FROM users WHERE active=1 AND verified_at IS NOT NULL ORDER BY name',
        );
    }
}
