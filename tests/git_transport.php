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
function git_fixture_pr(bool $github,string $repo,int $id=1387,bool $foreign=false): array
{
    $sha=str_repeat('c',40);$target=str_repeat('a',40);$source=$foreign?'fork/other':$repo;
    if($github) return ['number'=>$id,'title'=>'40908 - correction du sitemap','state'=>'closed','merged_at'=>$id===1500?null:'2026-11-02T12:00:00Z','updated_at'=>'2026-11-02T12:00:00Z','head'=>['ref'=>'40908_flow_sitemap','sha'=>$sha,'repo'=>['full_name'=>$source]],'base'=>['ref'=>'staging','sha'=>$target],'html_url'=>'https://github.com/'.$repo.'/pull/'.$id,'commits'=>$id===1500?300:2,'changed_files'=>$id===1500?42:2];
    return ['id'=>$id,'title'=>'40908 - correction du sitemap','state'=>$id===1500?'DECLINED':'MERGED','updated_on'=>'2026-11-02T12:00:00Z','source'=>['branch'=>['name'=>'40908_flow_sitemap'],'commit'=>['hash'=>$sha],'repository'=>['full_name'=>$source]],'destination'=>['branch'=>['name'=>'staging'],'commit'=>['hash'=>$target]],'links'=>['html'=>['href'=>'https://bitbucket.org/'.$repo.'/pull-requests/'.$id]]];
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
    $repo=substr($prefix,$github?7:18);$pulls=$prefix.($github?'/pulls':'/pullrequests');
    if($path===$pulls && isset($query['page']) && ($query['per_page']??$query['pagelen']??'')==='20') {
        if($github && (($query['state']??'')!=='all' || ($query['head']??'')!=='demo:40908_flow_sitemap')) return '[]';
        if(!$github && (!str_contains($handle->url,'state=MERGED') || !str_contains($query['q']??'','40908_flow_sitemap'))) return '{"values":[]}';
        $page=(int)$query['page'];$rows=$page===1?[git_fixture_pr($github,$repo)]:($page===2?[git_fixture_pr($github,$repo,1500)]:[]);
        if($page===1) for($i=1388;$i<=1406;$i++) $rows[]=git_fixture_pr($github,$repo,$i,true);
        return json_encode($github?$rows:['values'=>$rows]+($page===1?['next'=>'https://api.bitbucket.org'.$pulls.'?page=2']:[]),JSON_THROW_ON_ERROR);
    }
    if(preg_match('#^'.preg_quote($pulls,'#').'/(1387|1500)(?:/(commits|files|diffstat))?$#D',$path,$prMatch)) {
        $id=(int)$prMatch[1];$kind=$prMatch[2]??'';
        if($kind==='') return json_encode(git_fixture_pr($github,$repo,$id),JSON_THROW_ON_ERROR);
        if($kind==='commits') {
            if($github && $id===1500) {
                $rows=[];for($i=1;$i<=250;$i++) $rows[]=['sha'=>str_pad(dechex($i),40,'0',STR_PAD_LEFT),'commit'=>['message'=>'Historique '.$i,'author'=>['name'=>'Auteur Exemple','date'=>'2026-11-01T10:00:00Z']]];
                return json_encode(array_slice($rows,((int)($query['page']??1)-1)*100,100),JSON_THROW_ON_ERROR);
            }
            $rows=[];foreach(['c','d'] as $i=>$letter) $rows[]=$github?['sha'=>str_repeat($letter,40),'commit'=>['message'=>'40908 - '.($i?'correction du sitemap':'fix URL flux XML'),'author'=>['name'=>'Guillaume Exemple','date'=>'2026-11-02T'.($i?'10':'11').':00:00Z']]]:['hash'=>str_repeat($letter,40),'message'=>'40908 - '.($i?'correction du sitemap':'fix URL flux XML'),'author'=>['user'=>['display_name'=>'Guillaume Exemple']],'date'=>'2026-11-02T'.($i?'10':'11').':00:00Z'];
            return json_encode($github?$rows:['values'=>[$rows[(int)($query['page']??1)-1]]]+((int)($query['page']??1)===1?['next'=>'https://api.bitbucket.org'.$pulls.'/'.$id.'/commits?page=2']:[]),JSON_THROW_ON_ERROR);
        }
        if($kind==='files' && $github) {
            $files=[['filename'=>'app/code/Example/Flow/Cron/Sitemap.php','status'=>'modified','additions'=>580,'deletions'=>185,'patch'=>'DO_NOT_STORE_CODE'],['filename'=>'docs/sitemap.md','previous_filename'=>'docs/old.md','status'=>'renamed','additions'=>0,'deletions'=>0,'patch'=>'DO_NOT_STORE_CODE']];
            if($id===1500) for($i=1;$i<=40;$i++) $files[]=['filename'=>'docs/page-'.$i.'.md','status'=>'added','additions'=>1,'deletions'=>0];
            return json_encode($files,JSON_THROW_ON_ERROR);
        }
        if($kind==='diffstat' && !$github) {
            $handle->status=302;$location=$id===1500?'https://evil.example/stolen':'https://api.bitbucket.org'.$prefix.'/diffstat/'.str_repeat('c',40).'..'.str_repeat('a',40).'?from_pullrequest_id=1387&topic=true';
            ($handle->options[CURLOPT_HEADERFUNCTION])($handle,'Location: '.$location."\r\n");return '';
        }
    }
    if(!$github && str_starts_with($path,$prefix.'/diffstat/')) {
        if(($query['topic']??'')!=='true' || ($query['from_pullrequest_id']??'')!=='1387') throw new \RuntimeException('Fixture : comparaison de PR attendue.');
        $page=(int)($query['page']??1);$file=$page===1?['status'=>'modified','new'=>['path'=>'app/code/Example/Flow/Cron/Sitemap.php'],'old'=>['path'=>'app/code/Example/Flow/Cron/Sitemap.php'],'lines_added'=>580,'lines_removed'=>185]:['status'=>'renamed','new'=>['path'=>'docs/sitemap.md'],'old'=>['path'=>'docs/old.md'],'lines_added'=>0,'lines_removed'=>0];
        return json_encode(['values'=>[$file]]+($page===1?['next'=>'https://api.bitbucket.org'.$path.'?page=2']:[]),JSON_THROW_ON_ERROR);
    }
    if ($path===$prefix || $path===$pulls) return $path===$prefix?'{}':($github?'[]':'{"values":[]}');
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
