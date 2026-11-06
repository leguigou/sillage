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
# A description edit can attach an existing PR with no key in its title or branch.
description_key='BG'+suffix.upper()+'-1'
for provider,event_header,events,object_key,body_key,id_key,author_date in [
    ('bitbucket','X-Event-Key',('pullrequest:created','pullrequest:updated'),'pullrequest','description','id','updated_on'),
    ('github','X-GitHub-Event',('pull_request','pull_request'),'pull_request','body','number','updated_at'),
]:
    repo=repositories[provider]
    secret=json.loads(call('/connections/'+str(connections[provider])+'/webhook',{'_csrf':csrf},True)[1])['secret']
    title='PR reference dans la description '+provider
    pr={id_key:91234,'title':title,'state':'OPEN',body_key:'Correction du flux XML',author_date:'2026-11-06T10:00:00Z'}
    payload={'repository':{'full_name':repo},object_key:pr}
    for index,event in enumerate(events):
        if provider=='github':
            payload['action']='edited' if index else 'opened'
        if index:
            pr[body_key]='Correction du flux XML. Voir `'+description_key.lower()+'`.'
            pr[author_date]='2026-11-06T10:05:00Z'
        raw=json.dumps(payload).encode()
        signature='sha256='+hmac.new(secret.encode(),raw,hashlib.sha256).hexdigest()
        headers={'Content-Type':'application/json','X-Hub-Signature-256':signature,event_header:event}
        with urllib.request.urlopen(urllib.request.Request(BASE+'/webhooks/'+str(connections[provider]),data=raw,headers=headers)) as reply:
            check(reply.status==200,provider+' accepts signed PR delivery')
        _,description_panel,_=call('/tickets/'+tid+'/development')
        check((title in description_panel)==bool(index),provider+' only attaches the PR after adding the key in its description')
check(call('/projects/'+pid+'/update',{'_csrf':csrf,'repository_connection_id':bb},True)[0]==200,'project chooses Bitbucket repository as default')
status,panel,_=call('/tickets/'+tid+'/development')
_,branch_list,_=call('/list?project='+pid)
check('ticket-git-link' not in branch_list,'connected repository alone does not mark every ticket')
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
_,branch_list,_=call('/list?project='+pid)
git_cells=re.findall(r'<td data-column="git"[^>]*>(.*?)</td>',branch_list,re.S)
check(sum('ticket-git-link ticket-git-bitbucket' in cell for cell in git_cells)==1
      and 'ticket-git-link ticket-git-github' not in branch_list,'Git column identifies Bitbucket branch on its ticket only')
check('ticket-git-link' not in ''.join(re.findall(r'<td class="title-cell"[^>]*>(.*?)</td>',branch_list,re.S)),
      'summary no longer contains Git icons')
branch_href=re.search(r'class="ticket-git-link ticket-git-bitbucket" href="([^"]+)"',branch_list).group(1)
check(branch_href.endswith('#development') and '/tickets/'+tid in branch_href,'provider icon targets ticket development and retains list context')
gh_link=json.loads(call(path+'/link',{'_csrf':csrf,'connection_id':gh,'branch_name':'feature/sitemap-sans-cle'},True)[1])['id'];_,branch_list,_=call('/list?project='+pid)
check(branch_list.count('ticket-git-link ticket-git-github')==1 and branch_list.count('ticket-git-link ticket-git-bitbucket')==1,'list displays both provider icons without duplicate badges')
call(path+'/links/'+str(gh_link)+'/delete',{'_csrf':csrf},True)
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
_,branch_list,_=call('/list?project='+pid);check('ticket-git-link' not in branch_list,'unlinked branches no longer marked despite retained commit history')
_,panel,_=call(path)
check('Liens manuels de branches' not in panel and 'Correction sans clé' in panel,'unlink retains already received commit observations')
_,_,foreign_url=call('/projects',{'_csrf':csrf,'name':'Sans connexion '+suffix,'key':'BX'+suffix.upper()})
foreign_pid=urllib.parse.parse_qs(urllib.parse.urlsplit(foreign_url).query)['project'][0]
_,_,foreign_ticket=call('/tickets',{'_csrf':csrf,'project_id':foreign_pid,'title':'Hors dépôt'})
foreign_tid=urllib.parse.urlsplit(foreign_ticket).path.rsplit('/',1)[-1]
check(call('/tickets/'+foreign_tid+'/development/link',{'_csrf':csrf,'connection_id':gh,'branch_name':'main'},True)[0]==422,'shared credential cannot be used outside the ticket project repositories')

