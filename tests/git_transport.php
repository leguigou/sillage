<?php
declare(strict_types=1);
namespace App\Services;
/** Isolated fixture only: production Git HTTP functions are exercised with synthetic provider replies. */
function curl_init(?string $url=null): object {return (object)['url'=>$url,'status'=>200,'options'=>[]];}
function curl_setopt_array(object $handle,array $options): bool {$handle->options=$options;return true;}
function curl_setopt(object $handle,int $option,mixed $value): bool {$handle->options[$option]=$value;return true;}
function curl_getinfo(object $handle,?int $option=null): mixed {return $option===CURLINFO_HTTP_CODE?$handle->status:[];}
function curl_errno(object $handle): int {return 0;}
function curl_close(object $handle): void {}
function curl_exec(object $handle): string|false
{
    $url=parse_url($handle->url);$path=$url['path']??'';parse_str($url['query']??'',$query);
    if (!in_array($url['host']??'',['api.github.com','api.bitbucket.org'],true) || ($url['scheme']??'')!=='https') throw new \RuntimeException('Fixture Git : destination inattendue.');
    $headers=$handle->options[CURLOPT_HTTPHEADER]??[];
    if (($handle->options[CURLOPT_FOLLOWLOCATION]??true)!==false || ($handle->options[CURLOPT_SSL_VERIFYPEER]??false)!==true || ($handle->options[CURLOPT_PROTOCOLS_STR]??'')!=='https') throw new \RuntimeException('Fixture Git : paramètres de transport inattendus.');
    if (!in_array('Authorization: Bearer synthetic-git-token',$headers,true)) {$handle->status=401;return '{}';}
    $github=$url['host']==='api.github.com';
    $pattern=$github?'#^(/repos/demo/github(?:-[a-f0-9]+)?)(?:/|$)#D':'#^(/2\.0/repositories/demo/bitbucket(?:-[a-f0-9]+)?)(?:/|$)#D';
    if(!preg_match($pattern,$path,$matches)) {$handle->status=404;return '{}';}$prefix=$matches[1];
    if ($path===$prefix || $path===$prefix.'/pulls' || $path===$prefix.'/pullrequests') return $path===$prefix?'{}':($github?'[]':'{"values":[]}');
    $branches=$prefix.($github?'/branches':'/refs/branches');
    if ($path===$branches) {
        $items=[['name'=>'feature/sitemap-sans-cle'],['name'=>'main']];
        if((int)($query['page']??1)>1) $items=[];
        return json_encode($github?$items:['values'=>$items],JSON_THROW_ON_ERROR);
    }
    if (str_starts_with($path,$branches.'/')) {
        $name=rawurldecode(substr($path,strlen($branches)+1));
        if (!in_array($name,['feature/sitemap-sans-cle','main','unexpected-name'],true)) {$handle->status=404;return '{}';}
        if($name==='unexpected-name') $name='different-branch';
        return json_encode(['name'=>$name,$github?'commit':'target'=>[$github?'sha':'hash'=>str_repeat($github?'a':'b',40)]],JSON_THROW_ON_ERROR);
    }
    $handle->status=404;return '{}';
}
