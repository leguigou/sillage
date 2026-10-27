<?php
declare(strict_types=1);
namespace App\Models;
use App\Core\DB;

final class UserAvatar
{
    private static array $digests = [];

    public static function prime(array $ids): void
    {
        $ids = array_values(array_filter(array_unique(array_map('intval',$ids)),fn($id)=>$id>0 && !array_key_exists($id,self::$digests)));
        foreach (array_chunk($ids,500) as $chunk) {
            foreach ($chunk as $id) self::$digests[$id] = null;
            foreach (DB::all('SELECT user_id,digest FROM user_avatars WHERE user_id IN (' . implode(',',array_fill(0,count($chunk),'?')) . ')',$chunk) as $row) {
                self::$digests[(int)$row['user_id']] = $row['digest'];
            }
        }
    }

    public static function url(?int $id): ?string
    {
        if (!$id) return null;
        self::prime([$id]);
        return self::$digests[$id] ? '/avatars/' . $id . '/' . self::$digests[$id] : null;
    }

    public static function saveProfile(int $id, string $name, ?array $image, bool $remove): void
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name)>120 || preg_match('/[\x00-\x1f\x7f]/u',$name)) throw new \InvalidArgumentException('Nom invalide.');
        if ($remove && $image) throw new \InvalidArgumentException('Choisissez une nouvelle photo ou retirez la photo actuelle, sans combiner les deux.');
        DB::transaction(function () use ($id,$name,$image,$remove) {
            if (!DB::one('SELECT id FROM users WHERE id=? FOR UPDATE',[$id])) throw new \InvalidArgumentException('Compte introuvable.');
            DB::run('UPDATE users SET name=? WHERE id=?',[$name,$id]);
            if ($remove) DB::run('DELETE FROM user_avatars WHERE user_id=?',[$id]);
            elseif ($image) DB::run('INSERT INTO user_avatars(user_id,image_data,mime,digest) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE image_data=?,mime=?,digest=?',[$id,$image['data'],$image['mime'],$image['digest'],$image['data'],$image['mime'],$image['digest']]);
        });
        unset(self::$digests[$id]);
    }
}
