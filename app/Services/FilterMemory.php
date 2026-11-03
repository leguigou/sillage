<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\Security;
/** User/project scoped preferences; the persistent cookie is encrypted and bounded. */
final class FilterMemory
{
    public static function resolve(int $user,int $project,array $input): array
    {
        $name=Security::sessionName().'_filters_'.$user;$key=(string)$project;
        $memory=$_SESSION['ticket_filters'][$user]??null;
        if(!is_array($memory)) {
            $memory=[];
            try {$stored=json_decode(Security::decrypt((string)($_COOKIE[$name]??'')),true,32,JSON_THROW_ON_ERROR);if(($stored['expires']??0)>time() && is_array($stored['projects']??null)) $memory=$stored['projects'];} catch(\Throwable) {}
        }
        $explicit=array_diff_key($input,array_flip(['project','page','scale','from','to','filters_reset']));
        if(!empty($input['filters_reset'])) {$memory[$key]=[];$result=$input;}
        elseif($explicit || isset($input['view'])) {$result=$input;$memory[$key]=ListLayout::settings($project,$input);}
        else {$saved=$memory[$key]??[];try {$saved=ListLayout::settings($project,$saved);} catch(\InvalidArgumentException) {$saved=[];}$result=array_replace($saved,$input);}
        unset($result['filters_reset']);
        $memory=array_slice($memory,-20,null,true);$_SESSION['ticket_filters'][$user]=$memory;
        $persistent=array_slice($memory,-5,null,true);$expires=time()+30*86400;
        while(strlen(json_encode($persistent,JSON_THROW_ON_ERROR))>1800 && $persistent) unset($persistent[array_key_first($persistent)]);
        setcookie($name,Security::encrypt(json_encode(['expires'=>$expires,'projects'=>$persistent],JSON_THROW_ON_ERROR)),['expires'=>$expires,'path'=>'/','secure'=>env('SESSION_SECURE','true')==='true','httponly'=>true,'samesite'=>'Lax']);
        return $result;
    }
}
