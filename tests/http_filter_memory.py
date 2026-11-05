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
 _,html,url=call('/tickets',{'_csrf':csrf,'project_id':pid,'title':'Avancement '+str(progress),'progress_percent':str(progress),'start_date':{100:'2026-11-01',50:'2026-11-05',0:''}[progress],'due_date':'2026-11-15'});progress_tickets[progress]=urllib.parse.urlsplit(url).path.rsplit('/',1)[-1]
for direction,values in [('asc',[0,50,100]),('desc',[100,50,0])]:
 _,html,_=call('/list?project='+pid+'&sort=progress_percent&direction='+direction)
 positions=[html.index('data-ticket-id="'+progress_tickets[v]+'"') for v in values]
 check('data-column="updated"' in html and 'data-ticket-modified datetime="' in html,'list includes sortable modification date with explicit UTC timestamp')
 check(positions==sorted(positions),'numeric progress sort '+direction+' includes zero and completed')
 check(('Trier par avancement décroissant' if direction=='asc' else 'Trier par avancement croissant') in html and html.count('class="list-progress-sort"')==1,'single progress arrow toggles current order')
 _,remembered,_=call('/list?project='+pid);check('sort=progress_percent' in remembered and 'aria-current="true"' in remembered,'progress sort retained during navigation '+direction)
report_data={'_csrf':token(html),'project_id':pid,'name':'Trois tris','settings':'{}','report_options':'1','sort':'priority','direction':'asc','sort2':'progress_percent','direction2':'desc','sort3':'title','direction3':'asc','group':'','columns[]':['title','priority'],'column_order':json.dumps(['title','priority'])}
status,saved,_=call('/views',report_data,True);check(status==200,'report form saves three sort levels with independent directions')
view=json.loads(saved)['id'];_,report_html,_=call('/list?project='+pid+'&view='+str(view))
positions=[report_html.index('data-ticket-id="'+progress_tickets[v]+'"') for v in [100,50,0]]
check(positions==sorted(positions) and 'name="sort2"' in report_html and 'name="sort3"' in report_html,'saved report applies secondary order and renders all three selectors')
report_data['sort3']='priority';check(call('/views',report_data,True)[0]==422,'duplicate report sort refused through form endpoint')
_,date_html,_=call('/list?project='+pid+'&columns[]=title&columns[]=start&columns[]=due&sort=start&direction=desc')
check('Date de début' in date_html and 'data-column="start"' in date_html and '05/11/2026' in date_html and '15/11/2026' in date_html,'start and due dates available together in list and report picker')
date_positions=[date_html.index('data-ticket-id="'+progress_tickets[v]+'"') for v in [50,100,0]]
check(date_positions==sorted(date_positions),'start date descending sort uses date values with missing dates last')
check('datetime="2026-11-01"' in date_html and '<span class="muted">—</span>' in date_html,'date-only timestamps preserved and missing dates shown as dash')
