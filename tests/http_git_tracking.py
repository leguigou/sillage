"""Signed provider events, shared project linkage and opt-in Git automation over real HTTP/MySQL."""
git_marker='Git-'+uuid.uuid4().hex[:8]
git_project_key='GT'+uuid.uuid4().hex[:6].upper()
status,git_body,git_url=call('/projects',{'_csrf':csrf,'name':git_marker,'key':git_project_key,'repository_connection_id':connection.group(1)})
git_project_id=urllib.parse.parse_qs(urllib.parse.urlsplit(git_url).query)['project'][0]
git_repo_id=connection.group(1)
check(status==200,'project created with existing shared repository')
_,git_body,_=call('/development?project='+git_project_id)
check('value="qa/repo" readonly' in git_body and '/connections/'+git_repo_id+'/sync' in git_body,'shared repository visible in new project without credential duplication')
_,git_body,_=call('/?project='+git_project_id)
check('value="'+git_repo_id+'" selected' in git_body,'project editor remembers primary repository')
_,git_ticket_page,git_ticket_url=call('/tickets',{'_csrf':csrf,'project_id':git_project_id,'title':git_marker+' target'})
git_ticket_id=git_ticket_url.rsplit('/',1)[-1]
git_ticket_key=git_project_key+'-1'
_,git_other_page,git_other_url=call('/tickets',{'_csrf':csrf,'project_id':pid,'title':git_marker+' other project'})
git_other_id=git_other_url.rsplit('/',1)[-1]
git_other_key=re.search(r'<span class="ticket-key">([^<]+)</span>',git_other_page).group(1)
status,_,_=call('/automation',{'_csrf':csrf,'project_id':git_project_id,'name':'Review '+git_marker,'event':'development.pull_request_merged','enabled':'1','action_status':'review','condition_development_repository':'qa/repo'})
check(status==200,'web editor creates pull-request merge automation')
git_sha=hashlib.sha1(git_marker.encode()).hexdigest()
git_branch='feature/'+git_ticket_key+'-'+git_other_key
git_push={'repository':{'full_name':'qa/repo'},'ref':'refs/heads/'+git_branch,'created':True,'after':git_sha,'head_commit':{'timestamp':'2026-10-28T10:00:00Z'},'commits':[{'id':git_sha,'message':git_ticket_key+' '+git_other_key+' Correction','url':'https://github.com/qa/repo/commit/'+git_sha,'timestamp':'2026-10-28T10:00:00Z'}]}
check(signed_webhook(hook,git_push)[0]==200,'signed push records branch and commit')
git_pr={'repository':{'full_name':'qa/repo'},'action':'opened','pull_request':{'number':int(uuid.uuid4().hex[:6],16)+100,'title':git_ticket_key+' Correction','state':'open','head':{'ref':git_branch,'sha':git_sha},'html_url':'https://github.com/qa/repo/pull/1','updated_at':'2026-10-28T10:05:00Z'}}
check(signed_webhook(hook,git_pr)[0]==200,'signed pull request attaches to ticket')
_,git_body,_=call('/tickets/'+git_ticket_id)
check('ticket-development-branch' in git_body and git_branch in git_body and git_sha[:12] in git_body and 'Ouverte' in git_body,'ticket development panel renders branch commit and pull request')
git_pr['action']='closed';git_pr['pull_request'].update({'state':'closed','merged':True,'updated_at':'2026-10-28T11:00:00Z'})
check(signed_webhook(hook,git_pr)[0]==200,'signed merge notification accepted')
_,git_body,_=call('/tickets/'+git_ticket_id)
git_version=re.search(r'name="version" value="(\d+)"',git_body).group(1)
check('Fusionnée' in git_body and '<option value="review" selected' in git_body,'merge changes ticket to configured review state')
signed_webhook(hook,git_pr)
_,git_body,_=call('/tickets/'+git_ticket_id)
check(re.search(r'name="version" value="(\d+)"',git_body).group(1)==git_version,'duplicate webhook does not apply automation twice')
check(signed_webhook(hook,{'repository':{'full_name':'qa/repo'},'pull_request':'malformed'})[0]==400,'malformed signed provider object returns bounded client error')
_,git_original,_=call('/tickets/'+git_other_id)
check(git_sha[:12] in git_original,'same signed event visible in second linked project')
status,_,_=call('/connections/'+git_repo_id+'/delete',{'_csrf':csrf,'project_id':git_project_id})
_,git_original,_=call('/development?project='+pid)
check(status==200 and '/connections/'+git_repo_id+'/sync' in git_original,'detaching shared repository preserves original project integration')
_,git_body,_=call('/tickets/'+git_ticket_id)
check(git_sha[:12] not in git_body,'detached project no longer displays repository events')
