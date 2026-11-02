"""Real auth/CSRF/controller/MySQL workflow; only the Git transport is simulated."""
import http.cookiejar, urllib.request, urllib.error, urllib.parse, json, re, uuid, hashlib, hmac
import http_workflows_support as support
from http_workflows_support import BASE, call, check, token, get_token, passed_count

csrf,_=get_token('/login')
_,html,_=call('/login',{'_csrf':csrf,'email':'qa.admin@example.com','password':'Tests-Sillage-2026!'})
csrf=token(html);admin=support.client
suffix=uuid.uuid4().hex[:6]
_,_,project_url=call('/projects',{'_csrf':csrf,'name':'Branches Git '+suffix,'key':'BG'+suffix.upper()})
pid=urllib.parse.parse_qs(urllib.parse.urlsplit(project_url).query)['project'][0]
_,_,ticket_url=call('/tickets',{'_csrf':csrf,'project_id':pid,'title':'Corriger le sitemap XML','description':'Projet fictif de contrôle des branches.'})
tid=urllib.parse.urlsplit(ticket_url).path.rsplit('/',1)[-1]
_,initial,_=call('/tickets/'+tid);version=re.search(r'name="version" value="(\d+)"',initial).group(1)
check('Aucun dépôt Git n’est lié' in initial and 'id="ticket-branch-dialog"' not in initial,'ticket without repository explains configuration and offers no branch selector')
connections={};repositories={}
for provider,stem in [('bitbucket','bitbucket'),('github','github')]:
    repo='demo/'+stem+'-'+suffix;repositories[provider]=repo
    status,body,_=call('/connections',{'_csrf':csrf,'project_id':pid,'provider':provider,'repository':repo,'token':'synthetic-git-token'},True)
    check(status==200,provider+' fixture connection verified through production HTTP client')
    connections[provider]=json.loads(body)['id']
    if provider=='bitbucket':
        _,single_panel,_=call('/tickets/'+tid+'/development')
        check('Bitbucket · '+repo in single_panel and 'GitHub · demo/github-' not in single_panel and 'value="'+str(connections[provider])+'" selected' in single_panel,'single configured Bitbucket repository is the only offered provider and is preselected')
bb=connections['bitbucket'];gh=connections['github']
check(call('/connections/'+str(bb)+'/webhook',{},True)[0]==403,'webhook setup reveal requires CSRF')
status,setup_body,_=call('/connections/'+str(bb)+'/webhook',{'_csrf':csrf},True)
webhook_setup=json.loads(setup_body)
check(status==200 and re.fullmatch('[a-f0-9]{64}',webhook_setup['secret']) is not None and webhook_setup['url']==BASE+'/webhooks/'+str(bb),'administrator can retrieve existing webhook setup without rotating it')
with admin.open(urllib.request.Request(BASE+'/connections/'+str(bb)+'/webhook',data=urllib.parse.urlencode({'_csrf':csrf}).encode(),headers={'Accept':'application/json'})) as setup_reply:
    setup_reply.read();check('no-store' in setup_reply.headers.get('Cache-Control',''),'revealed webhook setup cannot be cached')