# Backfill a branch linked after its merge: exact source repo, PR commits and file statistics.
history_prs={};history_tickets={};history_links={}
for provider,cid in connections.items():
    _,_,history_url=call('/tickets',{'_csrf':csrf,'project_id':pid,'title':'Historique sitemap '+provider})
    history_tid=urllib.parse.urlsplit(history_url).path.rsplit('/',1)[-1];history_tickets[provider]=history_tid
    hp='/tickets/'+history_tid+'/development'
    history_link=json.loads(call(hp+'/link',{'_csrf':csrf,'connection_id':cid,'branch_name':'40908_flow_sitemap'},True)[1])['id'];history_links[provider]=history_link
    check(call(hp+'/links/'+str(history_link)+'/sync',{},True)[0]==403,provider+' history sync requires CSRF')
    status,body,_=call(hp+'/links/'+str(history_link)+'/sync',{'_csrf':csrf},True);history=json.loads(body)
    check(status==200 and len(history['pull_requests'])==1 and history['has_more'],provider+' merged PR recovered after branch link and homonymous fork PRs excluded')
    event=history['pull_requests'][0];history_prs[provider]=event
    check(call(hp+'/pull-requests/'+str(event)+'/files',json_accept=True)[0]==422,provider+' missing file summary stays unknown until retrieved')
    check(call(hp+'/pull-requests/'+str(event)+'/sync',{},True)[0]==403,provider+' PR detail sync requires CSRF')
    status,body,_=call(hp+'/pull-requests/'+str(event)+'/sync',{'_csrf':csrf},True);details=json.loads(body)
    check(status==200 and details['commits']==2 and details['files']==2 and details['commits_complete'],provider+' exact PR commits and complete paginated file statistics imported')
    summary=json.loads(call(hp+'/pull-requests/'+str(event)+'/files',json_accept=True)[1])
    check(summary['details']['additions']==580 and summary['details']['deletions']==185 and not summary['stale'],provider+' numeric totals match provider diffstat')
    check(summary['files'][0]['path']=='app/code/Example/Flow/Cron/Sitemap.php' and summary['files'][1]['previous_path']=='docs/old.md',provider+' file paths and renames retained without code')
    status,html,_=call(hp)
    check(status==200 and 'Fusionnée' in html and 'staging' in html and '40908 - fix URL flux XML' in html and 'Guillaume Exemple' in html,provider+' merged PR destination and commit authors shown in ticket')
    check('DO_NOT_STORE_CODE' not in html and 'DO_NOT_STORE_CODE' not in json.dumps(summary),provider+' code patches absent from saved summary and rendered ticket')
    check(call(hp+'/pull-requests/'+str(event)+'/sync',{'_csrf':csrf},True)[0]==200,provider+' repeated detail sync is idempotent')
    check(call('/tickets/'+foreign_tid+'/development/pull-requests/'+str(event)+'/files',json_accept=True)[0]==422,provider+' file details cannot cross ticket/project scope')
    check(call(hp+'/pull-requests/'+str(event)+'/files?page=0',json_accept=True)[0]==422,provider+' invalid summary page refused')
    more=json.loads(call(hp+'/links/'+str(history_link)+'/sync',{'_csrf':csrf,'page':2},True)[1])
    check(len(more['pull_requests'])==1 and not more['has_more'],provider+' older closed PRs remain available through pagination')
    if provider=='bitbucket':
        check(call(hp+'/pull-requests/'+str(more['pull_requests'][0])+'/sync',{'_csrf':csrf},True)[0]==422,'Bitbucket diffstat redirect to another host refused before sending credential')
        check(json.loads(call(hp+'/pull-requests/'+str(event)+'/files',json_accept=True)[1])['details']['additions']==580,'failed synchronization preserves earlier complete statistics')
    else:
        old_pr=str(more['pull_requests'][0]);partial=json.loads(call(hp+'/pull-requests/'+old_pr+'/sync',{'_csrf':csrf},True)[1])
        check(partial['commits']==250 and not partial['commits_complete'],'GitHub 250-commit API cap is explicit instead of claiming complete history')
        first=json.loads(call(hp+'/pull-requests/'+old_pr+'/files',json_accept=True)[1]);tail=json.loads(call(hp+'/pull-requests/'+old_pr+'/files?page=2',json_accept=True)[1])
        check(len(first['files'])==40 and first['has_more'] and len(tail['files'])==2 and not tail['has_more'],'stored file statistics paginate by forty without loss')
