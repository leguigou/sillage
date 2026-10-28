from html.parser import HTMLParser

class CopyFields(HTMLParser):
    def __init__(self, page):
        super().__init__(); self.inputs={}; self.buttons={}; self.feed(page)
    def handle_starttag(self, tag, attrs):
        attrs=dict(attrs)
        if tag=='input' and attrs.get('id'): self.inputs[attrs['id']]=attrs
        if tag=='button' and attrs.get('data-copy-target'): self.buttons.setdefault(attrs.get('aria-label'),attrs['data-copy-target'])
    def value(self,label): return self.inputs[self.buttons['Copier '+label]]['value']

"""OAuth discovery, browser consent and PKCE token exchange through real HTTP/MySQL."""
import base64, hashlib
http_support.client=admin_client;csrf,oauth_page=get_token('/settings')
check('Connexions OAuth' in oauth_page,'OAuth setup visible in settings')
_,as_body,_=call('/.well-known/oauth-authorization-server')
oauth_metadata=json.loads(as_body)
check(oauth_metadata['issuer']==BASE and oauth_metadata['code_challenge_methods_supported']==['S256'],'OAuth discovery advertises issuer and PKCE S256')
check('registration_endpoint' not in oauth_metadata and not oauth_metadata['client_id_metadata_document_supported'],'only pre-registered clients advertised, no fake DCR or CIMD')
_,rs_body,_=call('/.well-known/oauth-protected-resource/mcp')
check(json.loads(rs_body)['resource']==BASE+'/mcp','protected resource metadata points at exact MCP audience')
check(call('/oauth/token')[0]==405,'token endpoint requires POST')
check(call('/oauth/token',{'client_id':'bad'},True)[0]==401,'unknown OAuth client refused')
oauth_cb='https://client.example/callback'
oauth_status,oauth_page,_=call('/oauth/clients',{'_csrf':csrf,'name':'ChatGPT test OAuth','redirect_uri':oauth_cb,'auth_method':'none'})
oauth_id=CopyFields(oauth_page).value('ID client OAuth')
check(CopyFields(oauth_page).value('URL du serveur MCP')==BASE+'/mcp','OAuth server value has an accessible copy button')
check(oauth_status==200 and 'Client public' in oauth_page,'administrator registers public OAuth client with exact callback')
check(call('/oauth/clients',{'name':'Missing CSRF','redirect_uri':oauth_cb,'auth_method':'none'},True)[0]==403,'OAuth client creation requires CSRF')
oauth_verifier='v'*64;oauth_challenge=base64.urlsafe_b64encode(hashlib.sha256(oauth_verifier.encode()).digest()).decode().rstrip('=')
oauth_params={'client_id':oauth_id,'response_type':'code','redirect_uri':oauth_cb,'code_challenge_method':'S256','code_challenge':oauth_challenge,'resource':BASE+'/mcp','scope':'read write offline_access','state':'state + opaque'}
def oauth_authorize(params=oauth_params):
    return call('/oauth/authorize?'+urllib.parse.urlencode(params))
check(oauth_authorize({**oauth_params,'redirect_uri':'https://evil.example/callback'})[0]==422,'unregistered callback rejected without redirect')
check(oauth_authorize({**oauth_params,'code_challenge_method':'plain'})[0]==422,'plain PKCE rejected')
check(oauth_authorize({**oauth_params,'resource':'https://other.example/mcp'})[0]==422,'foreign OAuth resource rejected')
oauth_saved_client=http_support.client
http_support.client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
check(oauth_authorize()[2].endswith('/login'),'anonymous authorization requires Sillage sign-in')
http_support.client=oauth_saved_client
class OAuthNoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*args,**kwargs): return None
oauth_jar=next(h.cookiejar for h in admin_client.handlers if isinstance(h,urllib.request.HTTPCookieProcessor))
oauth_browser=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(oauth_jar),OAuthNoRedirect())
def oauth_consent(fields):
    try: response=oauth_browser.open(urllib.request.Request(BASE+'/oauth/authorize',data=urllib.parse.urlencode(fields,doseq=True).encode()))
    except urllib.error.HTTPError as error: return error.code,error.headers.get('Location',''),error.read().decode()
    return response.status,response.headers.get('Location',''),response.read().decode()
