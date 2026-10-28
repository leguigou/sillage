"""MCP transport, token lifecycle, permissions and real business operations over HTTP."""
import json,re,uuid,urllib.request,urllib.error,os,subprocess,pathlib
from http_workflows_support import call,check,token,get_token,BASE,passed_count

csrf,_=get_token('/login')
status,html,_=call('/login',{'_csrf':csrf,'email':'qa.admin@example.com','password':'Tests-Sillage-2026!'})
check(status==200,'MCP owner signs in');csrf=token(html)

def issue(scopes,lifetime='unlimited',name='Client MCP de test'):
    status,html,_=call('/mcp/tokens',{'_csrf':csrf,'name':name,'scopes[]':scopes,'lifetime':lifetime,'duration':'5','unit':'minutes'})
    match=re.search(r'value="(slg_mcp_[a-f0-9]{64})"',html)
    check(status==200 and match is not None,'token created and displayed once')
    return match.group(1),re.search(r'data-mcp-token-id="(\d+)"',html).group(1)

def transport(payload,key=None,headers=None,method='POST'):
    data=json.dumps(payload,ensure_ascii=False).encode() if not isinstance(payload,bytes) else payload
    request_headers={'Content-Type':'application/json','Accept':'application/json, text/event-stream','MCP-Protocol-Version':'2025-11-25'}
    if key: request_headers['Authorization']='Bearer '+key
    request_headers.update(headers or {})
    try:
        with urllib.request.urlopen(urllib.request.Request(BASE+'/mcp',data=data if method=='POST' else None,headers=request_headers,method=method),timeout=20) as response:
            body=response.read().decode();return response.status,json.loads(body) if body else None
    except urllib.error.HTTPError as error:
        body=error.read().decode()
        try: result=json.loads(body)
        except ValueError: result={'text':body}
        return error.code,result

def rpc(method,params=None,key=None):
    return transport({'jsonrpc':'2.0','id':1,'method':method,'params':{} if params is None else params},key)

def tool(name,args=None,key=None,success=True):
    status,result=rpc('tools/call',{'name':name,'arguments':{} if args is None else args},key or full_key)
    check(status==200 and 'result' in result and result['result']['isError'] is not success,name+(' succeeded' if success else ' refused'))
    return result['result']['structuredContent']

