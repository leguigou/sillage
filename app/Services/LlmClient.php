<?php
declare(strict_types=1);
namespace App\Services;

final class LlmClient
{
    /** DNS is checked on every call, then pinned for the transfer (no proxy/redirect). */
    public static function addresses(string $host,bool $local): array
    {
        $host=trim($host,'[]');
        if (filter_var($host,FILTER_VALIDATE_IP)) $ips=[$host];
        elseif (strtolower($host)==='localhost') $ips=['127.0.0.1'];
        else {
            $records=@dns_get_record($host,DNS_A|DNS_AAAA);
            $ips=[];
            foreach($records?:[] as $record) if (isset($record['ip']) || isset($record['ipv6'])) $ips[]=$record['ip']??$record['ipv6'];
        }
        if (!$ips) throw new \InvalidArgumentException('Le serveur PHP ne parvient pas à résoudre l’hôte du fournisseur.');
        foreach($ips as $ip) {
            $public=filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)!==false;
            // Only explicitly selected local providers can reach loopback/private LANs.
            $lan=$local && (preg_match('/^(127\.|10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[01])\.)/',$ip) || $ip==='::1' || preg_match('/^f[cd][0-9a-f]{2}:/i',$ip));
            if (!$public && !$lan) throw new \InvalidArgumentException('Adresse réseau non autorisée pour ce fournisseur. Utilisez LM Studio ou Ollama pour un serveur local.');
            if (str_contains($ip,':') && str_starts_with(strtolower($ip),'::ffff:')) throw new \InvalidArgumentException('Adresse IPv6 de transition non autorisée.');
        }
        return array_values(array_unique($ips));
    }
    public static function request(array $config,string $path,?array $body=null): array
    {
        // Windows counts blocking network time against PHP's execution deadline.
        if (function_exists('set_time_limit')) @set_time_limit(65);
        $parts=parse_url($config['base_url']);
        $host=$parts['host'];
        $ips=self::addresses($host,LlmProtocol::PROVIDERS[$config['provider']]['local']);
        if ($parts['scheme']==='http') foreach($ips as $ip) {
            if (filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)!==false) throw new \InvalidArgumentException('Une URL HTTP est réservée à un serveur local ou réseau privé. Utilisez HTTPS pour une adresse publique.');
        }
        $port=$parts['port']??($parts['scheme']==='https'?443:80);
        $ch=curl_init($config['base_url'].$path);
        $response='';$tooLarge=false;
        $options=[CURLOPT_RETURNTRANSFER=>false,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>$body===null?10:55,CURLOPT_PROTOCOLS_STR=>'http,https',CURLOPT_PROXY=>'',CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_HTTPHEADER=>LlmProtocol::headers($config),CURLOPT_USERAGENT=>'Sillage/1.0',CURLOPT_WRITEFUNCTION=>function($handle,string $chunk) use(&$response,&$tooLarge): int {
            if (strlen($response)+strlen($chunk)>2*1024*1024) { $tooLarge=true;return 0; }
            $response.=$chunk;return strlen($chunk);
        }];
        if (!filter_var(trim($host,'[]'),FILTER_VALIDATE_IP)) $options[CURLOPT_RESOLVE]=[$host.':'.$port.':'.(str_contains($ips[0],':')?'['.$ips[0].']':$ips[0])];
        if ($body!==null) { $options[CURLOPT_POST]=true;$options[CURLOPT_POSTFIELDS]=json_encode($body,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE); }
        if (PHP_OS_FAMILY==='Windows' && defined('CURLSSLOPT_NATIVE_CA')) $options[CURLOPT_SSL_OPTIONS]=CURLSSLOPT_NATIVE_CA;
        curl_setopt_array($ch,$options);
        $ok=curl_exec($ch);$status=curl_getinfo($ch,CURLINFO_HTTP_CODE);$errno=curl_errno($ch);unset($ch);
        if ($tooLarge) throw new \InvalidArgumentException('Réponse LLM trop volumineuse (2 Mo maximum).');
        if ($ok===false) throw new \InvalidArgumentException(match($errno) { CURLE_PEER_FAILED_VERIFICATION=>'Certificat HTTPS non reconnu par PHP.',CURLE_OPERATION_TIMEDOUT=>'Le fournisseur LLM n’a pas répondu dans le délai prévu.',default=>'Impossible de joindre le fournisseur LLM (erreur réseau '.$errno.'). Vérifiez son URL et son démarrage.' });
        if ($status<200 || $status>=300) throw new \InvalidArgumentException(match($status) { 401=>'Clé API refusée (HTTP 401).',403=>'Accès au fournisseur ou au modèle refusé (HTTP 403).',404=>'Adresse API ou modèle introuvable (HTTP 404).',429=>'Quota ou limite d’appels atteint (HTTP 429).',default=>'Le fournisseur a refusé la requête (HTTP '.$status.'). Vérifiez le modèle et sa compatibilité.' });
        try { $data=json_decode($response,true,64,JSON_THROW_ON_ERROR); } catch (\JsonException) { throw new \InvalidArgumentException('Le fournisseur n’a pas renvoyé un JSON valide.'); }
        if (!is_array($data)) throw new \InvalidArgumentException('Réponse LLM invalide.');
        return $data;
    }
    public static function discover(array $config): array
    {
        $models=[];$cursor='';$seen=[];$started=microtime(true);
        for($page=0;$page<10;$page++) {
            if (microtime(true)-$started>20) throw new \InvalidArgumentException('Le catalogue prend trop de temps à charger. Réessayez ou réduisez le catalogue de la passerelle.');
            $result=LlmProtocol::models($config['provider'],self::request($config,LlmProtocol::modelsPath($config['provider'],$cursor)));
            foreach($result['models'] as $model) $models[$model['id']]=$model;
            $cursor=$result['cursor'];
            if ($cursor==='') { ksort($models);return array_values($models); }
            if (isset($seen[$cursor])) throw new \InvalidArgumentException('Le fournisseur répète une page de modèles.');
            $seen[$cursor]=true;
        }
        throw new \InvalidArgumentException('Catalogue de modèles trop grand (10 pages maximum). Utilisez un point d’accès au catalogue réduit.');
    }
    public static function generate(array $config,string $model,string $system,string $input): string
    {
        [$path,$body]=LlmProtocol::generation($config['provider'],$model,$system,$input);
        return LlmProtocol::text($config['provider'],self::request($config,$path,$body));
    }
}
