<?php
declare(strict_types=1);
namespace App\Services;

/**
 * Complete, bounded snapshots: paging never turns an alphabetical subset into a claimed global date sort.
 * Branch picker data for linking a branch to a ticket (called by TicketDevelopmentController).
 * load() fetches every matching branch (GitHub GraphQL or Bitbucket REST, at most MAX_BRANCHES, about
 * 20 s budget), sorts them by last commit date, and the snapshot is cached in the PHP session for TTL
 * seconds (3 snapshots max). page() then serves 50-branch slices of that snapshot. If the full list
 * cannot be loaded, an error asks the user to narrow the search instead of showing a partial list.
 */
final class GitBranchCatalog
{
    public const MAX_BRANCHES=2000;
    public const TTL=120;
    /** Strict ISO 8601 timestamp to UTC "Y-m-d\TH:i:s\Z", or null when absent or malformed. */
    public static function date(mixed $value): ?string
    {
        if (!is_string($value) || strlen($value)>64 || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D',$value)) return null;
        try {$date=new \DateTimeImmutable($value);$errors=\DateTimeImmutable::getLastErrors();if($errors && ($errors['warning_count'] || $errors['error_count'])) return null;return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');} catch(\Exception) {return null;}
    }
    /** Most recent commit first, branches without a date last, then by natural name order. */
    public static function sorted(array $items): array
    {
        usort($items,static function(array $a,array $b): int {
            if($a['last_commit_at']!==$b['last_commit_at']) return $a['last_commit_at']===null?1:($b['last_commit_at']===null?-1:strcmp($b['last_commit_at'],$a['last_commit_at']));
            return strnatcasecmp($a['name'],$b['name'])?:strcmp($a['name'],$b['name']);
        });return $items;
    }
    public static function page(array $connection,int $page,string $search='',bool $refresh=false): array
    {
        if($page<1 || $page>10000 || mb_strlen($search)>100 || preg_match('/[\x00-\x1f\x7f]/',$search)) throw new \InvalidArgumentException('Page ou recherche de branches invalide.');
        $search=trim($search);
        // The cache key includes the encrypted token, so changing the connection's credential invalidates it.
        $key=hash('sha256',json_encode([$connection['id'],$connection['provider'],$connection['repository'],$connection['secret_cipher'],$search],JSON_THROW_ON_ERROR));
        $cache=$_SESSION['git_branch_catalogs']??[];
        foreach($cache as $id=>$snapshot) if(time()-$snapshot['at']>=self::TTL) unset($cache[$id]);
        if($refresh) unset($cache[$key]);
        if(!isset($cache[$key])) {
            $items=self::load($connection,$search);
            while(count($cache)>=3) array_shift($cache);
            $cache[$key]=['at'=>time(),'items'=>$items];
        }
        $_SESSION['git_branch_catalogs']=$cache;$snapshot=$cache[$key];$total=count($snapshot['items']);
        $items=array_slice($snapshot['items'],($page-1)*50,50);
        return ['branches'=>array_column($items,'name'),'items'=>$items,'page'=>$page,'has_more'=>$page*50<$total,'total'=>$total,'fetched_at'=>gmdate('Y-m-d\TH:i:s\Z',$snapshot['at'])];
    }
    private static function load(array $connection,string $search): array
    {
        if(function_exists('set_time_limit')) @set_time_limit(30);
        $github=$connection['provider']==='github';$cursor=null;$items=[];$seen=[];$started=microtime(true);
        // Each request's timeout is capped by what remains of the overall 20-second budget.
        for($page=1;$page<=100;$page++) {
            $remaining=20-(microtime(true)-$started);
            if($remaining<1) throw new \InvalidArgumentException('Le catalogue prend trop de temps à charger. Précisez la recherche ou saisissez le nom exact.');
            if($github) {
                $response=Development::githubBranchRefs($connection,$search,$cursor,min(10,(int)ceil($remaining)));
                $refs=$response['data']['repository']['refs']??null;
                if(!is_array($refs) || !is_array($refs['nodes']??null) || !is_bool($refs['pageInfo']['hasNextPage']??null)) throw new \InvalidArgumentException('GitHub n’a pas fourni un catalogue de branches complet. Vérifiez les droits de lecture du contenu.');
                $rows=$refs['nodes'];$more=$refs['pageInfo']['hasNextPage'];$next=$refs['pageInfo']['endCursor']??null;
            } else {
                // The search text is JSON-quoted inside Bitbucket's query language, then URL-encoded.
                $query=['pagelen'=>100,'page'=>$page,'sort'=>'-target.date'];if($search!=='') $query['q']='name ~ '.json_encode($search,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
                $response=Development::request($connection,'refs/branches?'.http_build_query($query,'','&',PHP_QUERY_RFC3986),min(10,(int)ceil($remaining)));
                $rows=$response['values']??null;$next=$response['next']??null;$more=is_string($next) && $next!=='';
                if($next!==null && !is_string($next)) throw new \InvalidArgumentException('Pagination Bitbucket invalide.');
            }
            if(!is_array($rows) || !array_is_list($rows) || count($rows)>100) throw new \InvalidArgumentException('Catalogue de branches invalide.');
            foreach($rows as $row) {
                if(!is_array($row)) throw new \InvalidArgumentException('Branche invalide dans le catalogue.');
                $name=DevelopmentPayload::branchName($row['name']??null);
                $items[$name]=['name'=>$name,'last_commit_at'=>self::date($github?($row['target']['committedDate']??null):($row['target']['date']??null))];
                if(count($items)>self::MAX_BRANCHES) throw new \InvalidArgumentException('Plus de '.self::MAX_BRANCHES.' branches correspondent. Précisez la recherche pour obtenir une liste chronologique complète, ou saisissez le nom exact.');
            }
            if(!$more) return self::sorted(array_values($items));
            // A missing or repeated cursor would loop forever or silently skip branches.
            // For Bitbucket, "next" is only a loop guard here: the next request is rebuilt from $page.
            if(!is_string($next) || $next==='' || strlen($next)>4096 || isset($seen[$next])) throw new \InvalidArgumentException('Le fournisseur répète une page de branches ou renvoie un curseur invalide.');
            $seen[$next]=true;$cursor=$next;
        }
        throw new \InvalidArgumentException('Le catalogue ne peut pas être chargé en une fois. Précisez la recherche.');
    }
}
