<?php
declare(strict_types=1);
namespace App\Services;
/** Isolated fixture only: production Git HTTP functions are exercised with synthetic provider replies. */
function curl_init(?string $url=null): object {return (object)['url'=>$url,'status'=>200,'options'=>[]];}
function curl_setopt_array(object $handle,array $options): bool {$handle->options=array_replace($handle->options,$options);return true;}
function curl_setopt(object $handle,int $option,mixed $value): bool {$handle->options[$option]=$value;return true;}
function curl_getinfo(object $handle,?int $option=null): mixed {return $option===CURLINFO_HTTP_CODE?$handle->status:[];}
function curl_errno(object $handle): int {return 0;}
function curl_close(object $handle): void {}
function branch_fixture_items(string $search=''): array
{
    $items=[['name'=>'main','date'=>'2024-01-01T00:00:00Z'],['name'=>'feature/sitemap-sans-cle','date'=>'2026-11-01T08:00:00Z'],['name'=>'staging','date'=>'2026-11-02T09:00:00Z'],['name'=>'40908_flow_sitemap','date'=>'2026-11-03T10:00:00Z'],['name'=>'production','date'=>'2026-10-01T10:00:00Z'],['name'=>'sans-date','date'=>null]];
    for($i=1;$i<=50;$i++) $items[]=['name'=>'archive/branche-'.$i,'date'=>(new \DateTimeImmutable('2025-01-01T00:00:00Z'))->modify('+'.$i.' days')->format('c')];
    return array_values(array_filter($items,fn($item)=>$search==='' || stripos($item['name'],$search)!==false));
}
function curl_exec(object $handle): string|false
{
    $url=parse_url($handle->url);$path=$url['path']??'';parse_str($url['query']??'',$query);
    if (!in_array($url['host']??'',['api.github.com','api.bitbucket.org'],true) || ($url['scheme']??'')!=='https') throw new \RuntimeException('Fixture Git : destination inattendue.');
    $headers=$handle->options[CURLOPT_HTTPHEADER]??[];
    if (($handle->options[CURLOPT_FOLLOWLOCATION]??true)!==false || ($handle->options[CURLOPT_SSL_VERIFYPEER]??false)!==true || ($handle->options[CURLOPT_PROTOCOLS_STR]??'')!=='https') throw new \RuntimeException('Fixture Git : paramètres de transport inattendus.');
    if (!in_array('Authorization: Bearer synthetic-git-token',$headers,true)) {$handle->status=401;return '{}';}
    $github=$url['host']==='api.github.com';
    if($github && $path==='/graphql') {
        if(empty($handle->options[CURLOPT_POST])) throw new \RuntimeException('Fixture GraphQL : POST requis.');
        $body=json_decode($handle->options[CURLOPT_POSTFIELDS],true,64,JSON_THROW_ON_ERROR);$variables=$body['variables'];$search=$variables['search']??'';
        if(($variables['owner']??'')!=='demo' || !preg_match('/^github-[a-f0-9]+$/D',$variables['repository']??'')) throw new \RuntimeException('Fixture GraphQL : dépôt inattendu.');
        if($search==='graphql-error') return '{"errors":[{"message":"refused"}]}';
        $all=branch_fixture_items($search);$offset=$variables['after']===null?0:(int)substr($variables['after'],7);
        $items=array_slice($all,$offset,20);$more=$offset+count($items)<count($all);$cursor='cursor-'.($offset+count($items));
        if($search==='cursor-loop') {$more=true;$cursor='cursor-0';}
        $nodes=array_map(fn($item)=>['name'=>$item['name'],'target'=>['committedDate'=>$item['date']]],$items);
        return json_encode(['data'=>['repository'=>['refs'=>['nodes'=>$nodes,'pageInfo'=>['hasNextPage'=>$more,'endCursor'=>$cursor]]]]],JSON_THROW_ON_ERROR);
    }
    $pattern=$github?'#^(/repos/demo/github(?:-[a-f0-9]+)?)(?:/|$)#D':'#^(/2\.0/repositories/demo/bitbucket(?:-[a-f0-9]+)?)(?:/|$)#D';
    if(!preg_match($pattern,$path,$matches)) {$handle->status=404;return '{}';}$prefix=$matches[1];
    if ($path===$prefix || $path===$prefix.'/pulls' || $path===$prefix.'/pullrequests') return $path===$prefix?'{}':($github?'[]':'{"values":[]}');
    $branches=$prefix.($github?'/branches':'/refs/branches');
    if ($path===$branches) {
        if($github) throw new \RuntimeException('Le catalogue GitHub doit utiliser les dates GraphQL.');
        if(($query['sort']??'')!=='-target.date') throw new \RuntimeException('Fixture Bitbucket : tri chronologique requis.');
        $search='';if(isset($query['q'])) $search=json_decode(substr($query['q'],7),true,64,JSON_THROW_ON_ERROR);
        $all=branch_fixture_items($search);$page=(int)($query['page']??1);$items=array_slice($all,($page-1)*20,20);
        $response=['values'=>array_map(fn($item)=>['name'=>$item['name'],'target'=>['date'=>$item['date']]],$items)];
        if($page*20<count($all)) $response['next']='https://api.bitbucket.org'.$branches.'?page='.($page+1);
        return json_encode($response,JSON_THROW_ON_ERROR);
    }
    if (str_starts_with($path,$branches.'/')) {
        $name=rawurldecode(substr($path,strlen($branches)+1));
        if (!in_array($name,array_merge(array_column(branch_fixture_items(),'name'),['unexpected-name']),true)) {$handle->status=404;return '{}';}
        if($name==='unexpected-name') $name='different-branch';
        return json_encode(['name'=>$name,$github?'commit':'target'=>[$github?'sha':'hash'=>str_repeat($github?'a':'b',40)]],JSON_THROW_ON_ERROR);
    }
    $handle->status=404;return '{}';
}