def oauth_code(scopes=['read','write','offline_access']):
    with admin_client.open(BASE+'/oauth/authorize?'+urllib.parse.urlencode(oauth_params)) as response:
        status=response.status; page=response.read().decode(); policy=response.headers['Content-Security-Policy']
    check("form-action 'self' https://client.example" in policy,'consent CSP permits the registered callback origin for browser redirects')
    check(status==200 and 'Autoriser ChatGPT test OAuth' in page,'consent page identifies client and account')
    nonce=re.search(r'name="nonce" value="([a-f0-9]+)"',page).group(1)
    data={'_csrf':token(page),'nonce':nonce,'decision':'allow','scopes[]':scopes}
    status,location,_=oauth_consent(data)
    check(status==303 and location.startswith(oauth_cb+'?'),'consent returns code to registered callback only')
    query=urllib.parse.parse_qs(urllib.parse.urlsplit(location).query)
    check(query['state']==[oauth_params['state']] and query['iss']==[BASE],'callback preserves state and issuer')
    again=oauth_consent(data)
    check(again[0]==422 and not again[1] and 'Relancez l’autorisation' in again[2],'used consent explains retry in place without a settings redirect')
    return query['code'][0]
# Independent pending requests must preserve their own state and decisions.
_,tab_one,_=oauth_authorize({**oauth_params,'state':'first-tab','scope':'read'})
_,tab_two,_=oauth_authorize({**oauth_params,'state':'second-tab','scope':'read'})
nonce_one=re.search(r'name="nonce" value="([a-f0-9]+)"',tab_one).group(1)
nonce_two=re.search(r'name="nonce" value="([a-f0-9]+)"',tab_two).group(1)
status,location,_=oauth_consent({'_csrf':token(tab_one),'nonce':nonce_one,'decision':'allow','scopes[]':['read']})
check(status==303 and urllib.parse.parse_qs(urllib.parse.urlsplit(location).query).get('state')==['first-tab'],'opening a second authorization does not invalidate the first consent')
status,location,_=oauth_consent({'_csrf':token(tab_two),'nonce':nonce_two,'decision':'deny'})
query=urllib.parse.parse_qs(urllib.parse.urlsplit(location).query)
check(status==303 and query.get('error')==['access_denied'] and query.get('state')==['second-tab'],'denial returns to its own client request with exact state')
_,retry_page,_=oauth_authorize()
retry_nonce=re.search(r'name="nonce" value="([a-f0-9]+)"',retry_page).group(1)
retry={'_csrf':token(retry_page),'nonce':retry_nonce,'decision':'allow'}
status,location,error_page=oauth_consent(retry)
check(status==422 and not location and 'Choisissez au moins un droit' in error_page,'empty selection reports error on consent instead of redirecting to settings')
status,location,_=oauth_consent({**retry,'scopes[]':['read']})
check(status==303 and location.startswith(oauth_cb+'?'),'valid selection can retry the same pending consent after validation error')
status,location,error_page=oauth_consent({'_csrf':csrf,'nonce':'unknown','decision':'allow','scopes[]':['read']})
check(status==422 and not location and 'Relancez l’autorisation' in error_page,'unknown pending request gives an actionable authorization error')
with admin_client.open(BASE+'/settings') as response:
    check("form-action 'self'" in response.headers['Content-Security-Policy'] and 'https://client.example' not in response.headers['Content-Security-Policy'],'ordinary settings retain same-origin-only form policy')
raw_code=oauth_code()
oauth_exchange={'grant_type':'authorization_code','client_id':oauth_id,'code':raw_code,'redirect_uri':oauth_cb,'code_verifier':oauth_verifier,'resource':BASE+'/mcp'}
check(json.loads(call('/oauth/token',{**oauth_exchange,'code_verifier':'x'*64},True)[1])['error']=='invalid_grant','wrong verifier refused through HTTP')
oauth_status,oauth_body,_=call('/oauth/token',oauth_exchange,True);oauth_tokens=json.loads(oauth_body)
check(oauth_status==200 and oauth_tokens['token_type']=='Bearer' and 'refresh_token' in oauth_tokens,'PKCE exchange produces bearer and refresh tokens')
check(json.loads(call('/oauth/token',oauth_exchange,True)[1])['error']=='invalid_grant','authorization code replay refused over HTTP')
def oauth_rpc(key):
    payload={'jsonrpc':'2.0','id':1,'method':'tools/list'}
    request=urllib.request.Request(BASE+'/mcp',data=json.dumps(payload).encode(),headers={'Authorization':'Bearer '+key,'Content-Type':'application/json','Accept':'application/json, text/event-stream','MCP-Protocol-Version':'2025-11-25'})
    try:
        with urllib.request.urlopen(request) as response: return response.status,json.loads(response.read())
    except urllib.error.HTTPError as error: return error.code,json.loads(error.read())
