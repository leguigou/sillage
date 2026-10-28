"""Real HTTP workflow: shared teams, independent assignees and project-scoped ticket filters."""
http_support.client=admin_client
csrf,team_html=get_token('/settings')
team_marker='Equipe-'+uuid.uuid4().hex[:8]
status,team_html,_=call('/teams',{'_csrf':csrf,'name':team_marker})
tid=re.search(r'data-team-id="(\d+)"[^>]*>\s*<header[^>]*><h3>'+team_marker,team_html).group(1)
check(status==200,'create real team over HTTP')
status,team_html,team_project_url=call('/projects',{'_csrf':csrf,'name':team_marker+' projet A','key':'EA'+uuid.uuid4().hex[:6].upper(),'teams_present':'1','team_ids[]':[tid]})
team_pid=urllib.parse.parse_qs(urllib.parse.urlsplit(team_project_url).query)['project'][0]
check(status==200 and re.search(r'name="team_ids\[\]" value="'+tid+'" checked',team_html),'create project with team and remember selection')
_,_,second_url=call('/projects',{'_csrf':csrf,'name':team_marker+' projet B','key':'EB'+uuid.uuid4().hex[:6].upper()})
team_pid2=urllib.parse.parse_qs(urllib.parse.urlsplit(second_url).query)['project'][0]
check(call('/teams/'+tid+'/projects',{'_csrf':csrf,'project_id':team_pid2,'action':'add'})[0]==200,'same team linked to second project')
_,team_html,_=call('/?project='+team_pid)
assigned_id=re.search(r'<select name="assignee_id">.*?<option value="(\d+)"',team_html,re.S).group(1)
status,team_html,ticket_url=call('/tickets',{'_csrf':csrf,'project_id':team_pid,'title':team_marker+' ticket','team_id':tid,'assignee_id':assigned_id,'progress_percent':'35','estimated_hours':'4.5'})
team_ticket_id=ticket_url.rsplit('/',1)[-1]
check(status==200 and re.search(r'name="team_id".*?value="'+tid+'" selected',team_html,re.S),'ticket assigned to project team')
team_version=re.search(r'name="version" value="(\d+)"',team_html).group(1)
check(call('/teams/'+tid+'/projects',{'_csrf':csrf,'project_id':team_pid,'action':'remove'},True)[0]==422,'team with assigned tickets cannot be detached')
for path in ['/list','/board','/timeline','/charts','/']:
    status,team_html,_=call(path+'?project='+team_pid+'&team='+tid)
    check(status==200 and team_marker in team_html,'team filter rendered '+path)
_,team_html,_=call('/board?project='+team_pid+'&group=team')
check('board-group-title' in team_html and team_marker in team_html,'kanban groups by team')
_,team_html,_=call('/charts?project='+team_pid+'&team='+tid)
check('Le travail par équipe' in team_html and '35 %' in team_html and '4,50' in team_html,'team report includes progress and estimated hours')
_,team_json,_=call('/export?format=json&project='+team_pid+'&team='+tid)
check(json.loads(team_json)[0]['team_id']==int(tid),'team filtered export retains team identity')
check(call('/teams/'+tid+'/projects',{'project_id':team_pid2,'action':'remove'},True)[0]==403,'team project mutation requires CSRF')
_,team_html,_=call('/tickets/'+team_ticket_id)
team_version=re.search(r'name="version" value="(\d+)"',team_html).group(1)
status,team_html,_=call('/tickets/'+team_ticket_id,{'_csrf':csrf,'version':team_version,'title':team_marker+' ticket','team_id':'','assignee_id':assigned_id})
check(status==200 and re.search(r'name="assignee_id".*?value="'+assigned_id+'" selected',team_html,re.S),'clearing team preserves individual owner')
check(call('/teams/'+tid+'/projects',{'_csrf':csrf,'project_id':team_pid,'action':'remove'})[0]==200,'team removed after ticket reassignment')
check(call('/tickets',{'_csrf':csrf,'project_id':team_pid,'title':'Invalid team','team_id':tid},True)[0]==422,'ticket cannot use detached team')
# Leave a visible isolated example for browser QA; never touches the main installation.
call('/teams/'+tid+'/projects',{'_csrf':csrf,'project_id':team_pid,'action':'add'})
_,team_html,_=call('/tickets/'+team_ticket_id)
team_version=re.search(r'name="version" value="(\d+)"',team_html).group(1)
call('/tickets/'+team_ticket_id,{'_csrf':csrf,'version':team_version,'title':team_marker+' ticket','team_id':tid,'assignee_id':assigned_id,'progress_percent':'35','estimated_hours':'4.5'})
