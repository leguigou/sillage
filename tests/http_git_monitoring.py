"""Real signed webhooks, durable jobs and monitored provider contracts; synthetic Git HTTP only."""
import json,re,uuid,subprocess,urllib.parse,urllib.request,hashlib,hmac,http.cookiejar
import http_workflows_support as support
from http_workflows_support import call,check,get_token,token,BASE,passed_count
subprocess.run(['php','-c','.runtime/php-config/php.ini','tests/git_worker.php','clear'],check=True,capture_output=True)
csrf,_=get_token('/login');_,html,_=call('/login',{'_csrf':csrf,'email':'qa.admin@example.com','password':'Tests-Sillage-2026!'});csrf=token(html);admin=support.client
suffix=uuid.uuid4().hex[:6];_,_,url=call('/projects',{'_csrf':csrf,'name':'Suivi Git '+suffix,'key':'GM'+suffix.upper()});pid=urllib.parse.parse_qs(urllib.parse.urlsplit(url).query)['project'][0]
_,_,url=call('/tickets',{'_csrf':csrf,'project_id':pid,'title':'Suivi du sitemap et livraison'});tid=urllib.parse.urlsplit(url).path.rsplit('/',1)[-1];path='/tickets/'+tid+'/development'
connections={};repos={}
for provider in ['github','bitbucket']:
    repos[provider]='demo/'+provider+'-'+suffix
    status,body,_=call('/connections',{'_csrf':csrf,'project_id':pid,'provider':provider,'repository':repos[provider],'token':'synthetic-git-token'},True);check(status==200,provider+' monitoring repository connected')
    cid=json.loads(body)['id'];connections[provider]=cid
    check(call(path+'/link',{'_csrf':csrf,'connection_id':cid,'branch_name':'40908_flow_sitemap'},True)[0]==200,provider+' linked branch queues recovery')
    check(call('/connections/'+str(cid)+'/recover',{'project_id':pid},True)[0]==403,'monitor recovery requires CSRF')
    check(call('/connections/'+str(cid)+'/recover',{'_csrf':csrf,'project_id':pid},True)[0]==202,'monitor recovery is queued without an inline provider call')
def work():
    result=subprocess.run(['php','-c','.runtime/php-config/php.ini','tests/git_worker.php'],check=True,capture_output=True,text=True);return json.loads(result.stdout)
result=work();check(result['processed']>5,'worker executes queued catch-up and follows dependent tasks')
_,panel,_=call(path)
check('Conflit de fusion' in panel and 'merge-conflict-badge' in panel,'confirmed merge conflict has orange warning pictogram')
check('Tests PHP et compilation' in panel and 'Tests fonctionnels' in panel and 'Échec' in panel,'GitHub workflows and per-commit checks visible')
check('Pipeline #11' in panel and 'Tests PHP' in panel,'Bitbucket pipelines and commit statuses visible')
check('staging' in panel and 'production' in panel and 'Déploiement confirmé par le fournisseur' in panel and 'Déploiement non confirmé comme réussi' in panel,'deployment environments distinguish success from pending or running')
_,listing,_=call('/list?project='+pid);check('Conflit Git' in listing,'ticket list flags confirmed PR merge conflicts')
check('Service à démarrer' not in panel and 'Suivi automatique' in panel,'worker heartbeat visible after background processing')
for provider,cid in connections.items():
    status,body,_=call('/connections/'+str(cid)+'/sync-status?project='+pid,json_accept=True);data=json.loads(body)
    check(status==200 and data['worker']['active']==1 and data['settings']['last_success_at'],provider+' last success and live worker status available without secrets')
    check('synthetic-git-token' not in body and 'payload_json' not in body and 'lease_owner' not in body,'monitor status excludes credentials and internal payloads')
# Access diagnostics exercise each provider independently without changing its credential.
for provider,cid in connections.items():
    endpoint='/connections/'+str(cid)+'/test-access'
    check(call(endpoint,{'project_id':pid},True)[0]==403,'access test requires CSRF')
    status,body,_=call(endpoint,{'_csrf':csrf,'project_id':pid,'token':'synthetic-git-readonly-token'},True);data=json.loads(body)
    checks={row['key']:row for row in data['checks']}
    check(status==200 and data['credential']=='draft' and checks['repository']['ok'] and checks['pull_requests']['ok'] and checks['pipelines']['http_status']==403 and checks['deployments']['http_status']==403,provider+' distinguishes repository access from missing CI permissions')
    check('synthetic-git-' not in body and re.fullmatch('[a-f0-9]{32}',data['request_id']),provider+' diagnostic excludes credentials and exposes correlation')
    status,body,_=call(endpoint,{'_csrf':csrf,'project_id':pid},True);data=json.loads(body)
    check(status==200 and data['credential']=='saved' and len(data['checks'])==4 and all(row['ok'] for row in data['checks']),provider+' draft test did not overwrite saved credential')