status,tool_page=oauth_rpc(oauth_tokens['access_token'])
check(status==200 and any(t['name']=='tickets_create' for t in tool_page['result']['tools']),'OAuth access token invokes existing MCP with granted write scope')
check(all('securitySchemes' in t and '_meta' in t for t in tool_page['result']['tools']),'MCP tool descriptors advertise OAuth scope requirements')
refresh={'grant_type':'refresh_token','client_id':oauth_id,'refresh_token':oauth_tokens['refresh_token'],'resource':BASE+'/mcp'}
_,oauth_body,_=call('/oauth/token',refresh,True);renewed=json.loads(oauth_body)
check(renewed['refresh_token']!=oauth_tokens['refresh_token'],'HTTP refresh rotates credential')
check(json.loads(call('/oauth/token',refresh,True)[1])['error']=='invalid_grant' and oauth_rpc(renewed['access_token'])[0]==401,'refresh replay revokes the grant and access tokens')
raw_code=oauth_code(['read','offline_access'])
_,oauth_body,_=call('/oauth/token',{**oauth_exchange,'code':raw_code},True);oauth_tokens=json.loads(oauth_body)
check(oauth_rpc(oauth_tokens['access_token'])[0]==200,'new consent works after previous grant revocation')
check(call('/oauth/revoke',{'client_id':oauth_id,'token':oauth_tokens['refresh_token']},True)[0]==200 and oauth_rpc(oauth_tokens['access_token'])[0]==401,'standard revocation endpoint revokes refresh and access')
_,oauth_page,_=call('/settings');check('ChatGPT test OAuth' in oauth_page and 'Mes connexions autorisées' in oauth_page,'OAuth clients and user grants visible in settings')
check(call('/oauth/clients/'+oauth_id+'/delete',{'_csrf':csrf})[0]==200,'administrator can remove client and dependent grants')
for auth_method in ['client_secret_post','client_secret_basic']:
    _,page,_=call('/oauth/clients',{'_csrf':csrf,'name':'ChatGPT test OAuth','redirect_uri':oauth_cb,'auth_method':auth_method})
    confidential_id=CopyFields(page).value('ID client OAuth')
    confidential_secret=CopyFields(page).value('Secret du client OAuth — affiché une seule fois')
    check(confidential_secret not in call('/settings')[1],'OAuth client secret displayed once '+auth_method)
    params={**oauth_params,'client_id':confidential_id}
    _,page,_=oauth_authorize(params)
    nonce=re.search(r'name="nonce" value="([a-f0-9]+)"',page).group(1)
    status,location,_=oauth_consent({'_csrf':token(page),'nonce':nonce,'decision':'allow','scopes[]':['read']})
    code=urllib.parse.parse_qs(urllib.parse.urlsplit(location).query)['code'][0]
    data={**oauth_exchange,'client_id':confidential_id,'code':code}
    headers={'Accept':'application/json','Content-Type':'application/x-www-form-urlencoded'}
    if auth_method=='client_secret_post': data['client_secret']=confidential_secret
    else: headers['Authorization']='Basic '+base64.b64encode((confidential_id+':'+confidential_secret).encode()).decode()
    with urllib.request.urlopen(urllib.request.Request(BASE+'/oauth/token',data=urllib.parse.urlencode(data).encode(),headers=headers)) as response:
        issued=json.loads(response.read())
    check(oauth_rpc(issued['access_token'])[0]==200,'real token exchange '+auth_method)
    call('/oauth/clients/'+confidential_id+'/delete',{'_csrf':csrf})
    check(oauth_rpc(issued['access_token'])[0]==401,'client removal invalidates issued access '+auth_method)
