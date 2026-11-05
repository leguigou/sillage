"""Assignments through actual HTTP, with CSRF, version and viewer boundaries."""
import re,json,uuid,urllib.parse,urllib.request,http.cookiejar
import http_workflows_support as support
from http_workflows_support import call,get_token,token,check,passed_count
csrf,_=get_token('/login');_,html,_=call('/login',{'_csrf':csrf,'email':'qa.admin@example.com','password':'Tests-Sillage-2026!'});csrf=token(html);admin=support.client
suffix=uuid.uuid4().hex[:6];_,_,url=call('/projects',{'_csrf':csrf,'name':'Affectations '+suffix,'key':'PE'+suffix.upper()});pid=urllib.parse.parse_qs(urllib.parse.urlsplit(url).query)['project'][0]
_,_,url=call('/tickets',{'_csrf':csrf,'project_id':pid,'title':'Travail à plusieurs'});tid=urllib.parse.urlsplit(url).path.rsplit('/',1)[-1];endpoint='/tickets/'+tid+'/people'
status,body,_=call(endpoint,json_accept=True);data=json.loads(body);check(status==200 and 'Collaborateurs assignés' in data['html'],'assignment modal loads active selectable people')
ids=re.findall(r'name="collaborators\[\]" value="(\d+)"',data['html']);check(len(ids)>=2,'two real installation members offered')
version=data['version'];payload={'_csrf':csrf,'version':version,'assignee_id':ids[0],'team_id':'','collaborators[]':[ids[0],ids[1]]}
check(call(endpoint,{'version':version},True)[0]==403,'assignment save requires CSRF')
status,body,_=call(endpoint,payload,True);saved=json.loads(body);check(status==200 and saved['version']==version+1,'assignment save changes ticket version')
check(saved['button'].count('class="avatar ')==2,'responsible and collaborator rendered once each on card')
_,board,_=call('/board?project='+pid);check('data-ticket-people="'+tid+'"' in board and board.count('class="avatar small"')>=2,'board reload displays saved assignments')
check(board.index('class="column-create"')<board.index('class="column-cards"'),'create ticket button precedes cards')
check(call(endpoint,payload,True)[0]==422,'stale assignment save refused')
payload['version']=saved['version'];payload['collaborators[]']=['999999999'];check(call(endpoint,payload,True)[0]==422,'unknown collaborator refused')
payload['collaborators[]']=[ids[1]];status,body,_=call(endpoint,payload,True);check(status==200 and json.loads(body)['version']==saved['version'],'unchanged assignment save is idempotent')
payload.pop('collaborators[]');status,body,_=call(endpoint,payload,True);check(status==200 and json.loads(body)['button'].count('class="avatar ')==1,'remove collaborators keeps principal responsible')
support.client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()));vcsrf,_=get_token('/login');_,html,_=call('/login',{'_csrf':vcsrf,'email':'qa.viewer@example.com','password':'Tests-Sillage-2026!'});vcsrf=token(html)
status,body,_=call(endpoint,json_accept=True);check(status==200 and 'Consultation uniquement' in json.loads(body)['html'],'viewer may consult assignments')
check(call(endpoint,{'_csrf':vcsrf,'version':json.loads(body)['version'],'collaborators[]':[ids[1]]},True)[0]==403,'viewer cannot change assignments')
support.client=admin
print('People UI project '+pid+' ticket '+tid)
print(str(passed_count())+' ticket people HTTP checks passed')
