<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\{Auth,DB,Security,View};
use App\Models\{OAuth,McpToken};
use App\Mcp\OAuthError;

/**
 * OAuth 2 authorization server used to connect external MCP clients (AI tools)
 * to a user's Sillage account (authorization code + PKCE, refresh tokens).
 *
 * - publicEndpoint(): machine-to-machine endpoints called directly from
 *   public/index.php, outside the router and without session/CSRF:
 *   /.well-known/* metadata, POST /oauth/token and POST /oauth/revoke.
 * - GET /oauth/authorize: validates the client request, sends anonymous users
 *   to /login (then back here), and shows the consent screen.
 * - POST /oauth/authorize: the user allows or denies; redirects to the client.
 * - POST /oauth/clients, /oauth/clients/{id}/delete (admin): register/remove clients.
 * - POST /oauth/grants/{id}/revoke (signed-in user, own grants only).
 * Protocol logic lives in App\Models\OAuth; scopes come from App\Models\McpToken.
 */
final class OAuthController
{
    /**
     * Serves the OAuth discovery documents and the token/revoke endpoints as JSON.
     * POST bodies must be form-encoded and at most 16 KB; rate-limited per IP.
     */
    public static function publicEndpoint(string $path): never
    {
        header('Cache-Control: no-store');header('Pragma: no-cache');header('X-Content-Type-Options: nosniff');
        if ($error=\App\Mcp\Endpoint::error()) json_response(['error'=>'invalid_request','error_description'=>$error['message']],$error['status']);
        $method=$_SERVER['REQUEST_METHOD']??'';
        if (str_starts_with($path,'/.well-known/')) {
            if ($method!=='GET') { header('Allow: GET');json_response(['error'=>'invalid_request'],405); }
            $scopes=[...array_keys(McpToken::SCOPES),'offline_access'];
            if ($path==='/.well-known/oauth-authorization-server') json_response(['issuer'=>OAuth::issuer(),'authorization_endpoint'=>url('/oauth/authorize'),'token_endpoint'=>url('/oauth/token'),'revocation_endpoint'=>url('/oauth/revoke'),'response_types_supported'=>['code'],'grant_types_supported'=>['authorization_code','refresh_token'],'code_challenge_methods_supported'=>['S256'],'token_endpoint_auth_methods_supported'=>OAuth::METHODS,'revocation_endpoint_auth_methods_supported'=>OAuth::METHODS,'scopes_supported'=>$scopes,'authorization_response_iss_parameter_supported'=>true,'client_id_metadata_document_supported'=>false]);
            json_response(['resource'=>url('/mcp'),'authorization_servers'=>[OAuth::issuer()],'scopes_supported'=>array_keys(McpToken::SCOPES),'bearer_methods_supported'=>['header'],'resource_name'=>'Sillage MCP']);
        }
        if ($method!=='POST') { header('Allow: POST');json_response(['error'=>'invalid_request'],405); }
        if ((int)($_SERVER['CONTENT_LENGTH']??0)>16384 || strlen(file_get_contents('php://input',false,null,0,16385))>16384) json_response(['error'=>'invalid_request'],413);
        if (strtolower(trim(explode(';',$_SERVER['CONTENT_TYPE']??'')[0]))!=='application/x-www-form-urlencoded') json_response(['error'=>'invalid_request'],415);
        Security::limit('oauth-token',120,60);
        try {
            $client=OAuth::authenticateClient($_POST,$_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'');
            if ($path==='/oauth/revoke') { OAuth::revoke($client,OAuth::text($_POST,'token'));json_response([]); }
            $result=match(OAuth::text($_POST,'grant_type')) {
                'authorization_code'=>OAuth::exchange($client,$_POST),
                'refresh_token'=>OAuth::refresh($client,$_POST),
                default=>throw new OAuthError('unsupported_grant_type'),
            };
            json_response($result);
        } catch(OAuthError $error) {
            if ($error->status===401) header('WWW-Authenticate: Basic realm="Sillage OAuth"');
            json_response(['error'=>$error->error],$error->status);
        }
    }
    public function authorize(): void
    {
        if ($error=\App\Mcp\Endpoint::error()) throw new \InvalidArgumentException($error['message']);
        Security::limit('oauth-authorize',60,60);
        try { $request=OAuth::authorization($_GET); }
        catch(OAuthError $error) {
            $this->invalidConsent('La demande OAuth est invalide ('.$error->error.'). Vérifiez le client, l’URL de rappel, la ressource et PKCE.');return;
        }
        $user=Auth::user();
        if (!$user) {
            $_SESSION['oauth_after_login']='/oauth/authorize?'.http_build_query($_GET,'','&',PHP_QUERY_RFC3986);
            redirect('/login');
        }
        // The validated request is stored server-side under a random nonce (valid
        // 10 min, tied to this user); the consent form only posts back the nonce.
        $nonce=bin2hex(random_bytes(24));
        $pending=array_filter($_SESSION['oauth_consents']??[],fn($entry)=>$entry['expires']>=time());
        // Bound temporary session storage while allowing several client windows to coexist.
        $pending=array_slice($pending,-7,null,true);
        $pending[$nonce]=['request'=>$request,'expires'=>time()+600,'user_id'=>$user['id']];
        $_SESSION['oauth_consents']=$pending;
        $this->renderConsent($request,$user,$nonce);
    }
    /** Consent page; only scopes allowed for the user's role (plus offline_access) can be granted. */
    private function renderConsent(array $request,array $user,string $nonce,?string $error=null): void
    {
        // The CSP must allow the form to redirect to the client's callback URL.
        Security::contentSecurityPolicy($request['redirect_uri']);
        header('Referrer-Policy: no-referrer');
        $available=array_values(array_intersect($request['scopes'],[...McpToken::availableScopes($user['role']),'offline_access']));
        View::render('oauth-consent',['guest'=>true,'title'=>'Autoriser une connexion MCP','request'=>$request,'nonce'=>$nonce,'user'=>$user,'available'=>$available,'consentError'=>$error]);
    }
    private function invalidConsent(?string $message=null): void
    {
        http_response_code(422);
        View::render('oauth-error',['guest'=>true,'title'=>'Autorisation à relancer','consentError'=>$message]);
    }
    /**
     * Handles the consent form: on "allow" issues an authorization code, on
     * "deny" returns access_denied, then redirects to the client's redirect_uri
     * (taken from the stored, already validated request, never from the form).
     */
    public function consent(): void
    {
        $user=Auth::user();
        $nonce=is_string($_POST['nonce']??null)?$_POST['nonce']:'';
        $pending=$_SESSION['oauth_consents'][$nonce]??null;
        if (!$user || !$pending || $pending['expires']<time() || (int)$pending['user_id']!==(int)$user['id']) {
            unset($_SESSION['oauth_consents'][$nonce]);
            $this->invalidConsent();return;
        }
        $request=$pending['request'];$params=['state'=>$request['state'],'iss'=>OAuth::issuer()];
        if (($_POST['decision']??'')==='deny') $params['error']='access_denied';
        elseif (($_POST['decision']??'')==='allow') {
            try { $params['code']=OAuth::code($request,$user,(array)($_POST['scopes']??[])); }
            catch(OAuthError $error) {
                if ($error->error!=='invalid_scope') {
                    unset($_SESSION['oauth_consents'][$nonce]);
                    $this->invalidConsent();return;
                }
                http_response_code(422);
                $this->renderConsent($request,$user,$nonce,'Choisissez au moins un droit disponible pour votre compte, puis réessayez.');return;
            }
        } else {
            http_response_code(422);
            $this->renderConsent($request,$user,$nonce,'Choisissez « Autoriser la connexion » ou « Refuser ».');return;
        }
        unset($_SESSION['oauth_consents'][$nonce]);
        Security::contentSecurityPolicy($request['redirect_uri']);
        header('Referrer-Policy: no-referrer');
        header('Location: '.$request['redirect_uri'].(str_contains($request['redirect_uri'],'?')?'&':'?').http_build_query($params,'','&',PHP_QUERY_RFC3986),true,303);exit;
    }
    /** Registers an OAuth client; its credentials are shown once on /settings via the session. */
    public function createClient(): void
    {
        Auth::admin();$_SESSION['new_oauth_client']=OAuth::createClient((string)($_POST['name']??''),(string)($_POST['redirect_uri']??''),(string)($_POST['auth_method']??''));
        redirect('/settings#oauth');
    }
    /** Deletes a client; its grants and tokens go with it through ON DELETE CASCADE foreign keys. */
    public function deleteClient(string $id): void
    {
        Auth::admin();DB::run('DELETE FROM oauth_clients WHERE id=?',[$id]);
        flash('Client OAuth supprimé. Ses autorisations et jetons sont révoqués.');redirect('/settings#oauth');
    }
    public function revokeGrant(string $id): void
    {
        $user=Auth::requireUser();DB::run('DELETE FROM oauth_grants WHERE id=? AND user_id=?',[$id,$user['id']]);
        flash('Connexion OAuth révoquée.');redirect('/settings#oauth');
    }
}
