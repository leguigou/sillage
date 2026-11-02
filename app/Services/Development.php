<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\{DB, Security};
final class Development
{
    public static function repository(string $provider, string $value): string
    {
        $host = match ($provider) {
            'github' => 'github.com',
            'bitbucket' => 'bitbucket.org',
            default => throw new \InvalidArgumentException('Choisissez GitHub ou Bitbucket Cloud.'),
        };
        $value = trim($value);
        $hostPattern = preg_quote($host, '#');
        if (preg_match('~^https://' . $hostPattern . '/([^?#]+)/?$~iD', $value, $match)) {
            $value = rtrim($match[1], '/');
        } elseif (preg_match('~^git@' . $hostPattern . ':([^?#]+)$~iD', $value, $match)) {
            $value = $match[1];
        }
        $value = preg_replace('/\.git$/iD', '', $value);
        if (!preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#D', $value) || in_array(explode('/', $value)[0], ['.','..'], true) || in_array(explode('/', $value)[1], ['.','..'], true)) {
            throw new \InvalidArgumentException('Indiquez un dépôt sous la forme organisation/projet ou son URL ' . $host . '. Le champ Dépôt ne doit pas contenir une adresse e-mail.');
        }
        return $value;
    }

    public static function request(array $c, string $resource, int $timeout = 20): array
    {
        $repo = self::repository($c['provider'], $c['repository']);
        $path = ($c['provider'] === 'github' ? '/repos/' : '/2.0/repositories/') . $repo . ($resource === '' ? '' : '/' . $resource);
        return self::http($c['provider'], Security::decrypt($c['secret_cipher']), $path, $timeout,isset($c['id'])?['connection_id'=>(int)$c['id']]:[]);
    }

    public static function accessToken(string $token): string
    {
        $token = trim($token);
        if (strlen($token) < 10 || strlen($token) > 4000 || preg_match('/\s/', $token)) {
            throw new \InvalidArgumentException('Collez le jeton d’accès complet, sans espace ni préfixe « Bearer » (10 à 4 000 caractères).');
        }
        return $token;
    }

    public static function verifyConnection(array $connection): void
    {
        self::request($connection, '', 8);
        self::request($connection, $connection['provider'] === 'github' ? 'pulls?state=all&per_page=1' : 'pullrequests?state=OPEN&pagelen=1', 8);
    }