# Later webhook preserves the snapshot but marks its revision as stale.
pr={'id':1387,'title':'40908 - correction du sitemap','state':'MERGED','updated_on':'2026-11-02T13:00:00Z','source':{'branch':{'name':'40908_flow_sitemap'},'commit':{'hash':'c'*40},'repository':{'full_name':repositories['bitbucket']}},'destination':{'branch':{'name':'staging'},'commit':{'hash':'a'*40}}}
raw=json.dumps({'repository':{'full_name':repositories['bitbucket']},'pullrequest':pr}).encode();signature='sha256='+hmac.new(hook.encode(),raw,hashlib.sha256).hexdigest()
with urllib.request.urlopen(urllib.request.Request(BASE+'/webhooks/'+str(bb),data=raw,headers={'Content-Type':'application/json','X-Hub-Signature-256':signature,'X-Event-Key':'pullrequest:fulfilled'})) as reply:
    check(reply.status==200,'later merged PR webhook accepted')
hp='/tickets/'+history_tickets['bitbucket']+'/development';event=history_prs['bitbucket']
stale=json.loads(call(hp+'/pull-requests/'+str(event)+'/files',json_accept=True)[1])
check(stale['stale'] and stale['details']['additions']==580,'webhook keeps file statistics and explicitly marks old snapshot stale')
check(call(hp+'/pull-requests/'+str(event)+'/sync',{'_csrf':csrf},True)[0]==422,'older API snapshot cannot overwrite a newer webhook revision')
logs=call('/administration/logs?channel=development',json_accept=True)[1]
check('DO_NOT_STORE_CODE' not in logs and 'synthetic-git-token' not in logs and 'evil.example' not in logs,'technical logs contain no patches tokens or rejected redirect destination')

support.client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
viewer_csrf,_=get_token('/login');_,viewer_html,_=call('/login',{'_csrf':viewer_csrf,'email':'qa.viewer@example.com','password':'Tests-Sillage-2026!'})
viewer_csrf=token(viewer_html);_,viewer_panel,_=call(path)
check('id="ticket-branch-dialog"' not in viewer_panel,'read-only viewer receives no branch mutation dialog')
check(call('/connections/'+str(bb)+'/webhook',{'_csrf':viewer_csrf},True)[0]==403,'read-only viewer cannot reveal webhook secret')
for operation,args in [('branches',{}),('link',{'branch_name':'main'}),('links/'+str(link)+'/delete',{})]:
    check(call(path+'/'+operation,{'_csrf':viewer_csrf,'connection_id':bb,**args},True)[0]==403,'read-only viewer cannot '+operation)
check(call('/tickets/'+history_tickets['github']+'/development/pull-requests/'+str(history_prs['github'])+'/files',json_accept=True)[0]==200,'read-only viewer may consult stored file statistics')
check(call('/tickets/'+history_tickets['github']+'/development/pull-requests/'+str(history_prs['github'])+'/sync',{'_csrf':viewer_csrf},True)[0]==403,'read-only viewer cannot synchronize provider data')
support.client=admin
print('History UI inspection ticket '+history_tickets['github'])
print('Git UI inspection project '+pid+' ticket '+tid)
print('\n',passed_count(),'ticket branch HTTP checks passed (synthetic Git transport).')
