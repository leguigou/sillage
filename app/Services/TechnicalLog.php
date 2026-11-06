<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\DB;

/**
 * Metadata only: no bodies, query strings, headers, exceptions or credentials enter this boundary.
 * Technical call log (technical_logs table), viewable by admins. Records incoming API/MCP/OAuth/webhook
 * requests (beginIncoming() in public/index.php, written at shutdown) and outgoing integration calls
 * (outgoing(), used by Development and LlmClient). All entries of one request or background job share a
 * correlation id (requestId(), also sent as the X-Request-ID response header).
 * Writes use a separate autocommit connection (DB::independent()) so entries survive a business
 * rollback, and a logging failure never changes the business result or error (see AGENTS.md and
 * docs/journaux-techniques.md). append() re-validates every field against whitelists before insert.
 */
final class TechnicalLog
{
    public const CHANNELS=['api'=>'API / AJAX','mcp'=>'MCP','oauth'=>'OAuth MCP','development'=>'Développement Git','llm'=>'Intelligence artificielle'];
    public const PROVIDERS=['github'=>'GitHub','bitbucket'=>'Bitbucket','openai'=>'OpenAI','lmstudio'=>'LM Studio','ollama'=>'Ollama','deepseek'=>'DeepSeek','anthropic'=>'Anthropic','grok'=>'Grok','gemini'=>'Gemini'];
    private static ?array $incoming=null;
    private static ?string $requestId=null;
    private static ?\PDO $writer=null;
    private static bool $pruned=false;
    private static ?int $actor=null;
    public static function requestId(): string { return self::$requestId??=bin2hex(random_bytes(16)); }
    /** CLI only: adopt a background job's public correlation id for the following log entries. */
    public static function background(string $id): void
    {
        if(PHP_SAPI!=='cli' || !preg_match('/^[a-f0-9]{32}$/D',$id)) throw new \LogicException('Contexte de journal de fond invalide.');
        self::$requestId=$id;self::$actor=null;
    }
    public static function actor(int $id): void { self::$actor=$id>0?$id:null; }
    /** Retention in days from TECHNICAL_LOG_RETENTION_DAYS (1 to 365, default 30). */
    public static function retention(): int
    {
        $value=env('TECHNICAL_LOG_RETENTION_DAYS','30');
        return ctype_digit($value) && (int)$value>=1 && (int)$value<=365?(int)$value:30;
    }
    /**
     * Starts tracking the current HTTP request. The path is reduced to a fixed endpoint pattern
     * (e.g. /webhooks/{id}, /api/*) so ids and parameters are never stored. Plain page loads are only
     * kept if they turn out to be JSON responses (decided in endIncoming()).
     */
    public static function beginIncoming(): void
    {
        if (self::$incoming!==null) return;
        $path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/';
        $channel=match(true) {$path==='/mcp'=>'mcp',str_starts_with($path,'/webhooks/')=>'development',str_starts_with($path,'/oauth/') || str_starts_with($path,'/.well-known/oauth-')=>'oauth',default=>'api'};
        $endpoint=match($channel) {'mcp'=>'/mcp','development'=>'/webhooks/{id}','oauth'=>in_array($path,['/oauth/token','/oauth/revoke','/oauth/authorize','/oauth/consent','/.well-known/oauth-authorization-server','/.well-known/oauth-protected-resource','/.well-known/oauth-protected-resource/mcp'],true)?$path:'/oauth/*',default=>'/api/*'};
        self::$incoming=['direction'=>'incoming','channel'=>$channel,'method'=>in_array($_SERVER['REQUEST_METHOD']??'',['GET','POST','PUT','PATCH','DELETE','OPTIONS','HEAD'],true)?$_SERVER['REQUEST_METHOD']:'OTHER','endpoint'=>$endpoint,'_start'=>hrtime(true),'request_bytes'=>isset($_SERVER['CONTENT_LENGTH'])?max(0,(int)$_SERVER['CONTENT_LENGTH']):null,'_explicit'=>$channel!=='api' || str_starts_with($path,'/api/') || str_contains(strtolower($_SERVER['HTTP_ACCEPT']??''),'application/json') || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']??'')==='xmlhttprequest'];
        header('X-Request-ID: '.self::requestId());
        register_shutdown_function([self::class,'endIncoming']);
    }
    /** Lets the router, MCP server or webhook handler add controlled facts (whitelisted keys only) to the incoming entry. */
    public static function incoming(array $facts): void
    {
        if (self::$incoming===null) return;
        self::$incoming=array_replace(self::$incoming,array_intersect_key($facts,array_flip(['endpoint','channel','provider','operation','token_id','connection_id','error_code','failed'])));
    }
    /** Shutdown handler: completes the incoming entry with the final HTTP status (500 after a fatal error) and writes it. */
    public static function endIncoming(): void
    {
        if (self::$incoming===null) return;
        $entry=self::$incoming;self::$incoming=null;
        $json=false;foreach(headers_list() as $header) if (stripos($header,'Content-Type: application/json')===0) $json=true;
        if (!$entry['_explicit'] && !$json) return;
        $entry['http_status']=http_response_code()?:200;
        $fatal=error_get_last();$fatal=$fatal && in_array($fatal['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR],true);
        if ($fatal) {$entry['http_status']=500;$entry['error_code']='internal_error';}
        $entry['outcome']=!empty($entry['failed']) || $entry['http_status']>=400?'error':'success';
        if ($entry['outcome']==='error' && empty($entry['error_code'])) $entry['error_code']='http:'.$entry['http_status'];
        self::finish($entry);
    }
    /** The callback supplies transport facts; failures are rethrown unchanged after independent recording. */
    public static function outgoing(string $channel,string $provider,string $method,string $url,callable $transfer,array $context=[]): array
    {
        $entry=array_replace(array_intersect_key($context,['connection_id'=>true]),['direction'=>'outgoing','channel'=>$channel,'provider'=>$provider,'method'=>$method,'endpoint'=>self::endpoint($url),'_start'=>hrtime(true),'outcome'=>'success']);
        try { return $transfer($entry); }
        catch(\Throwable $error) {
            $entry['outcome']='error';
            if (empty($entry['error_code'])) $entry['error_code']=!empty($entry['http_status']) && $entry['http_status']>=400?'http:'.$entry['http_status']:($error instanceof \InvalidArgumentException?'invalid_response_or_configuration':'internal_error');
            throw $error;
        } finally { self::finish($entry); }
    }
    /** Reduces a URL to scheme://host[:port]/path (no credentials, query or fragment), control characters removed. */
    public static function endpoint(string $url): string
    {
        $parts=parse_url($url);if ($parts===false) return '[adresse invalide]';
        $origin=isset($parts['host'])?strtolower($parts['scheme']??'https').'://'.$parts['host'].(isset($parts['port'])?':'.$parts['port']:''):'';
        // Gemini embeds the model name in its path; query parameters (including API keys) are discarded.
        return mb_substr(preg_replace('/[\x00-\x1f\x7f]/u','',$origin.($parts['path']??'/'))??'[adresse invalide]',0,600);
    }
    private static function finish(array $entry): void
    {
        $entry['duration_ms']=min(4294967295,max(0,(int)round((hrtime(true)-$entry['_start'])/1000000)));
        $entry['user_id']=self::$actor;$entry['request_id']=self::requestId();
        try { self::append($entry); }
        catch(\Throwable) { error_log('Sillage : journal technique indisponible ; vérifier MySQL et les migrations.'); }
    }
    /**
     * Inserts one entry after normalising it: unknown channel/direction/outcome is rejected; unknown provider,
     * method, operation or error code is blanked; numbers are clamped. Prunes expired rows once per process.
     */
    public static function append(array $entry): int
    {
        $directions=['incoming','outgoing'];
        if (!in_array($entry['direction']??null,$directions,true) || !isset(self::CHANNELS[$entry['channel']??'']) || !in_array($entry['outcome']??null,['success','error'],true) || !preg_match('/^[a-f0-9]{32}$/D',$entry['request_id']??'')) throw new \InvalidArgumentException('Métadonnées techniques invalides.');
        $row=[];
        foreach(['request_id'=>32,'direction'=>8,'channel'=>16,'provider'=>24,'method'=>12,'endpoint'=>600,'operation'=>100,'outcome'=>8,'error_code'=>64] as $key=>$length) $row[$key]=mb_substr((string)($entry[$key]??''),0,$length);
        if ($row['provider']!=='' && !isset(self::PROVIDERS[$row['provider']])) $row['provider']='';
        if (!in_array($row['method'],['GET','POST','PUT','PATCH','DELETE','OPTIONS','HEAD','OTHER'],true)) $row['method']='OTHER';
        $row['endpoint']=self::endpoint($row['endpoint']);
        $row['operation']=preg_match('/^[a-zA-Z0-9_\/.:-]{0,100}$/D',$row['operation'])?$row['operation']:'';
        $row['error_code']=preg_match('/^(?:http:[1-5][0-9]{2}|transport:[0-9]{1,3}|rpc:-[0-9]{1,6}|tool_error|internal_error|invalid_response_or_configuration)$/D',$row['error_code'])?$row['error_code']:'';
        foreach(['http_status','duration_ms','request_bytes','response_bytes','user_id','token_id','connection_id'] as $key) $row[$key]=isset($entry[$key])?max(0,(int)$entry[$key]):null;
        $row['duration_ms']=min(4294967295,$row['duration_ms']??0);
        if ($row['http_status']!==null && ($row['http_status']<100 || $row['http_status']>599)) $row['http_status']=null;
        $db=self::$writer??=DB::independent();
        $sql='INSERT INTO technical_logs('.implode(',',array_keys($row)).') VALUES('.implode(',',array_fill(0,count($row),'?')).')';
        $db->prepare($sql)->execute(array_values($row));$id=(int)$db->lastInsertId();
        if (!self::$pruned) {self::$pruned=true;self::prune($db);}
        return $id;
    }
    /** Deletes at most $limit entries older than the retention period; returns the number deleted. */
    public static function prune(?\PDO $db=null,int $limit=1000): int
    {
        $db??=DB::independent();$limit=max(1,min(10000,$limit));
        $statement=$db->prepare('DELETE FROM technical_logs
            WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL '.self::retention().' DAY) ORDER BY created_at,id LIMIT '.$limit);$statement->execute();return $statement->rowCount();
    }
    /**
     * One page (50 rows, newest first) for the admin log screen. Filters are checked against whitelists or
     * strict formats and bound as parameters; paging is by keyset ("before" = last id seen), not OFFSET.
     */
    public static function page(array $filters): array
    {
        $where=['l.created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL '.self::retention().' DAY)'];$args=[];$clean=[];
        foreach(['direction'=>['incoming','outgoing'],'channel'=>array_keys(self::CHANNELS),'provider'=>array_keys(self::PROVIDERS),'outcome'=>['success','error']] as $key=>$allowed) {
            $value=$filters[$key]??'';if(!is_string($value) || ($value!=='' && !in_array($value,$allowed,true))) throw new \InvalidArgumentException('Filtre du journal invalide.');
            $clean[$key]=$value;if($value!=='') {$where[]='l.'.$key.'=?';$args[]=$value;}
        }
        foreach(['from','to'] as $key) {
            $value=$filters[$key]??'';
            if (!is_string($value) || ($value!=='' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$value) || !checkdate((int)substr($value,5,2),(int)substr($value,8,2),(int)substr($value,0,4))))) throw new \InvalidArgumentException('Date du journal invalide.');
            $clean[$key]=$value;if($value!=='') {$where[]='l.created_at '.($key==='from'?'>= ?':'< DATE_ADD(?, INTERVAL 1 DAY)');$args[]=$value;}
        }
        if ($clean['from']!=='' && $clean['to']!=='' && $clean['from']>$clean['to']) throw new \InvalidArgumentException('La date de fin doit suivre la date de début.');
        $correlation=$filters['request_id']??'';
        if (!is_string($correlation) || ($correlation!=='' && !preg_match('/^[a-f0-9]{32}$/D',$correlation))) throw new \InvalidArgumentException('Identifiant de requête invalide.');
        $clean['request_id']=$correlation;if($correlation!=='') {$where[]='l.request_id=?';$args[]=$correlation;}
        $before=filter_var($filters['before']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
        if ($before===false) throw new \InvalidArgumentException('Page du journal invalide.');
        if ($before>0) {$where[]='l.id<?';$args[]=$before;}
        $statement=(self::$writer??=DB::independent())->prepare('SELECT l.*,u.name AS user_name
            FROM technical_logs l
            LEFT JOIN users u ON u.id=l.user_id
            WHERE '.implode(' AND ',$where).' ORDER BY l.id DESC LIMIT 51');
        $statement->execute($args);$rows=$statement->fetchAll();
        $more=count($rows)>50;$rows=array_slice($rows,0,50);
        return ['rows'=>$rows,'filters'=>$clean,'next'=>$more?(int)$rows[array_key_last($rows)]['id']:null];
    }
}
