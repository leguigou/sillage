"""Pausing is distinct from blocking and has an explicit, reversible work-list visibility policy."""
http_support.client=admin_client
pause_csrf,pause_page=get_token('/settings')
pause_marker='Pause-'+uuid.uuid4().hex[:8]
_,pause_page,pause_project_url=call('/projects',{'_csrf':pause_csrf,'name':pause_marker,'key':'PS'+uuid.uuid4().hex[:6].upper()})
pause_pid=urllib.parse.parse_qs(urllib.parse.urlsplit(pause_project_url).query)['project'][0]
status,pause_page,pause_url=call('/tickets',{'_csrf':token(pause_page),'project_id':pause_pid,'title':pause_marker,'description':'Demande à différer','status':'progress','flagged':'1','estimated_hours':'6.25','progress_percent':'42','start_date':'2026-11-02','due_date':'2026-11-06'})
pause_id=urllib.parse.urlsplit(pause_url).path.rsplit('/',1)[-1]
check(status==200 and 'blocked-badge' in pause_page and 'Mettre en pause' in pause_page,'blocked ticket shows explicit badge and pause action')
for view in ['/list','/board','/timeline']:
    _,view_html,_=call(view+'?project='+pause_pid)
    check(pause_marker in view_html and 'blocked-badge' in view_html,'blocked indicator visible in '+view)
ver=re.search(r'name="version" value="(\d+)"',pause_page).group(1)
check(call('/tickets/'+pause_id+'/pause',{'version':ver,'action':'pause'},True)[0]==403,'pause requires CSRF')
status,pause_page,_=call('/tickets/'+pause_id+'/pause',{'_csrf':token(pause_page),'version':ver,'action':'pause'})
check(status==200 and 'Reprendre le ticket' in pause_page and 'Mis de côté pour plus tard' in pause_page,'ticket pause is visible on its own detail page')
check(call('/tickets/'+pause_id+'/pause',{'_csrf':token(pause_page),'version':ver,'action':'resume'},True)[0]==422,'stale resume refused by HTTP')
for view in ['/list','/board','/timeline','/all']:
    _,default_page,_=call(view+'?project='+pause_pid)
    check('data-ticket-id="'+pause_id+'"' not in default_page,'paused ticket hidden by default in '+view)
    _,only_page,_=call(view+'?project='+pause_pid+'&pause=only')
    check('data-ticket-id="'+pause_id+'"' in only_page,'pause filter recovers ticket in '+view)
_,pause_json,_=call('/export?project='+pause_pid+'&format=json')
check(json.loads(pause_json)==[],'default export follows active-work visibility')
_,pause_json,_=call('/export?project='+pause_pid+'&pause=only&format=json')
pause_export=json.loads(pause_json)[0]
check(pause_export['status']=='paused' and pause_export['progress_percent']==42 and pause_export['estimated_hours']=='6.25','explicit pause export preserves ticket fields')
_,pause_page,_=call('/tickets/'+pause_id);ver=re.search(r'name="version" value="(\d+)"',pause_page).group(1)
http_support.client=viewer_client
_,viewer_page,_=call('/tickets/'+pause_id)
check('Mettre en pause' not in viewer_page and 'Reprendre le ticket' not in viewer_page,'read-only account has no pause control')
check(call('/tickets/'+pause_id+'/pause',{'_csrf':token(viewer_page),'version':ver,'action':'resume'},True)[0]==403,'read-only account cannot resume a ticket')
http_support.client=admin_client
status,pause_page,_=call('/tickets/'+pause_id+'/pause',{'_csrf':token(pause_page),'version':ver,'action':'resume'})
_,pause_json,_=call('/export?project='+pause_pid+'&format=json')
restored=json.loads(pause_json)[0]
check(status==200 and restored['status']=='progress' and restored['progress_percent']==42 and restored['estimated_hours']=='6.25','resume restores previous state visibility and estimates')
