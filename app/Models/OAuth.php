<?php
declare(strict_types=1);
namespace App\Models;
use App\Core\DB;
use App\Mcp\OAuthError;

/** Pre-registered OAuth clients only: no arbitrary remote metadata fetches or open registration. */
final class OAuth
{
    public const METHODS=['none','client_secret_post','client_secret_basic'];
    public static function issuer(): string { return rtrim(url(),'/'); }
    public static function text(array $data,string $key,string $default=''): string
    {
        $value=$data[$key]??$default;
        if (!is_string($value) || strlen($value)>4096 || preg_match('/[\x00-\x1f\x7f]/',$value)) throw new OAuthError('invalid_request');
        return $value;
    }
    public static function scopes(string $value): array
    {
        $scopes=array_values(array_unique(preg_split('/ +/',trim($value),-1,PREG_SPLIT_NO_EMPTY)));
        if (!$scopes || array_diff($scopes,[...array_keys(McpToken::SCOPES),'offline_access']) || !array_intersect($scopes,array_keys(McpToken::SCOPES))) throw new OAuthError('invalid_scope');
        return $scopes;
    }
    public static function resource(string $value): string
    {
        $parts=parse_url($value);$expected=parse_url(url('/mcp'));
        if (!$parts || isset($parts['query']) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass']) || strtolower($parts['scheme']??'')!==strtolower($expected['scheme']) || strtolower($parts['host']??'')!==strtolower($expected['host']) || ($parts['port']??(($parts['scheme']??'')==='https'?443:80))!==($expected['port']??($expected['scheme']==='https'?443:80)) || ($parts['path']??'')!==($expected['path']??'')) throw new OAuthError('invalid_target');
        return url('/mcp');
    }
    public static function createClient(string $name,string $redirect,string $method): array
    {
        $name=trim($name);$parts=parse_url($redirect);
        $local=env('APP_ENV')==='local' && ($parts['scheme']??'')==='http' && in_array($parts['host']??'',['127.0.0.1','localhost','[::1]'],true);
        if ($name==='' || mb_strlen($name)>120 || preg_match('/[\x00-\x1f\x7f]/',$name) || !in_array($method,self::METHODS,true) || !$parts || strlen($redirect)>1000 || !filter_var($redirect,FILTER_VALIDATE_URL) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass']) || (!$local && ($parts['scheme']??'')!=='https')) throw new \InvalidArgumentException('Nom, méthode ou URL de rappel invalide. Copiez exactement l’URL HTTPS affichée par ChatGPT.');
        $id='slg_client_'.bin2hex(random_bytes(16));$secret=$method==='none'?null:bin2hex(random_bytes(32));
        DB::run('INSERT INTO oauth_clients(id,name,redirect_uri,auth_method,secret_hash) VALUES(?,?,?,?,?)',[$id,$name,$redirect,$method,$secret===null?null:hash('sha256',$secret)]);
        return ['id'=>$id,'secret'=>$secret];
    }
    public static function client(string $id): array
    {
        return DB::one('SELECT * FROM oauth_clients WHERE id=?',[$id])??throw new OAuthError('invalid_client',401);
    }
    public static function authorization(array $input): array
    {
        $client=self::client(self::text($input,'client_id'));
        $redirect=self::text($input,'redirect_uri');
        if (!hash_equals($client['redirect_uri'],$redirect)) throw new OAuthError('invalid_request');
        if (self::text($input,'response_type')!=='code') throw new OAuthError('unsupported_response_type');
        $challenge=self::text($input,'code_challenge');
        if (self::text($input,'code_challenge_method')!=='S256' || !preg_match('/^[a-zA-Z0-9_-]{43}$/D',$challenge)) throw new OAuthError('invalid_request');
        $state=self::text($input,'state');
        if (strlen($state)>2048) throw new OAuthError('invalid_request');
        return ['client_id'=>$client['id'],'client_name'=>$client['name'],'redirect_uri'=>$redirect,'challenge'=>$challenge,'state'=>$state,'resource'=>self::resource(self::text($input,'resource')),'scopes'=>self::scopes(self::text($input,'scope','read'))];
    }
    public static function code(array $request,array $user,array $scopes): string
    {
        return DB::transaction(function() use($request,$user,$scopes) {
            $current=DB::one('SELECT * FROM users WHERE id=? FOR UPDATE',[$user['id']]);
            if (!$current || !$current['active'] || !$current['verified_at'] || (int)$current['auth_version']!==(int)$user['auth_version']) throw new OAuthError('access_denied');
            $client=DB::one('SELECT * FROM oauth_clients WHERE id=? FOR UPDATE',[$request['client_id']]);
            if (!$client || $client['redirect_uri']!==$request['redirect_uri']) throw new OAuthError('invalid_client');
            if (!$scopes || array_filter($scopes,fn($s)=>!is_string($s)) || array_diff($scopes,$request['scopes']) || array_diff($scopes,[...McpToken::availableScopes($current['role']),'offline_access']) || !array_intersect($scopes,array_keys(McpToken::SCOPES))) throw new OAuthError('invalid_scope');
            $raw=bin2hex(random_bytes(32));
            DB::run('INSERT INTO oauth_codes(code_hash,client_id,user_id,auth_version,redirect_uri,resource,scopes,challenge,expires_at) VALUES(?,?,?,?,?,?,?,?,?)',[hash('sha256',$raw),$client['id'],$user['id'],$user['auth_version'],$request['redirect_uri'],$request['resource'],json_encode(array_values(array_unique($scopes)),JSON_THROW_ON_ERROR),$request['challenge'],gmdate('Y-m-d H:i:s',time()+300)]);
            return $raw;
        });
    }
    public static function authenticateClient(array $input,string $authorization): array
    {
        $id=self::text($input,'client_id');$secret=self::text($input,'client_secret');$method=$secret!==''?'client_secret_post':'none';
        if ($authorization!=='') {
            if ($secret!=='' || !preg_match('/^(?i:Basic) ([A-Za-z0-9+\/=]+)$/D',$authorization,$match)) throw new OAuthError('invalid_client',401);
            $decoded=base64_decode($match[1],true);
            if (!is_string($decoded) || !str_contains($decoded,':')) throw new OAuthError('invalid_client',401);
            [$basicId,$secret]=array_map('urldecode',explode(':',$decoded,2));
            if ($id!=='' && $id!==$basicId) throw new OAuthError('invalid_client',401);
            $id=$basicId;$method='client_secret_basic';
        }
        $client=self::client($id);
        if ($client['auth_method']!==$method || ($method!=='none' && !hash_equals($client['secret_hash'],hash('sha256',$secret)))) throw new OAuthError('invalid_client',401);
        return $client;
    }
    private static function access(array $grant,array $user,string $clientName): array
    {
        $seconds=min(3600,strtotime($grant['expires_at'].' UTC')-time());
        if ($seconds<60) throw new OAuthError('invalid_grant');
        DB::run('DELETE FROM mcp_tokens WHERE oauth_grant_id=? AND expires_at<=UTC_TIMESTAMP()',[$grant['id']]);
        $scopes=json_decode($grant['scopes'],true,32,JSON_THROW_ON_ERROR);
        $token=McpToken::issue($user,mb_substr('OAuth · '.$clientName,0,120),array_values(array_intersect($scopes,array_keys(McpToken::SCOPES))),$seconds);
        DB::run('UPDATE mcp_tokens SET oauth_grant_id=? WHERE id=?',[$grant['id'],$token['id']]);
        return ['access_token'=>$token['key'],'token_type'=>'Bearer','expires_in'=>$seconds,'scope'=>implode(' ',$scopes)];
    }
    public static function exchange(array $client,array $input): array
    {
        $resource=self::resource(self::text($input,'resource'));$raw=self::text($input,'code');$redirect=self::text($input,'redirect_uri');$verifier=self::text($input,'code_verifier');
        if (!preg_match('/^[a-f0-9]{64}$/D',$raw) || !preg_match('/^[A-Za-z0-9._~-]{43,128}$/D',$verifier)) throw new OAuthError('invalid_grant');
        return DB::transaction(function() use($client,$raw,$redirect,$verifier,$resource,$input) {
            $hash=hash('sha256',$raw);$identity=DB::one('SELECT user_id FROM oauth_codes WHERE code_hash=?',[$hash]);
            if (!$identity) throw new OAuthError('invalid_grant');
            $user=DB::one('SELECT * FROM users WHERE id=? FOR UPDATE',[$identity['user_id']]);
            $liveClient=DB::one('SELECT name FROM oauth_clients WHERE id=? FOR UPDATE',[$client['id']]);
            $code=DB::one('SELECT * FROM oauth_codes WHERE code_hash=? FOR UPDATE',[$hash]);
            $challenge=rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'=');
            if (!$code || !$liveClient || $code['client_id']!==$client['id'] || $code['redirect_uri']!==$redirect || $code['resource']!==$resource || strtotime($code['expires_at'].' UTC')<=time() || !hash_equals($code['challenge'],$challenge) || !$user || !$user['active'] || !$user['verified_at'] || (int)$code['auth_version']!==(int)$user['auth_version']) throw new OAuthError('invalid_grant');
            if (isset($input['scope']) && self::text($input,'scope')!==implode(' ',json_decode($code['scopes'],true))) throw new OAuthError('invalid_scope');
            DB::run('DELETE FROM oauth_codes WHERE code_hash=?',[$hash]);
            $id=bin2hex(random_bytes(16));$scopes=json_decode($code['scopes'],true);$refresh=in_array('offline_access',$scopes,true)?'slg_refresh_'.$id.'_'.bin2hex(random_bytes(32)):null;
            $expires=gmdate('Y-m-d H:i:s',time()+30*86400);
            DB::run('INSERT INTO oauth_grants(id,client_id,user_id,auth_version,resource,scopes,refresh_hash,expires_at) VALUES(?,?,?,?,?,?,?,?)',[$id,$client['id'],$user['id'],$user['auth_version'],$resource,$code['scopes'],$refresh===null?null:hash('sha256',$refresh),$expires]);
            $grant=DB::one('SELECT * FROM oauth_grants WHERE id=?',[$id]);$result=self::access($grant,$user,$liveClient['name']);
            if ($refresh!==null) $result['refresh_token']=$refresh;
            return $result;
        });
    }
    public static function refresh(array $client,array $input): array
    {
        $resource=self::resource(self::text($input,'resource'));$raw=self::text($input,'refresh_token');
        if (!preg_match('/^slg_refresh_([a-f0-9]{32})_[a-f0-9]{64}$/D',$raw,$match)) throw new OAuthError('invalid_grant');
        $result=DB::transaction(function() use($client,$input,$resource,$raw,$match) {
            $identity=DB::one('SELECT user_id FROM oauth_grants WHERE id=?',[$match[1]]);
            if (!$identity) throw new OAuthError('invalid_grant');
            $user=DB::one('SELECT * FROM users WHERE id=? FOR UPDATE',[$identity['user_id']]);
            $liveClient=DB::one('SELECT name FROM oauth_clients WHERE id=? FOR UPDATE',[$client['id']]);
            $grant=DB::one('SELECT * FROM oauth_grants WHERE id=? FOR UPDATE',[$match[1]]);$hash=hash('sha256',$raw);
            if (!$grant || !$liveClient || $grant['client_id']!==$client['id'] || $grant['resource']!==$resource) throw new OAuthError('invalid_grant');
            if ($grant['refresh_hash']===null || !hash_equals($grant['refresh_hash'],$hash)) {
                if (DB::one('SELECT token_hash FROM oauth_refresh_history WHERE token_hash=? AND grant_id=?',[$hash,$grant['id']])) { DB::run('DELETE FROM oauth_grants WHERE id=?',[$grant['id']]);return ['reused'=>true]; }
                throw new OAuthError('invalid_grant');
            }
            if (!$user || !$user['active'] || !$user['verified_at'] || (int)$user['auth_version']!==(int)$grant['auth_version'] || strtotime($grant['expires_at'].' UTC')<time()+60) throw new OAuthError('invalid_grant');
            $scopes=json_decode($grant['scopes'],true);
            if (isset($input['scope'])) {
                $requested=self::scopes(self::text($input,'scope'));
                if (array_diff($requested,$scopes)) throw new OAuthError('invalid_scope');
                $grant['scopes']=json_encode($requested,JSON_THROW_ON_ERROR);
            }
            $next=in_array('offline_access',json_decode($grant['scopes'],true),true)?'slg_refresh_'.$grant['id'].'_'.bin2hex(random_bytes(32)):null;
            DB::run('INSERT INTO oauth_refresh_history(token_hash,grant_id) VALUES(?,?)',[$hash,$grant['id']]);
            DB::run('UPDATE oauth_grants SET refresh_hash=?,scopes=? WHERE id=?',[$next===null?null:hash('sha256',$next),$grant['scopes'],$grant['id']]);
            $result=self::access($grant,$user,$liveClient['name']);
            if ($next!==null) $result['refresh_token']=$next;
            return $result;
        });
        if (isset($result['reused'])) throw new OAuthError('invalid_grant');
        return $result;
    }
    public static function revoke(array $client,string $raw): void
    {
        $hash=hash('sha256',$raw);
        DB::transaction(function() use($client,$hash) {
            $row=DB::one('SELECT g.id FROM oauth_grants g LEFT JOIN mcp_tokens k ON k.oauth_grant_id=g.id LEFT JOIN oauth_refresh_history h ON h.grant_id=g.id WHERE g.client_id=? AND (g.refresh_hash=? OR k.token_hash=? OR h.token_hash=?) LIMIT 1',[$client['id'],$hash,$hash,$hash]);
            if ($row) DB::run('DELETE FROM oauth_grants WHERE id=?',[$row['id']]);
        });
    }
}
