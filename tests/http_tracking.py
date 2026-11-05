"""Time, audit, review policy and manual deliveries through authenticated Apache."""
http_support.client=admin_client
tr_csrf,tr_html=get_token('/settings')
_,tr_html,tr_url=call('/projects',{'_csrf':tr_csrf,'name':'Livraisons HTTP '+uuid.uuid4().hex[:5],'key':'TR'+uuid.uuid4().hex[:6].upper()})
tr_pid=urllib.parse.parse_qs(urllib.parse.urlsplit(tr_url).query)['project'][0]
status,tr_body,_=call('/releases',{'_csrf':tr_csrf,'project_id':tr_pid,'name':'Version HTTP','description':'Livraison contrôlée'},True)
check(status==200,'release created through AJAX endpoint');tr_release=json.loads(tr_body)['release']
check(call('/releases',{'project_id':tr_pid,'name':'No CSRF'},True)[0]==403,'release creation requires CSRF')
status,tr_html,tr_url=call('/tickets',{'_csrf':tr_csrf,'project_id':tr_pid,'title':'Validation du parcours','estimated_hours':'10','progress_percent':'60','release_id':tr_release['id']})
tr_tid=urllib.parse.urlsplit(tr_url).path.rsplit('/',1)[-1];tr_version=int(re.search(r'name="version" value="(\d+)"',tr_html).group(1))
check(status==200 and 'data-ticket-tab="time"' in tr_html and 'data-ticket-tab="history"' in tr_html,'time and history in dedicated ticket tabs')
tr_history_html=re.search(r'<section id="history".*?</section>',tr_html,re.S).group(0)
check('Aucune modification enregistrée pour ce ticket.' in tr_history_html and 'data-history-event' not in tr_history_html,'new ticket history omits creation and shows empty modification feed')
check('Version HTTP' in tr_html and '4 h' in tr_html,'target version and remaining effort visible')
status,tr_body,_=call('/tickets/'+tr_tid+'/time',{'_csrf':tr_csrf,'version':tr_version,'hours':'2,5','worked_on':'2026-01-01','note':'Contrôle fonctionnel'},True)
tr_time=json.loads(tr_body);tr_version=tr_time['ticket']['version'];tr_entry=tr_time['entry']['id']
check(status==200 and tr_time['ticket']['spent_hours']=='2.50' and tr_time['ticket']['remaining_hours']=='4.00','time saved without redirect and independent of remaining effort')
check(call('/tickets/'+tr_tid+'/time',{'_csrf':tr_csrf,'version':tr_version-1,'hours':'1','worked_on':'2026-01-01'},True)[0]==422,'stale time save refused')
_,tr_body,_=call('/tickets/'+tr_tid+'/time',{'_csrf':tr_csrf,'version':tr_version,'entry_id':tr_entry,'hours':'3','worked_on':'2026-01-01','note':'Correction'},True);tr_time=json.loads(tr_body);tr_version=tr_time['ticket']['version']
check(tr_time['ticket']['spent_hours']=='3.00','existing worklog corrected through HTTP')
_,tr_body,_=call('/tickets/'+tr_tid+'/time',{'_csrf':tr_csrf,'version':tr_version,'entry_id':tr_entry,'action':'delete'},True);tr_time=json.loads(tr_body);tr_version=tr_time['ticket']['version']
check(float(tr_time['ticket']['spent_hours'])==0,'time deletion updates total')
_,tr_html,_=call('/tickets/'+tr_tid)
check('Temps supprimé' in tr_html and '3 h' in tr_html,'audit retains removed worklog values')
tr_history_html=re.search(r'<section id="history".*?</section>',tr_html,re.S).group(0)
check('history-timeline-row' in tr_history_html and 'avatar small' in tr_history_html and 'data-history-day' in tr_history_html and 'history-before' in tr_history_html and 'data-history-action="created"' not in tr_history_html,'timeline shows author avatar day groups and before/after without creation')
check(call('/projects/'+tr_pid+'/update',{'_csrf':tr_csrf,'require_ticket_validation':'1'},True)[0]==200,'project can require validation')
check(call('/tickets/'+tr_tid+'/quick',{'_csrf':tr_csrf,'version':tr_version,'field':'status','value':'done'},True)[0]==422,'quick closure respects required validation')
status,tr_body,_=call('/tickets/'+tr_tid+'/validation',{'_csrf':tr_csrf,'version':tr_version,'decision':'approved','note':'Contrôle effectué'},True);tr_ticket=json.loads(tr_body)['ticket'];tr_version=tr_ticket['version']
check(status==200 and tr_ticket['validation_state']=='approved','review decision saved through AJAX')
status,tr_body,_=call('/tickets/'+tr_tid+'/quick',{'_csrf':tr_csrf,'version':tr_version,'field':'status','value':'done'},True);tr_ticket=json.loads(tr_body)['ticket'];tr_version=tr_ticket['version']
check(status==200 and tr_ticket['resolution']=='completed','approved ticket closes with compatible default resolution')
_,tr_body,_=call('/tickets/'+tr_tid+'/quick',{'_csrf':tr_csrf,'version':tr_version,'field':'status','value':'progress'},True);tr_ticket=json.loads(tr_body)['ticket'];tr_version=tr_ticket['version']
check(tr_ticket['resolution'] is None and tr_ticket['validation_state']=='pending','reopening clears obsolete closure data')
status,tr_body,_=call('/releases/'+str(tr_release['id'])+'/deliveries',{'_csrf':tr_csrf,'version':tr_release['version'],'environment':'staging','outcome':'success','reference':'v1.0.0-rc1'},True);tr_release=json.loads(tr_body)['release']
check(status==200 and tr_release['delivery_state']['production'] is None,'staging declaration does not imply production')
_,tr_page,_=call('/releases?project='+tr_pid+'&release='+str(tr_release['id']))
check('Validation du parcours' in tr_page and 'v1.0.0-rc1' in tr_page,'release page shows associated tickets and deliveries')
_,tr_export,_=call('/export?project='+tr_pid+'&format=json');tr_export=json.loads(tr_export)
check(tr_export[0]['remaining_hours']=='4.00' and 'spent_hours' in tr_export[0],'exports include actual and remaining time')
http_support.client=viewer_client;_,tr_viewer,_=call('/tickets/'+tr_tid);tr_vcsrf=token(tr_viewer)
for tr_path,tr_data in [('/tickets/'+tr_tid+'/time',{'version':tr_version,'hours':'1','worked_on':'2026-01-01'}),('/tickets/'+tr_tid+'/validation',{'version':tr_version,'decision':'approved'}),('/releases',{'project_id':tr_pid,'name':'Interdite'}),('/releases/'+str(tr_release['id'])+'/deliveries',{'version':tr_release['version'],'environment':'production','outcome':'success','reference':'v1'})]:
    check(call(tr_path,dict(tr_data,_csrf=tr_vcsrf),True)[0]==403,'read-only account denied '+tr_path.rsplit('/',1)[-1])
check('data-ticket-tab="history"' in tr_viewer and 'data-tracking-form' not in tr_viewer,'viewer reads history without edit controls')
http_support.client=admin_client
