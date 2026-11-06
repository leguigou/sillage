<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\Security;
/**
 * User/project scoped preferences; the persistent cookie is encrypted and bounded.
 * Remembers the last list filters a user applied per project, so returning to a view restores them.
 * Called by WorkspaceController. Storage: the PHP session (up to 20 projects) plus a cookie encrypted
 * with Security::encrypt (up to 5 projects, about 1.8 KB of JSON, 30-day expiry). Saved settings are
 * re-validated through ListLayout::settings() when read back; invalid ones are silently dropped.
 */
final class FilterMemory
{
    /** True when the request carries filter parameters beyond navigation ones (project, page, timeline range). */
    public static function hasExplicitFilters(array $input): bool
    {
        return (bool) array_diff_key($input, array_flip(['project','page','scale','from','to','filters_reset']));
    }

    /**
     * Returns the filters to apply: explicit input is used and remembered, "filters_reset" clears the
     * memory for the project, otherwise the remembered filters are merged under the current input.
     * Always rewrites the session entry and the cookie (so it must run before output is sent).
     */
    public static function resolve(int $user,int $project,array $input): array
    {
        $name=Security::sessionName().'_filters_'.$user;$key=(string)$project;
        $memory=$_SESSION['ticket_filters'][$user]??null;
        // Fall back to the cookie on a new session; an undecryptable, malformed or expired cookie is ignored.
        if(!is_array($memory)) {
            $memory=[];
            try {$stored=json_decode(Security::decrypt((string)($_COOKIE[$name]??'')),true,32,JSON_THROW_ON_ERROR);if(($stored['expires']??0)>time() && is_array($stored['projects']??null)) $memory=$stored['projects'];} catch(\Throwable) {}
        }
        if(!empty($input['filters_reset'])) {$memory[$key]=[];$result=$input;}
        elseif(self::hasExplicitFilters($input)) {$result=$input;$memory[$key]=ListLayout::settings($project,$input);}
        else {$saved=$memory[$key]??[];try {$saved=ListLayout::settings($project,$saved);} catch(\InvalidArgumentException) {$saved=[];}$result=array_replace($saved,$input);}
        unset($result['filters_reset']);
        $memory=array_slice($memory,-20,null,true);$_SESSION['ticket_filters'][$user]=$memory;
        // Keep the cookie small: oldest projects are dropped until the JSON fits.
        $persistent=array_slice($memory,-5,null,true);$expires=time()+30*86400;
        while(strlen(json_encode($persistent,JSON_THROW_ON_ERROR))>1800 && $persistent) unset($persistent[array_key_first($persistent)]);
        setcookie($name,Security::encrypt(json_encode(['expires'=>$expires,'projects'=>$persistent],JSON_THROW_ON_ERROR)),['expires'=>$expires,'path'=>'/','secure'=>env('SESSION_SECURE','true')==='true','httponly'=>true,'samesite'=>'Lax']);
        return $result;
    }
}