check(call('/mcp/tokens',{'name':'No CSRF'})[0]==403,'token generation requires CSRF')
full_key,full_id=issue(['read','write','admin'])
check(full_key not in call('/settings')[1],'full token never displayed twice')
read_key,read_id=issue(['read'],'limited')
check(rpc('ping',key=full_key)[0]==200,'valid bearer header reaches PHP through Apache rewrite')
check(transport({'jsonrpc':'2.0','id':1,'method':'ping'},full_key,{'Authorization':'bearer '+full_key})[0]==200,'Bearer scheme is case insensitive')
check(rpc('ping')[0]==401,'MCP rejects browser session without bearer token')
check(rpc('ping',key='slg_mcp_'+'0'*64)[0]==401,'unknown bearer token rejected')
check(transport({},full_key,{'Origin':'https://untrusted.example'})[0]==403,'foreign Origin rejected')
check(transport({},full_key,{'Host':'untrusted.example'})[0]==403,'foreign Host rejected')
bad_version=transport({},full_key,{'MCP-Protocol-Version':'invalid'})
check(bad_version[0]==400,'unsupported version header rejected: '+str(bad_version))
check(transport({},full_key,{'Content-Type':'text/plain'})[0]==415,'content type validated')
check(transport({},full_key,{'Accept':'text/html'})[0]==406,'response type negotiated')
check(transport(b'{',full_key)[1]['error']['code']==-32700,'malformed JSON has JSON-RPC error')
check(transport([],full_key)[0]==400,'batch requests rejected')
check(transport({},full_key,method='GET')[0]==405,'optional SSE stream explicitly unsupported')
status,init=rpc('initialize',{'protocolVersion':'2025-11-25','clientInfo':{'name':'http-test','version':'1'},'capabilities':{}},full_key)
check(status==200 and init['result']['protocolVersion']=='2025-11-25','MCP initialize negotiated')
check(transport({'jsonrpc':'2.0','method':'notifications/initialized'},full_key)==(202,None),'initialized notification acknowledged')
status,catalog=rpc('tools/list',key=full_key); definitions=catalog['result']['tools']
check(len(definitions)>=35,'management tools discoverable')
status,readonly=rpc('tools/list',key=read_key)
check(all(t['annotations']['readOnlyHint'] for t in readonly['result']['tools']),'read-only key sees read tools only')
status,denied=rpc('tools/call',{'name':'projects_create','arguments':{'name':'Forbidden','key':'BAD'}},read_key)
check(status==200 and 'error' in denied,'read-only key cannot invoke hidden admin tool')
status,invalid=rpc('tools/call',{'name':'tickets_get','arguments':{'id':'1','sql':'SELECT 1'}},full_key)
check(status==200 and 'error' in invalid,'schema rejects wrong types and unknown fields')
marker=uuid.uuid4().hex[:7].upper();p=tool('projects_create',{'name':'MCP '+marker,'key':'M'+marker})
pid=p['id'];tool('projects_update',{'id':pid,'description':'Gestion depuis MCP'})
check(call('/automation?project='+str(pid))[0]==200,'automation editor rendered')
status,web_html,_=call('/automation',{'_csrf':csrf,'project_id':pid,'name':'Règle web '+marker,'event':'ticket.created','condition_type':'story','action_priority':'low','action_estimated_hours':'4,5','enabled':'1'})
web_rule_id=re.search(r'action="/automation/(\d+)/toggle"',web_html)
check(status==200 and web_rule_id is not None,'automation created through web editor')
web_rule_id=int(web_rule_id.group(1))
web_ticket=tool('tickets_create',{'project_id':pid,'fields':{'title':'Depuis une règle web','type':'story'}})
check(web_ticket['priority']=='low' and web_ticket['estimated_hours']=='4.50','web-created automation applies to MCP ticket')
check('value="4.5"' in call('/automation?project='+str(pid)+'&edit='+str(web_rule_id))[1],'automation editor loads canonical saved values')
status,web_html,_=call('/automation/'+str(web_rule_id)+'/toggle',{'_csrf':csrf,'version':'1'})
check(status==200 and 'En pause' in web_html,'automation suspended through web editor')
check(call('/automation/'+str(web_rule_id)+'/delete',{'_csrf':csrf,'version':'2'})[0]==200,'automation removed through web editor')

page=tool('projects_list',{'limit':1});check(len(page['projects'])==1 and page['total']>=1,'project pagination exposes complete total')

rule=tool('automation_save',{'project_id':pid,'name':'Bugs prioritaires','event':'ticket.created','conditions':{'type':'bug'},'actions':{'priority':'high','estimated_hours':'1,50','comment':'Commentaire automatique'}})
t=tool('tickets_create',{'project_id':pid,'fields':{'title':'Ticket '+marker,'type':'bug','progress_percent':35}})
check(t['priority']=='high' and t['estimated_hours']=='1.50','MCP creation applies real automation')
check(t['progress_percent']==35,'MCP creation stores ticket progress')
comments=tool('tickets_comments_list',{'id':t['id']});check(comments['total']==1,'automation comment stored')
tool('tickets_update',{'id':t['id'],'version':1,'fields':{'title':'Obsolete'}},success=False)
t=tool('tickets_update',{'id':t['id'],'version':t['version'],'fields':{'status':'progress','progress_percent':75}})
check(t['progress_percent']==75,'MCP update persists progress')
t=tool('tickets_pause',{'id':t['id'],'version':t['version'],'paused':True})
check(t['status']=='paused' and t['paused_from_status']=='progress','MCP pause records previous state')
paused_page=tool('tickets_list',{'project_id':pid,'filters':{'pause':'only'}})
check(any(row['id']==t['id'] for row in paused_page['tickets']),'MCP explicit pause filter finds paused ticket')
active_page=tool('tickets_list',{'project_id':pid})
check(all(row['id']!=t['id'] for row in active_page['tickets']),'MCP default search hides paused tickets')
t=tool('tickets_pause',{'id':t['id'],'version':t['version'],'paused':False})
check(t['status']=='progress' and t['progress_percent']==75,'MCP resume preserves advancement')

