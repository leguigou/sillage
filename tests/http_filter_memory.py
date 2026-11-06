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
 progress_header=html.split('<th data-column="progress_percent"',1)[1].split('</th>',1)[0]
 check('aria-sort="'+('ascending' if direction=='asc' else 'descending')+'"' in progress_header
       and 'direction='+('desc' if direction=='asc' else 'asc') in progress_header
       and 'data-list-label-options' not in progress_header,'dedicated progress column sorts without a settings button')
 check('<summary>Avancé</summary>' in html and 'data-list-progress-mode' in html,
       'advanced view options expose progress display settings')
 check('<td data-column="progress_percent"' in html and 'data-progress-estimate' in html,'progress and total estimate are rendered in their own column')
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

status,_,review_url=call('/tickets',{'_csrf':csrf,'project_id':pid,'title':'Ticket en revue','status':'review'})
review_id=urllib.parse.urlsplit(review_url).path.rsplit('/',1)[-1]
status,_,done_url=call('/tickets',{'_csrf':csrf,'project_id':pid,'title':'Ticket terminé','status':'done'})
done_id=urllib.parse.urlsplit(done_url).path.rsplit('/',1)[-1]
status_query=urllib.parse.urlencode({'project':pid,'status[]':['','todo','review']},doseq=True)
_,status_html,_=call('/list?'+status_query)
check('data-ticket-id="'+review_id+'"' in status_html and 'data-ticket-id="'+progress_tickets[0]+'"' in status_html and 'data-ticket-id="'+done_id+'"' not in status_html,'multiple checked states restrict ticket list')
_,board_html,_=call('/board?project='+pid)
check('data-drop-status="todo"' in board_html and 'data-drop-status="review"' in board_html and 'data-drop-status="done"' not in board_html,'board remembers state selection and hides unselected columns')
_,empty_html,_=call('/list?project='+pid+'&status[]=')
check('data-ticket-id="'+review_id+'"' not in empty_html and '0 ticket' in empty_html,'all states unchecked yields an empty view')
saved_row=json.loads(saved)
report_data.update({'sort3':'title','name':'Rapport en revue','view_id':saved_row['id'],'revision':saved_row['revision'],'status[]':['','review']})
status,updated,_=call('/views',report_data,True)
check(status==200,'edit report persists state checkboxes')
_,filtered_report,_=call(json.loads(updated)['url'])
check('data-ticket-id="'+review_id+'"' in filtered_report and 'data-ticket-id="'+progress_tickets[0]+'"' not in filtered_report and 'name="status[]" value="review" checked' in filtered_report,'reopened report restores checked states and matching tickets')

# The active report survives navigation and is reapplied from its saved settings.
report_id = json.loads(updated)['id']
call('/board?project='+pid+'&status=done')
_, returned, _ = call('/list?project='+pid)
check('data-report-edit="'+str(report_id)+'"' in returned
      and 'data-ticket-id="'+review_id+'"' in returned
      and 'data-ticket-id="'+done_id+'"' not in returned,
      'returning from another view restores the selected report and its filters')
call('/list?project='+pid+'&filters_reset=1')
_, reset_list, _ = call('/list?project='+pid)
check('data-report-edit="'+str(report_id)+'"' not in reset_list
      and 'data-ticket-id="'+done_id+'"' in reset_list,
      'reset clears the remembered report and its filters')
