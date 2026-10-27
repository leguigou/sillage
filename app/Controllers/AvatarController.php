<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\{Auth, DB};

final class AvatarController
{
    public function image(string $id, string $digest): void
    {
        Auth::requireUser();
        if (!ctype_digit($id) || !preg_match('/^[a-f0-9]{64}$/D',$digest)) { http_response_code(404); return; }
        $photo = DB::one('SELECT image_data,mime FROM user_avatars WHERE user_id=? AND digest=?',[(int)$id,$digest]);
        if (!$photo || !in_array($photo['mime'],['image/png','image/jpeg','image/webp'],true)) { http_response_code(404); return; }
        header('Content-Type: '.$photo['mime']);
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: inline; filename="avatar"');
        header('Content-Length: '.strlen($photo['image_data']));
        echo $photo['image_data'];
    }
}
