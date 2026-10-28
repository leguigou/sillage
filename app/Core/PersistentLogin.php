<?php
declare(strict_types=1);
namespace App\Core;

/** Thirty-day bearer credential, independent of ephemeral PHP session storage. */
final class PersistentLogin
{
    public const LIFETIME=30*86400;
    public static function cookieName(): string
    {
        return Security::sessionName().'_remember';
    }
    private static function fingerprint(string $validator): string
    {
        return hash_hmac('sha256',self::cookieName().':'.$validator,env('APP_KEY'));
    }
    public static function issue(array $user,int $expires,?string $replaceSelector=null): ?array
    {
        return DB::transaction(function() use($user,$expires,$replaceSelector) {
            $current=DB::one('SELECT id,active,verified_at,auth_version FROM users WHERE id=? FOR UPDATE',[$user['id']]);
            if (!$current || !$current['active'] || !$current['verified_at'] || (int)$current['auth_version']!==(int)$user['auth_version'] || $expires<=time()) return null;
            if ($replaceSelector!==null) DB::run('DELETE FROM persistent_logins WHERE selector=? AND user_id=?',[$replaceSelector,$user['id']]);
            DB::run('DELETE FROM persistent_logins WHERE expires_at<=UTC_TIMESTAMP() LIMIT 100');
            $selector=bin2hex(random_bytes(12));$validator=bin2hex(random_bytes(32));
            DB::run('INSERT INTO persistent_logins(selector,validator_hash,user_id,auth_version,expires_at) VALUES(?,?,?,?,?)',[$selector,self::fingerprint($validator),$user['id'],$user['auth_version'],gmdate('Y-m-d H:i:s',$expires)]);
            return ['cookie'=>$selector.':'.$validator,'selector'=>$selector,'expires'=>$expires];
        });
    }
    public static function restore(string $cookie): ?array
    {
        $parts=self::parse($cookie);
        if (!$parts) return null;
        [$selector,$validator]=$parts;
        return DB::transaction(function() use($selector,$validator) {
            // All account changes lock the user before credentials; use the same order here.
            $identity=DB::one('SELECT user_id FROM persistent_logins WHERE selector=?',[$selector]);
            if (!$identity || !DB::one('SELECT id FROM users WHERE id=? FOR UPDATE',[$identity['user_id']])) return null;
            $row=DB::one('SELECT r.validator_hash,r.auth_version remembered_version,r.expires_at,u.id,u.email,u.name,u.role,u.verified_at,u.active,u.auth_version FROM persistent_logins r JOIN users u ON u.id=r.user_id WHERE r.selector=? FOR UPDATE',[$selector]);
            if (!$row) return null;
            if (!hash_equals($row['validator_hash'],self::fingerprint($validator))) return null;
            $expires=strtotime($row['expires_at'].' UTC');
            if ($expires<=time() || !$row['active'] || !$row['verified_at'] || (int)$row['remembered_version']!==(int)$row['auth_version']) {
                DB::run('DELETE FROM persistent_logins WHERE selector=?',[$selector]);return null;
            }
            DB::run('UPDATE persistent_logins SET last_used_at=UTC_TIMESTAMP() WHERE selector=?',[$selector]);
            unset($row['validator_hash'],$row['remembered_version'],$row['expires_at']);
            return ['user'=>$row,'selector'=>$selector,'expires'=>$expires];
        });
    }
    private static function parse(string $cookie): ?array
    {
        return preg_match('/^([a-f0-9]{24}):([a-f0-9]{64})$/D',$cookie,$match)?[$match[1],$match[2]]:null;
    }
    public static function revoke(string $cookie): void
    {
        $parts=self::parse($cookie);
        if ($parts) DB::run('DELETE FROM persistent_logins WHERE selector=? AND validator_hash=?',[$parts[0],self::fingerprint($parts[1])]);
    }
    public static function revokeAll(int $user): void
    {
        DB::run('DELETE FROM persistent_logins WHERE user_id=?',[$user]);
    }
    public static function setCookie(string $value,int $expires): void
    {
        setcookie(self::cookieName(),$value,['expires'=>$expires,'path'=>'/','secure'=>env('SESSION_SECURE','true')==='true','httponly'=>true,'samesite'=>'Lax']);
        if ($value==='') unset($_COOKIE[self::cookieName()]);
        else $_COOKIE[self::cookieName()]=$value;
    }
    public static function forget(): void
    {
        self::revoke((string)($_COOKIE[self::cookieName()]??''));
        self::setCookie('',time()-3600);
    }
}