_,setup_logs,_=call('/administration/logs?channel=api',json_accept=True)
check(webhook_setup['secret'] not in setup_logs,'webhook reveal body is excluded from technical logs')
check(call('/projects/'+pid+'/update',{'_csrf':csrf,'repository_connection_id':bb},True)[0]==200,'project chooses Bitbucket repository as default')
status,panel,_=call('/tickets/'+tid+'/development')
check(status==200 and 'Bitbucket · '+repositories['bitbucket'] in panel and 'GitHub · '+repositories['github'] in panel,'ticket lists only its configured providers')
check('value="'+str(bb)+'" selected' in panel,'project primary repository selected in branch dialog')
check('webhook signé' in panel and 'Issues' in panel,'ticket explains key matching webhooks and scope of Git integration')
path='/tickets/'+tid+'/development'
check(call(path+'/branches',{'connection_id':bb},True)[0]==403,'branch catalogue requires CSRF')
for provider,cid in connections.items():
    status,body,_=call(path+'/branches',{'_csrf':csrf,'connection_id':cid,'page':1},True)
    data=json.loads(body)
    check(status==200 and data['total']==56 and len(data['items'])==50 and data['branches'][:3]==['40908_flow_sitemap','staging','feature/sitemap-sans-cle'],provider+' complete catalogue sorted by tip commit date across source pages')
    status,body,_=call(path+'/branches',{'_csrf':csrf,'connection_id':cid,'page':2},True)
    tail=json.loads(body)
    check(status==200 and len(tail['branches'])==6 and tail['branches'][-1]=='sans-date' and tail['items'][-1]['last_commit_at'] is None,provider+' chronological pagination puts unknown dates last')
    filtered=json.loads(call(path+'/branches',{'_csrf':csrf,'connection_id':cid,'search':'sitemap'},True)[1])
    check(filtered['branches']==['40908_flow_sitemap','feature/sitemap-sans-cle'],provider+' server search retains chronological ordering')
    check(call(path+'/link',{'_csrf':csrf,'connection_id':cid,'branch_name':'missing'},True)[0]==422,provider+' unavailable branch rejected')
check(call(path+'/link',{'_csrf':csrf,'connection_id':bb,'branch_name':'unexpected-name'},True)[0]==422,'provider cannot substitute a different branch')
check(call(path+'/link',{'_csrf':csrf,'connection_id':bb,'branch_name':'../other'},True)[0]==422,'branch path traversal refused before provider request')
check(call(path+'/branches',{'_csrf':csrf,'connection_id':bb,'page':0},True)[0]==422,'invalid branch page refused')
def traced_catalog(fields):
    request=urllib.request.Request(BASE+path+'/branches',data=urllib.parse.urlencode({'_csrf':csrf,'connection_id':gh,**fields}).encode(),headers={'Accept':'application/json'})
    with admin.open(request) as reply:
        result=json.loads(reply.read());correlation=reply.headers.get('X-Request-ID')
    records=json.loads(call('/administration/logs?channel=development&request_id='+correlation,json_accept=True)[1])['rows']
    return result,records
cached,cached_calls=traced_catalog({'page':1})
check(len(cached_calls)==0,'warm GitHub catalogue reuses complete snapshot without provider calls')
fresh,fresh_calls=traced_catalog({'page':1,'refresh':'1'})
check(len(fresh_calls)==3 and all(row['method']=='POST' and row['endpoint']=='https://api.github.com/graphql' for row in fresh_calls),'cold GitHub catalogue reads three source pages without per-branch requests')
check(fresh['branches']==cached['branches'],'warm and refreshed snapshots return the same chronological order')
check(call('/connections/'+str(gh)+'/update',{'_csrf':csrf,'token':'synthetic-git-token'},True)[0]==200,'Git connection credential can be reverified')
rotated,rotated_calls=traced_catalog({'page':1})
check(len(rotated_calls)==3,'changed credential fingerprint invalidates cached branch catalogue')
check(call(path+'/branches',{'_csrf':csrf,'connection_id':gh,'search':'graphql-error'},True)[0]==422,'GraphQL error with HTTP 200 cannot become a complete empty catalogue')
check(call(path+'/branches',{'_csrf':csrf,'connection_id':gh,'search':'cursor-loop'},True)[0]==422,'repeated GraphQL cursor is rejected instead of publishing a partial sort')
check(call(path+'/branches',{'_csrf':csrf,'connection_id':gh,'search':'x'*101},True)[0]==422,'oversized catalogue search refused')
status,body,_=call(path+'/link',{'_csrf':csrf,'connection_id':bb,'branch_name':'feature/sitemap-sans-cle'},True)
link=json.loads(body)['id'];check(status==200,'Bitbucket branch without ticket key linked after verification')
status,body,_=call(path+'/link',{'_csrf':csrf,'connection_id':bb,'branch_name':'feature/sitemap-sans-cle'},True)
check(status==200 and json.loads(body)['id']==link,'repeated branch link is idempotent')
status,panel,_=call(path);check('Liens manuels de branches' in panel and 'feature/sitemap-sans-cle' in panel,'verified manual branch visible immediately in refresh fragment')
_,after,_=call('/tickets/'+tid)
check(re.search(r'name="version" value="(\d+)"',after).group(1)==version,'manual link preserves ticket fields and version')
check('synthetic-git-token' not in after and 'secret_cipher' not in after,'provider credentials never enter ticket HTML')