    public static function discover(string $provider, string $token, int $page, string $workspace = ''): array
    {
        if ($page < 1 || $page > 10000) throw new \InvalidArgumentException('Page de dépôts invalide.');
        $token = self::accessToken($token);
        if ($provider === 'github') {
            $path = '/user/repos?per_page=100&sort=full_name&direction=asc&page=' . $page;
        } elseif ($provider === 'bitbucket') {
            if (!preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $workspace)) throw new \InvalidArgumentException('Indiquez le nom du workspace Bitbucket pour lister ses dépôts.');
            $path = '/2.0/repositories/' . rawurlencode($workspace) . '?pagelen=100&page=' . $page;
        } else {
            throw new \InvalidArgumentException('Choisissez GitHub ou Bitbucket Cloud.');
        }
        return self::repositoryPage($provider, self::http($provider, $token, $path, 8), $page);
    }

    public static function repositoryPage(string $provider, array $data, int $page): array
    {
        if (!in_array($provider,['github','bitbucket'],true)) throw new \InvalidArgumentException('Fournisseur invalide.');
        $items = $provider === 'github' ? $data : ($data['values'] ?? null);
        if (!is_array($items) || !array_is_list($items) || count($items) > 100) throw new \InvalidArgumentException('La liste de dépôts renvoyée par le fournisseur est invalide.');
        $repositories = [];
        foreach ($items as $item) {
            if (!is_array($item) || !is_string($item['full_name'] ?? null)) throw new \InvalidArgumentException('Le fournisseur a renvoyé un dépôt sans identifiant.');
            $private = $item[$provider === 'github' ? 'private' : 'is_private'] ?? null;
            if ($private !== null && !is_bool($private)) throw new \InvalidArgumentException('Le fournisseur a renvoyé une visibilité de dépôt invalide.');
            $repositories[] = [
                'repository'=>self::repository($provider,$item['full_name']),
                'private'=>$private,
            ];
        }
        // Pagination URLs from remote responses are never followed with the credential.
        $more = $provider === 'github' ? count($items) === 100 : is_string($data['next'] ?? null) && $data['next'] !== '';
        return ['repositories'=>$repositories,'page'=>$page,'has_more'=>$more];
    }

    private static function http(string $provider, string $token, string $path, int $timeout,array $context=[],?array $payload=null,bool $diffstatRedirect=false): array
    {
        $base = match ($provider) { 'github'=>'https://api.github.com', 'bitbucket'=>'https://api.bitbucket.org', default=>throw new \InvalidArgumentException('Fournisseur invalide.') };
        $url = $base . $path;
        return TechnicalLog::outgoing('development',$provider,$payload===null?'GET':'POST',$url,function(array &$trace) use($url,$token,$timeout,$payload,$diffstatRedirect): array {
            $ch = curl_init($url);
            $location=null;
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT => 'Sillage/1.0',
                CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: Bearer ' . $token],
                CURLOPT_PROTOCOLS_STR => 'https',
            ]);
            if($diffstatRedirect) curl_setopt($ch,CURLOPT_HEADERFUNCTION,static function($handle,string $line) use(&$location): int {
                if(strncasecmp($line,'Location:',9)===0) $location=strlen($line)<=8192?trim(substr($line,9)):null;
                return strlen($line);
            });
            if($payload!==null) {
                $encoded=json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
                curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$encoded,CURLOPT_HTTPHEADER=>['Accept: application/json','Content-Type: application/json','Authorization: Bearer '.$token]]);
                $trace['request_bytes']=strlen($encoded);
            }
            if (PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA')) {
                curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
            }
            $body = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $errno = curl_errno($ch);
            $trace['http_status']=$status?:null;$trace['response_bytes']=is_string($body)?strlen($body):null;
            if ($errno) $trace['error_code']='transport:'.$errno;
            curl_close($ch);
            if ($body === false) {
                throw new \InvalidArgumentException(match ($errno) {
                    CURLE_PEER_FAILED_VERIFICATION => 'La vérification du certificat HTTPS a échoué. Configurez les certificats de confiance du serveur PHP.',
                    CURLE_OPERATION_TIMEDOUT => 'Le fournisseur ne répond pas dans le délai de ' . $timeout . ' secondes. Réessayez.',
                    CURLE_COULDNT_RESOLVE_HOST => 'Le serveur PHP ne parvient pas à résoudre le nom du fournisseur.',
                    default => 'Le serveur PHP ne parvient pas à joindre le fournisseur (erreur réseau ' . $errno . ').',
                });
            }
            if($diffstatRedirect && $status===302 && is_string($location)) return ['_diffstat_redirect'=>$location];
            if ($status < 200 || $status >= 300) {
                throw new \InvalidArgumentException(match ($status) {
                    401 => 'Jeton refusé par le fournisseur (HTTP 401). Vérifiez qu’il est valide et non expiré.',
                    403 => 'Accès refusé (HTTP 403). Vérifiez les permissions, l’approbation de votre organisation et les limites d’appels du fournisseur.',
                    404 => 'Ressource Git introuvable ou inaccessible avec ce jeton (HTTP 404). Vérifiez le dépôt, le nom exact de la branche et les droits de lecture.',
                    429 => 'Limite d’appels du fournisseur atteinte (HTTP 429). Réessayez plus tard.',
                    default => 'Le fournisseur a refusé la vérification (HTTP ' . $status . '). Réessayez plus tard.',
                });
            }
            if (strlen($body) > 8 * 1024 * 1024) {
                throw new \InvalidArgumentException('Réponse du fournisseur trop volumineuse.');
            }
            try { $data = json_decode($body, true, 128, JSON_THROW_ON_ERROR); }
            catch (\JsonException) { throw new \InvalidArgumentException('Le fournisseur a renvoyé une réponse JSON invalide.'); }
            if (!is_array($data)) {
                throw new \InvalidArgumentException('Réponse du fournisseur invalide.');
            }
            if($payload!==null && !empty($data['errors'])) throw new \InvalidArgumentException('GitHub a refusé le catalogue GraphQL. Vérifiez les droits de lecture du contenu et du dépôt.');
            return $data;
        },$context);
    }

    public static function pullRequestFiles(array $connection,int $number,int $page,int $timeout): array
    {
        if($number<1 || $page<1 || $page>10) throw new \InvalidArgumentException('Page de fichiers Git invalide.');
        if($connection['provider']==='github') return self::request($connection,'pulls/'.$number.'/files?per_page=100&page='.$page,$timeout);
        $repo=self::repository('bitbucket',$connection['repository']);
        $data=self::http('bitbucket',Security::decrypt($connection['secret_cipher']),'/2.0/repositories/'.$repo.'/pullrequests/'.$number.'/diffstat?pagelen=100&page='.$page,$timeout,['connection_id'=>$connection['id']],null,true);
        if(isset($data['_diffstat_redirect'])) return self::request($connection,GitPullRequest::diffstatResource($data['_diffstat_redirect'],$repo,$page),$timeout);
        return $data;
    }

    public static function sync(array $c, string $kind = 'pull_request', int $page = 1): int
    {
        if (!isset(DevelopmentPayload::KINDS[$kind]) || $page<1 || $page>10000) throw new \InvalidArgumentException('Type ou page de synchronisation invalide.');
        $github=$c['provider']==='github';
        $resource=$github ? match($kind) { 'branch'=>'branches?per_page=50', 'commit'=>'commits?per_page=50', default=>'pulls?state=all&per_page=50' }
            : match($kind) { 'branch'=>'refs/branches?pagelen=50', 'commit'=>'commits?pagelen=50', default=>'pullrequests?state=OPEN&state=MERGED&state=DECLINED&pagelen=50' };
        $data=self::request($c,$resource.'&page='.$page,8);
        $items=$github?$data:($data['values']??null);
        if (!is_array($items) || !array_is_list($items) || count($items)>50) throw new \InvalidArgumentException('Réponse de synchronisation invalide.');
        $events=[];
        foreach($items as $item) {
            if (!is_array($item)) throw new \InvalidArgumentException('Élément de synchronisation invalide.');
            $events[]=DevelopmentPayload::object($c['provider'],$kind,$item,$c['repository']);
        }
        DB::transaction(function() use($c,$events) {
            foreach($events as $event) \App\Models\DevelopmentEvent::save($c,$event,false);
            DB::run('UPDATE connections SET last_sync_at=UTC_TIMESTAMP() WHERE id=?',[$c['id']]);
        });
        return count($events);
    }

    public static function githubBranchRefs(array $connection,string $search,?string $cursor,int $timeout): array
    {
        if($connection['provider']!=='github') throw new \InvalidArgumentException('Ce catalogue est réservé à GitHub.');
        [$owner,$repo]=explode('/',self::repository('github',$connection['repository']));
        $query= <<<'GQL'
query SillageBranches($owner:String!,$repository:String!,$after:String,$search:String){repository(owner:$owner,name:$repository){refs(refPrefix:"refs/heads/",first:100,after:$after,query:$search,orderBy:{field:ALPHABETICAL,direction:ASC}){nodes{name target{... on Commit{committedDate}}}pageInfo{hasNextPage endCursor}}}}
GQL;
        return self::http('github',Security::decrypt($connection['secret_cipher']),'/graphql',$timeout,['connection_id'=>$connection['id']],['query'=>$query,'variables'=>['owner'=>$owner,'repository'=>$repo,'after'=>$cursor,'search'=>$search===''?null:$search]]);
    }
    public static function branch(array $connection,string $name): array
    {
        $name=DevelopmentPayload::branchName($name);
        $response=self::request($connection,($connection['provider']==='github'?'branches/':'refs/branches/').rawurlencode($name),10);
        if (($response['name']??null)!==$name) throw new \InvalidArgumentException('Le fournisseur n’a pas confirmé la branche demandée.');
        return DevelopmentPayload::object($connection['provider'],'branch',$response,$connection['repository']);
    }
}