tool('tickets_comment',{'id':t['id'],'body':'Depuis un assistant'})
status,invalid_progress=rpc('tools/call',{'name':'tickets_update','arguments':{'id':t['id'],'version':t['version'],'fields':{'progress_percent':101}}},full_key)
check(status==200 and invalid_progress.get('error',{}).get('code')==-32602,'MCP schema rejects out of range progress')
second=tool('tickets_create',{'project_id':pid,'fields':{'title':'Dépendance'}})
tool('tickets_link',{'id':t['id'],'target_id':second['id'],'relation':'blocks'})
tool('tickets_link',{'id':second['id'],'target_id':t['id'],'relation':'blocks'},success=False)
links=tool('tickets_links_list',{'id':t['id']});tool('tickets_unlink',{'id':links['links'][0]['id']})
tool('tickets_bulk_update',{'tickets':[{'id':t['id'],'version':t['version']},{'id':second['id'],'version':9999}],'fields':{'flagged':True}},success=False)
check(tool('tickets_get',{'id':t['id']})['flagged']==0,'failed bulk modification rolls back earlier ticket')
f=tool('forms_save',{'project_id':pid,'title':'Formulaire '+marker,'fields':['title','description'],'required':['description']})
check('public_url' in f and 'token_hash' not in f,'form link delivered without internal hash')
renamed=tool('forms_save',{'id':f['id'],'project_id':pid,'title':'Formulaire renommé '+marker})
check(len(renamed['fields'])==2 and renamed['fields'][1]['required'] and 'public_url' not in renamed,'partial form update preserves omitted fields and does not reveal link')
check(call(f['public_url'].replace(BASE,''))[0]==200,'new MCP form link is usable')
tool('forms_get',{'id':f['id']});tool('forms_list',{'project_id':pid,'limit':1})

tool('forms_set_enabled',{'id':f['id'],'enabled':False})
check(call(f['public_url'].replace(BASE,''))[0]==404,'disabled form refuses public access')
rotated=tool('forms_rotate_link',{'id':f['id']})
tool('forms_set_enabled',{'id':f['id'],'enabled':True})
check(call(rotated['public_url'].replace(BASE,''))[0]==200 and call(f['public_url'].replace(BASE,''))[0]==404,'rotated form link replaces old link')
tool('forms_delete',{'id':f['id']})
color=tool('formatting_create',{'project_id':pid,'title':'Urgent','field':'priority','value':'high','color':'#ff5555'})
tool('formatting_delete',{'id':color['id']})
team=tool('teams_save',{'name':'MCP '+marker});members=tool('members_list')['members']
tool('teams_membership',{'team_id':team['id'],'user_id':members[0]['id'],'add':True})
check(tool('teams_members_list',{'id':team['id']})['total']==1,'team member pagination')
tool('teams_list',{'limit':1})
tool('teams_project_membership',{'team_id':team['id'],'project_id':pid,'add':True})
check(tool('teams_projects_list',{'id':team['id']})['total']==1,'MCP shared team project catalog')
check(tool('teams_list',{'project_id':pid})['teams'][0]['id']==team['id'],'MCP teams filter by project')
t=tool('tickets_get',{'id':t['id']})
t=tool('tickets_update',{'id':t['id'],'version':t['version'],'fields':{'team_id':team['id']}})
check(tool('tickets_list',{'project_id':pid,'filters':{'team':team['id']}})['total']==1,'MCP team ticket assignment and filtering')
check(tool('reports_summary',{'project_id':pid,'filters':{'team':team['id']}})['teams'][0]['total']==1,'MCP team workload')
tool('teams_project_membership',{'team_id':team['id'],'project_id':pid,'add':False},success=False)
t=tool('tickets_update',{'id':t['id'],'version':t['version'],'fields':{'team_id':None}})
tool('teams_project_membership',{'team_id':team['id'],'project_id':pid,'add':False})