# Signed provider event updates tests immediately and enqueues reconciliation; no remote run is started.
cid=connections['github'];setup=json.loads(call('/connections/'+str(cid)+'/webhook',{'_csrf':csrf},True)[1]);payload={'repository':{'full_name':repos['github']},'workflow_run':{'id':501,'name':'Tests PHP et compilation','head_sha':'c'*40,'head_branch':'40908_flow_sitemap','status':'completed','conclusion':'success','run_attempt':2,'updated_at':'2026-11-03T02:00:00Z'}};raw=json.dumps(payload).encode();signature='sha256='+hmac.new(setup['secret'].encode(),raw,hashlib.sha256).hexdigest()
with urllib.request.urlopen(urllib.request.Request(BASE+'/webhooks/'+str(cid),data=raw,headers={'Content-Type':'application/json','X-Hub-Signature-256':signature,'X-GitHub-Event':'workflow_run'})) as response:check(response.status==200,'signed workflow completion received through actual webhook')
_,panel,_=call(path);check('Tests PHP et compilation' in panel and 'Réussi' in panel,'new run attempt result visible without manual sync')
work();_,panel,_=call(path);check('Tests PHP et compilation' in panel and 'Conflit de fusion' in panel,'reconciliation preserves webhook observations and conflict status')
check(call('/connections/'+str(cid)+'/sync-settings',{'_csrf':csrf,'project_id':pid,'enabled':'1','interval':'5'},True)[0]==200,'administrator configures shared polling interval')
check(call('/connections/'+str(cid)+'/sync-settings',{'_csrf':csrf,'project_id':pid,'enabled':'1','interval':'0'},True)[0]==422,'invalid polling interval refused')
# A restricted credential must make missing monitoring permissions visible without erasing prior facts.
check(call('/connections/'+str(cid)+'/update',{'_csrf':csrf,'token':'synthetic-git-readonly-token'},True)[0]==200,'PR-only token can still be verified without silently granting permissions')
call('/connections/'+str(cid)+'/recover',{'_csrf':csrf,'project_id':pid},True);work();data=json.loads(call('/connections/'+str(cid)+'/sync-status?project='+pid,json_accept=True)[1]);check(data['problems']>0 and any('403' in (job['last_error'] or '') for job in data['jobs']),'missing monitoring permissions displayed as retryable errors')
_,panel,_=call(path);check('Tests PHP et compilation' in panel and 'Déploiement confirmé par le fournisseur' in panel,'provider errors do not erase previous pipeline and deployment observations')
check(call('/connections/'+str(cid)+'/update',{'_csrf':csrf,'token':'synthetic-git-token'},True)[0]==200,'monitoring credential restored')
call('/connections/'+str(cid)+'/recover',{'_csrf':csrf,'project_id':pid},True);work();data=json.loads(call('/connections/'+str(cid)+'/sync-status?project='+pid,json_accept=True)[1]);check(data['problems']==0,'manual retry immediately clears recovered permission errors')
# Cross-project and read-only boundaries.
_,_,url=call('/projects',{'_csrf':csrf,'name':'Autre suivi '+suffix,'key':'GX'+suffix.upper()});other=urllib.parse.parse_qs(urllib.parse.urlsplit(url).query)['project'][0]
check(call('/connections/'+str(cid)+'/recover',{'_csrf':csrf,'project_id':other},True)[0]==422,'recovery cannot use a repository outside its project')
check(call('/connections/'+str(cid)+'/test-access',{'_csrf':csrf,'project_id':other},True)[0]==422,'access test rejects unrelated project')
support.client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()));vcsrf,_=get_token('/login');_,vhtml,_=call('/login',{'_csrf':vcsrf,'email':'qa.viewer@example.com','password':'Tests-Sillage-2026!'});vcsrf=token(vhtml)
check(call('/connections/'+str(cid)+'/recover',{'_csrf':vcsrf,'project_id':pid},True)[0]==403,'read-only viewer cannot queue recovery')
check(call('/connections/'+str(cid)+'/sync-settings',{'_csrf':vcsrf,'project_id':pid,'enabled':'0','interval':'5'},True)[0]==403,'read-only viewer cannot change automatic monitoring')
check(call('/connections/'+str(cid)+'/sync-status?project='+pid,json_accept=True)[0]==200,'read-only viewer can inspect public monitoring facts')
check(call('/connections/'+str(cid)+'/test-access',{'_csrf':vcsrf,'project_id':pid},True)[0]==403,'viewer cannot test provider credentials')
support.client=admin
print('Monitoring UI project '+pid+' ticket '+tid)
print('\n',passed_count(),'Git monitoring HTTP checks passed (synthetic providers).')
