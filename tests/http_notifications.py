"""Project policies and durable delivery via real HTTP, PHP CLI and the local mail log."""
import os, pathlib, subprocess, shutil, concurrent.futures

http_support.client=admin_client;csrf,notify_page=get_token('/settings')
notify_marker='Notifications-'+uuid.uuid4().hex[:8]
_,notify_page,notify_project_url=call('/projects',{'_csrf':csrf,'name':notify_marker,'key':'NE'+uuid.uuid4().hex[:6].upper()})
notify_pid=urllib.parse.parse_qs(urllib.parse.urlsplit(notify_project_url).query)['project'][0]
_,notify_page,_=call('/settings?project='+notify_pid)
account_forms=re.findall(r'<form[^>]+data-account-id="(\d+)"[^>]*>(.*?)</form>',notify_page,re.S)
notify_viewer=next(i for i,row in account_forms if 'qa.viewer@example.com' in row)
notify_admin=next(i for i,row in account_forms if 'qa.admin@example.com' in row)
notify_fields={'_csrf':csrf,'version':'0','enabled':'1','recipients[]':[notify_viewer]}
for event in ['created','updated','assigned','status','completed','commented','archived']: notify_fields['rules['+event+'][]']=['assignee','selected']
check('Notifications des projets' in notify_page and 'Vos notifications e-mail' in notify_page,'notification settings and personal preference rendered')
check(call('/projects/'+notify_pid+'/notifications',{'enabled':'1'},True)[0]==403,'project notification settings require CSRF')
check(call('/projects/'+notify_pid+'/notifications',notify_fields)[0]==200,'event recipient matrix saved per project')
check(call('/projects/'+notify_pid+'/notifications',notify_fields,True)[0]==422,'stale email settings cannot overwrite new version')
notify_mail_dir=pathlib.Path(__file__).resolve().parents[1]/'storage'/'mail'
def notify_mails(marker):
    return [p.read_text(encoding='utf-8') for p in notify_mail_dir.glob('*.txt') if marker in p.read_text(encoding='utf-8')]
_,notify_body,notify_ticket_url=call('/tickets',{'_csrf':csrf,'project_id':notify_pid,'title':notify_marker,'description':'Description interne à ne pas envoyer','assignee_id':notify_viewer})
notify_ticket=notify_ticket_url.rsplit('/',1)[-1]
notify_messages=notify_mails(notify_marker)
check(len(notify_messages)==1 and 'To: qa.viewer@example.com' in notify_messages[0],'creation delivers one local log message despite overlapping recipient roles')
check('Description interne à ne pas envoyer' not in notify_messages[0] and '/tickets/'+notify_ticket in notify_messages[0],'notification excludes private body and includes protected link')
notify_version=re.search(r'name="version" value="(\d+)"',notify_body).group(1)
call('/tickets/'+notify_ticket+'/quick',{'_csrf':csrf,'field':'status','value':'done','version':notify_version},True)
check(len(notify_mails(notify_marker))==2 and any('Ticket terminé' in m for m in notify_mails(notify_marker)),'completion event sends one notification')
call('/tickets/'+notify_ticket+'/comments',{'_csrf':csrf,'body':'Texte confidentiel du commentaire'})
check(len(notify_mails(notify_marker))==3 and all('Texte confidentiel du commentaire' not in m for m in notify_mails(notify_marker)),'comment notifies without copying its content')
notify_own_fields={'_csrf':csrf,'version':'1','enabled':'1','notify_actor':'1','recipients[]':[notify_admin],'rules[created][]':['selected']}
call('/projects/'+notify_pid+'/notifications',notify_own_fields)
check(call('/account/notifications',{'_csrf':csrf})[0]==200,'personal ticket email preference can be disabled')
notify_muted=notify_marker+' muted'
call('/tickets',{'_csrf':csrf,'project_id':notify_pid,'title':notify_muted})
check(not notify_mails(notify_muted),'personal opt-out prevents project notification')
call('/account/notifications',{'_csrf':csrf,'ticket_email_enabled':'1'})
notify_opted_in=notify_marker+' subscribed'
call('/tickets',{'_csrf':csrf,'project_id':notify_pid,'title':notify_opted_in})
check(len(notify_mails(notify_opted_in))==1 and 'To: qa.admin@example.com' in notify_mails(notify_opted_in)[0],'personal subscription and optional author delivery respected')
_,notify_page,_=call('/settings?project='+notify_pid)
check('Acceptés par le transport' in notify_page and 'Suivi des envois de ce projet' in notify_page,'project email delivery history visible')
call('/projects/'+notify_pid+'/notifications',{**notify_own_fields,'version':'2','enabled':''})
# Exercise the real public CLI worker with test-only environment and synthetic addresses.
notify_root=pathlib.Path(__file__).resolve().parents[1]
notify_env=os.environ.copy()
if not notify_env.get('DB_DATABASE') and (notify_root/'.runtime/test-env.json').is_file(): notify_env.update(json.loads((notify_root/'.runtime/test-env.json').read_text(encoding='utf-8-sig')))
if notify_env.get('DB_DATABASE')!='sillage_test': raise RuntimeError('Notification CLI test must use isolated database')
notify_env.update({'APP_ENV':'local','MAIL_TRANSPORT':'log'})
notify_php=shutil.which('php');notify_ini=notify_root/'.runtime/php-config/php.ini'
notify_prefix=[notify_php]+(['-c',str(notify_ini)] if notify_ini.exists() else [])
def notify_cli(script,*args,fail_transport=False):
    result=subprocess.run(notify_prefix+[str(notify_root/script),*map(str,args)],env={**notify_env,**({'MAIL_TRANSPORT':'unsupported'} if fail_transport else {})},capture_output=True,text=True,encoding='utf-8',check=True,timeout=25)
    return json.loads(result.stdout)
