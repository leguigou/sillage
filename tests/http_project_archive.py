"""Real HTTP workflow: an admin archives a project, which leaves menus and selectors, then reactivates it."""
http_support.client=admin_client
csrf,archive_html=get_token('/settings')
archive_marker='Archive-'+uuid.uuid4().hex[:8]
_,_,archive_url=call('/projects',{'_csrf':csrf,'name':archive_marker,'key':'HA'+uuid.uuid4().hex[:6].upper()})
archive_pid=urllib.parse.parse_qs(urllib.parse.urlsplit(archive_url).query)['project'][0]
_,archive_html,_=call('/settings')
check('action="/projects/'+archive_pid+'/archive"' in archive_html and 'settings-danger" data-confirm="Archiver le projet' in archive_html,'settings offer a confirmed archive action')
check(call('/projects/'+archive_pid+'/archive',{'action':'archive'},True)[0]==403,'archiving a project requires CSRF')
check(call('/projects/'+archive_pid+'/archive',{'_csrf':csrf,'action':'hide'},True)[0]==422,'unknown archive action rejected')
status,archive_html,archive_return=call('/projects/'+archive_pid+'/archive',{'_csrf':csrf,'action':'archive'})
check(status==200 and archive_return.split('#')[0].endswith('/settings') and 'Projet « '+archive_marker+' » archivé' in archive_html,'admin archives a project')
menu=archive_html.split('class="project-list"',1)[1].split('</div>',1)[0]
check('/?project='+archive_pid+'"' not in menu,'archived project left out of the side menu')
check('Projets archivés' in archive_html and 'Réactiver le projet '+archive_marker in archive_html,'archived project listed with a reactivation button')
_,archive_html,_=call('/?project='+archive_pid)
check('Ce projet est archivé' in archive_html,'archived project still reachable by its link, with a notice')
status,archive_json,_=call('/projects/'+archive_pid+'/archive',{'_csrf':csrf,'action':'restore'},True)
check(status==200 and json.loads(archive_json)['project']['archived'] is False,'admin reactivates the project')
_,archive_html,_=call('/settings')
menu=archive_html.split('class="project-list"',1)[1].split('</div>',1)[0]
check('/?project='+archive_pid+'"' in menu,'reactivated project back in the side menu')
