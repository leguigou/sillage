"""Project vocabulary and personal notifications through real authenticated HTTP routes."""
http_support.client=admin_client
wf_csrf,wf_page=get_token('/settings')
_,wf_page,wf_url=call('/projects',{'_csrf':wf_csrf,'name':'Circuit HTTP '+uuid.uuid4().hex[:5],'key':'WF'+uuid.uuid4().hex[:6].upper()})
wf_pid=urllib.parse.parse_qs(urllib.parse.urlsplit(wf_url).query)['project'][0]
wf_path='/projects/'+wf_pid+'/workflow'
def workflow_form(page):
    region=re.search(r'<section[^>]+id="workflow"[\s\S]*?</section>',page).group()
    version=re.search(r'name="version" value="(\d+)"',region).group(1)
    return {'_csrf':token(page),'version':version}
_,wf_page,_=call('/settings?project='+wf_pid)
check('Circuit du projet' in wf_page,'project workflow configuration visible to administrator')
wf_input=dict(workflow_form(wf_page),action='add_item',kind='status',label='Attente HTTP',category='review',icon='clock',color='#0891b2',position='25')
check(call(wf_path,{},True)[0]==403,'workflow change requires CSRF')
status,wf_page,_=call(wf_path,wf_input,True)
check(status==200 and 'Attente HTTP' in wf_page,'custom status saved via settings AJAX response')
check(call(wf_path,wf_input,True)[0]==422,'stale workflow settings refused over HTTP')
wf_status=re.search(r'name="code" value="(s_[a-f0-9]+)"',wf_page).group(1)
wf_input=dict(workflow_form(wf_page),action='add_item',kind='type',label='Incident HTTP',category='bug',icon='bug',color='#dc2626',position='5')
_,wf_page,_=call(wf_path,wf_input)
wf_type=re.search(r'name="code" value="(t_[a-f0-9]+)"',wf_page).group(1)
wf_policy=dict(workflow_form(wf_page),action='transitions',restricted='1',**{'transitions[todo][]':[wf_status],'transitions['+wf_status+'][]':['done']})
_,wf_page,_=call(wf_path,wf_policy)
_,wf_ticket,wf_ticket_url=call('/tickets',{'_csrf':token(wf_page),'project_id':wf_pid,'title':'Ticket du circuit HTTP','type':wf_type,'start_date':'2026-11-02','due_date':'2026-11-06'})
wf_tid=urllib.parse.urlsplit(wf_ticket_url).path.rsplit('/',1)[-1]
wf_ver=re.search(r'name="version" value="(\d+)"',wf_ticket).group(1)
check('Incident HTTP' in wf_ticket and 'Attente HTTP' in wf_ticket,'ticket form uses project vocabulary and valid next statuses')
check(call('/tickets/'+wf_tid+'/quick',{'_csrf':token(wf_ticket),'field':'status','value':'done','version':wf_ver},True)[0]==422,'forbidden transition refused through quick edit')
check(call('/tickets/'+wf_tid+'/move',{'_csrf':token(wf_ticket),'status':'done','version':wf_ver},True)[0]==422,'forbidden transition refused through board endpoint')
status,wf_json,_=call('/tickets/'+wf_tid+'/quick',{'_csrf':token(wf_ticket),'field':'status','value':wf_status,'version':wf_ver},True)
check(status==200 and json.loads(wf_json)['ticket']['status_label']=='Attente HTTP','allowed custom transition persisted')
for wf_view in ['/list','/board','/timeline','/charts']:
    code,view_page,_=call(wf_view+'?project='+wf_pid)
    check(code==200 and ('Attente HTTP' in view_page or 'Incident HTTP' in view_page),'custom vocabulary renders in '+wf_view)
http_support.client=viewer_client
_,wf_viewer,_=call('/tickets/'+wf_tid)
wf_viewer_id=re.search(r'<script type="application/json" id="mention-members">([\s\S]*?)</script>',wf_ticket)
wf_candidates=json.loads(wf_viewer_id.group(1));wf_viewer_user=next(person['id'] for person in wf_candidates if person['email']=='qa.viewer@example.com')
check(call(wf_path,dict(wf_policy,_csrf=token(wf_viewer)),True)[0]==403,'read-only user cannot administer workflow')
check(call('/tickets/'+wf_tid+'/follow',{'action':'follow'},True)[0]==403,'following a ticket requires CSRF')
status,wf_follow,_=call('/tickets/'+wf_tid+'/follow',{'_csrf':token(wf_viewer),'action':'follow','user_id':'999999'},True)
check(status==200 and json.loads(wf_follow)['following'],'read-only user can follow with identity derived from session')
http_support.client=admin_client
wf_comment='<p>Merci <span data-mention-id="'+str(wf_viewer_user)+'">Faux libellé</span></p>'
_,wf_after,_=call('/tickets/'+wf_tid+'/comments',{'_csrf':token(wf_ticket),'body':wf_comment,'body_format':'html'})
check('@QA Lecture' in wf_after and 'Faux libellé' not in wf_after,'rich mention label normalized to real account')
http_support.client=viewer_client
status,wf_inbox,_=call('/notifications?unread=1&project='+wf_pid)
check(status==200 and 'Ticket du circuit HTTP' in wf_inbox and 'vous a mentionné' in wf_inbox,'personal inbox contains mention with email transport disabled')
wf_notice=next(identity for identity,row in re.findall(r'data-inbox-id="(\d+)"([\s\S]*?)</article>',wf_inbox) if 'Ticket du circuit HTTP' in row)
check(call('/notifications/'+wf_notice+'/state',{'action':'read'},True)[0]==403,'read-state update requires CSRF')
status,wf_read,_=call('/notifications/'+wf_notice+'/state',{'_csrf':token(wf_inbox),'action':'read'},True)
check(status==200 and json.loads(wf_read)['read'],'notification can be marked read over AJAX')
status,wf_unread,_=call('/notifications/'+wf_notice+'/state',{'_csrf':token(wf_inbox),'action':'unread'},True)
check(status==200 and not json.loads(wf_unread)['read'],'notification can be marked unread over AJAX')
http_support.client=admin_client
check(call('/notifications/'+wf_notice+'/state',{'_csrf':token(wf_after),'action':'read'},True)[0]==422,'another user cannot change a notification')
http_support.client=viewer_client
status,_,wf_open_url=call('/notifications/'+wf_notice+'/state',{'_csrf':token(wf_inbox),'action':'open','_ticket_return':'/notifications?unread=1&project='+wf_pid})
check(status==200 and '/tickets/'+wf_tid in wf_open_url and 'return=' in wf_open_url,'opening notification returns to the originating inbox')
check(call('/notifications/count',None,True)[0]==200,'notification badge count endpoint authenticated')
check(call('/notifications/read-all',{'_csrf':token(wf_inbox)},True)[0]==200,'mark-all-read endpoint works on own inbox')
check(call('/tickets/'+wf_tid+'/follow',{'_csrf':token(wf_inbox),'action':'unfollow'},True)[0]==200,'personal follow can be removed')
http_support.client=admin_client