queue=notify_cli('tests/notification_queue.php')
check(notify_cli('tests/notification_queue.php','status',queue['id'])['state']=='pending','CLI mutation keeps notification pending until committed worker delivery')
failed=notify_cli('bin/console.php','notifications:send',fail_transport=True)
check(failed['failed']==1 and notify_cli('tests/notification_queue.php','status',queue['id'])['attempts']==1,'failed mail transport preserves notification for retry')
notify_cli('tests/notification_queue.php','exhaust',queue['id'])
notify_cli('bin/console.php','notifications:send',fail_transport=True)
check(notify_cli('tests/notification_queue.php','status',queue['id'])['state']=='failed','delivery attempts stop at five')
check(call('/projects/'+str(queue['project_id'])+'/notifications/retry',{'_csrf':csrf})[0]==200,'administrator can retry failed project emails')
check(notify_cli('tests/notification_queue.php','status',queue['id'])['state']=='sent' and len(notify_mails(queue['marker']))==1,'retried message accepted once by local transport')
queue=notify_cli('tests/notification_queue.php')
notify_cli('tests/notification_queue.php','stale',queue['id'])
with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
    deliveries=list(pool.map(lambda _:notify_cli('bin/console.php','notifications:send'),range(2)))
check(sum(d['sent'] for d in deliveries)==1 and len(notify_mails(queue['marker']))==1,'two workers safely claim one expired delivery lease')
queue=notify_cli('tests/notification_queue.php');notify_cli('tests/notification_queue.php','exhaust',queue['id'])
notify_cli('bin/console.php','notifications:send',fail_transport=True)
notify_cli('tests/notification_queue.php','stale',queue['id'])
check(notify_cli('bin/console.php','notifications:send')['failed']==1 and not notify_mails(queue['marker']),'expired lease cannot exceed the delivery attempt limit')
queue=notify_cli('tests/notification_queue.php');notify_cli('tests/notification_queue.php','optout',queue['id'])
check(notify_cli('bin/console.php','notifications:send')['cancelled']==1 and not notify_mails(queue['marker']),'opt-out checked again immediately before delivery')