tool('teams_membership',{'team_id':team['id'],'user_id':members[0]['id'],'add':False})
tool('teams_delete',{'id':team['id']})
tool('automation_get',{'id':rule['id']});tool('automation_list',{'project_id':pid})
tool('automation_set_enabled',{'id':rule['id'],'version':rule['version'],'enabled':False})
check(tool('automation_runs',{'project_id':pid})['total']==2,'automation execution logged through MCP')

summary=tool('reports_summary',{'project_id':pid});check(summary['total']==3,'MCP dashboard statistics use all project tickets')
email_policy=tool('project_notifications_get',{'project_id':pid})
check(email_policy['enabled']==False,'MCP reports email policy disabled before setup')
email_policy=tool('project_notifications_save',{'project_id':pid,'version':0,'enabled':True,'rules':{'created':['assignee']}})
check(email_policy['enabled']==1 and email_policy['rules']['created']==['assignee'],'MCP saves project event recipients')
tool('project_notifications_save',{'project_id':pid,'version':0,'enabled':False,'rules':{}},success=False)
tool('project_notifications_save',{'project_id':pid,'version':1,'enabled':False,'rules':{}})
saved=tool('views_save',{'project_id':pid,'name':'Vue MCP','filters':{'q':marker}})
check(any(v['id']==saved['id'] for v in tool('views_list',{'project_id':pid})['views']),'MCP personal view persisted')
tool('views_delete',{'id':saved['id']})
tool('tickets_attachments',{'id':t['id']});tool('development_list',{'project_id':pid})
accounts=tool('users_list',{'limit':1});check('password_hash' not in json.dumps(accounts),'MCP account list excludes credential hashes')
tool('projects_delete_empty',{'id':pid},success=False)
empty=tool('projects_create',{'name':'Empty '+marker,'key':'E'+marker});tool('projects_delete_empty',{'id':empty['id']})
t=tool('tickets_archive',{'id':t['id'],'version':t['version'],'archived':True})
tool('tickets_archive',{'id':t['id'],'version':t['version'],'archived':False})
status,settings,_=call('/settings');row=re.search(r'<tr data-mcp-token-id="'+full_id+r'">(.*?)</tr>',settings,re.S)
check(row is not None and 'Jamais' not in row.group(1),'last token access visible in settings')
check('projects_create' in settings and full_key not in settings,'audit names shown without token secret')
sdk_python=os.environ.get('SILLAGE_MCP_PYTHON')
if not sdk_python:
    candidate=pathlib.Path(__file__).resolve().parents[1]/'.runtime'/'mcp-sdk'/'Scripts'/'python.exe'
    if candidate.exists():sdk_python=str(candidate)
if sdk_python:
    subprocess.run([sdk_python,str(pathlib.Path(__file__).with_name('mcp_sdk.py'))],env=dict(os.environ,SILLAGE_MCP_TEST_KEY=full_key),check=True)
    check(True,'official Python MCP SDK interoperability and JSON Schemas')
else: print('SDK probe not run: set SILLAGE_MCP_PYTHON to the dedicated interpreter.')
check(call('/mcp/tokens/'+read_id+'/delete',{'_csrf':csrf})[0]==200,'token revoked through settings')
check(rpc('ping',key=read_key)[0]==401,'revoked token rejected immediately')
check(call('/mcp/tokens/'+full_id+'/delete',{'_csrf':csrf})[0]==200,'admin test token removed')
print('\n',passed_count(),'MCP HTTP checks passed.')