# Receive a real signed HTTP webhook using the one-time synthetic connection setup.
_,setups,_=call('/development?project='+pid)
hook=re.search(r'/webhooks/'+str(bb)+r'.*?Secret : <code>([a-f0-9]{64})</code>',setups,re.S).group(1)
_,ordinary_setup,_=call('/development?project='+pid)
check('data-webhook-reveal' in ordinary_setup and webhook_setup['secret'] not in ordinary_setup,'saved webhook secret is absent from ordinary provider HTML')
sha='f'*40
payload={'repository':{'full_name':repositories['bitbucket']},'push':{'changes':[{'new':{'type':'branch','name':'feature/sitemap-sans-cle','target':{'hash':sha}},'commits':[{'hash':sha,'message':'Correction sans clé','date':'2026-11-02T10:00:00Z'}]}]}}
raw=json.dumps(payload).encode();signature='sha256='+hmac.new(hook.encode(),raw,hashlib.sha256).hexdigest()
with urllib.request.urlopen(urllib.request.Request(BASE+'/webhooks/'+str(bb),data=raw,headers={'Content-Type':'application/json','X-Hub-Signature-256':signature,'X-Event-Key':'repo:push'})) as reply:
    check(reply.status==200,'signed Bitbucket push received through actual webhook endpoint')
_,panel,_=call(path)
check('Correction sans clé' in panel and 'Liens manuels de branches' in panel,'incoming commit follows manual branch and branch association survives update')
check(call(path+'/links/'+str(link)+'/delete',{},True)[0]==403,'unlink requires CSRF')
check(call(path+'/links/'+str(link)+'/delete',{'_csrf':csrf},True)[0]==200,'manual branch can be unlinked')
_,panel,_=call(path)
check('Liens manuels de branches' not in panel and 'Correction sans clé' in panel,'unlink retains already received commit observations')
_,_,foreign_url=call('/projects',{'_csrf':csrf,'name':'Sans connexion '+suffix,'key':'BX'+suffix.upper()})
foreign_pid=urllib.parse.parse_qs(urllib.parse.urlsplit(foreign_url).query)['project'][0]
_,_,foreign_ticket=call('/tickets',{'_csrf':csrf,'project_id':foreign_pid,'title':'Hors dépôt'})
foreign_tid=urllib.parse.urlsplit(foreign_ticket).path.rsplit('/',1)[-1]
check(call('/tickets/'+foreign_tid+'/development/link',{'_csrf':csrf,'connection_id':gh,'branch_name':'main'},True)[0]==422,'shared credential cannot be used outside the ticket project repositories')

support.client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
viewer_csrf,_=get_token('/login');_,viewer_html,_=call('/login',{'_csrf':viewer_csrf,'email':'qa.viewer@example.com','password':'Tests-Sillage-2026!'})
viewer_csrf=token(viewer_html);_,viewer_panel,_=call(path)
check('id="ticket-branch-dialog"' not in viewer_panel,'read-only viewer receives no branch mutation dialog')
check(call('/connections/'+str(bb)+'/webhook',{'_csrf':viewer_csrf},True)[0]==403,'read-only viewer cannot reveal webhook secret')
for operation,args in [('branches',{}),('link',{'branch_name':'main'}),('links/'+str(link)+'/delete',{})]:
    check(call(path+'/'+operation,{'_csrf':viewer_csrf,'connection_id':bb,**args},True)[0]==403,'read-only viewer cannot '+operation)
support.client=admin
print('Git UI inspection project '+pid+' ticket '+tid)
print('\n',passed_count(),'ticket branch HTTP checks passed (synthetic Git transport).')
