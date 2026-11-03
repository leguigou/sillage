"""User/project filter memory across routes, logout and session replacement."""
import json,uuid,urllib.parse,http.cookiejar,urllib.request
import http_workflows_support as s
from http_workflows_support import call,get_token,token,check
csrf,_=get_token('/login');_,html,_=call('/login',{'_csrf':csrf,'email':'qa.admin@example.com','password':'Tests-Sillage-2026!'});csrf=token(html)
suffix=uuid.uuid4().hex[:6]
_,_,url=call('/projects',{'_csrf':csrf,'name':'Filtres '+suffix,'key':'FM'+suffix.upper()});pid=urllib.parse.parse_qs(urllib.parse.urlsplit(url).query)['project'][0]
query='critere-'+suffix
_,body,_=call('/list?project='+pid+'&q='+query)
check('value="'+query+'"' in body,'explicit filter rendered')
for route in ['/board','/timeline','/list']:
 _,body,_=call(route+'?project='+pid);check('value="'+query+'"' in body,'filter survives navigation to '+route)
jar=next(h.cookiejar for h in s.client.handlers if isinstance(h,urllib.request.HTTPCookieProcessor))
cookies=list(jar);prefs=[c for c in cookies if '_filters_' in c.name]
check(len(prefs)>0 and query not in prefs[0].value,'persistent filter cookie excludes readable search text')
for c in cookies:
 if '_filters_' not in c.name: jar.clear(c.domain,c.path,c.name)
csrf,_=get_token('/login');_,html,_=call('/login',{'_csrf':csrf,'email':'qa.admin@example.com','password':'Tests-Sillage-2026!'})
_,body,_=call('/list?project='+pid);check('value="'+query+'"' in body,'encrypted cookie restores filter after a new login session')
_,body,_=call('/list?project='+pid+'&filters_reset=1');check('value="'+query+'"' not in body,'reset clears active criteria')
_,body,_=call('/board?project='+pid);check('value="'+query+'"' not in body,'reset survives next navigation')
print('Filter memory UI project '+pid)
csrf=token(body);progress_tickets={}
for progress in [50,100,0]:
 _,html,url=call('/tickets',{'_csrf':csrf,'project_id':pid,'title':'Avancement '+str(progress),'progress_percent':str(progress)});progress_tickets[progress]=urllib.parse.urlsplit(url).path.rsplit('/',1)[-1]
for direction,values in [('asc',[0,50,100]),('desc',[100,50,0])]:
 _,html,_=call('/list?project='+pid+'&sort=progress_percent&direction='+direction)
 positions=[html.index('data-ticket-id="'+progress_tickets[v]+'"') for v in values]
 check(positions==sorted(positions),'numeric progress sort '+direction+' includes zero and completed')
 check('Trier par avancement croissant' in html and 'Trier par avancement décroissant' in html,'progress arrow links available beside summary')
 _,remembered,_=call('/list?project='+pid);check('sort=progress_percent' in remembered and 'aria-current="true"' in remembered,'progress sort retained during navigation '+direction)
