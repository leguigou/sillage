"""Real member profile/avatar flow in the isolated test installation."""
import base64
avatar_csrf,avatar_page=get_token('/settings')
avatar_name=re.search(r'name="name" value="([^"]+)"',avatar_page).group(1)
avatar_png=base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=')
avatar_fields={'_csrf':avatar_csrf,'name':avatar_name,'user_id':'999999999','_return':'/settings'}
check(upload('/account',{'name':avatar_name},'avatar','avatar.png',avatar_png,'image/png',json_accept=True)[0]==403,'avatar upload requires CSRF')
status,avatar_page,_=upload('/account',avatar_fields,'avatar','avatar.png',avatar_png,'image/png')
avatar_path=re.search(r'src="(/avatars/(\d+)/[a-f0-9]{64})"',avatar_page)
check(status==200 and avatar_path is not None and avatar_path.group(2)!='999999999','avatar upload is bound to authenticated account')
avatar_url=avatar_path.group(1)
check(avatar_page.count(avatar_url)>=3,'photo appears in profile sidebar and header')
avatar_ticket_title='Avatar-'+uuid.uuid4().hex[:8]
_,avatar_ticket_page,avatar_ticket_url=call('/tickets',{'_csrf':avatar_csrf,'project_id':pid,'title':avatar_ticket_title,'assignee_id':avatar_path.group(2)})
avatar_ticket_id=avatar_ticket_url.rsplit('/',1)[-1]
_,avatar_comment_page,_=call('/tickets/'+avatar_ticket_id+'/comments',{'_csrf':avatar_csrf,'body':'Une photo dans la conversation'})
check(re.search(r'class="comment"[\s\S]*?src="'+re.escape(avatar_url),avatar_comment_page) is not None,'comment avatar belongs to its author')
_,avatar_board_page,_=call('/board?project='+pid+'&q='+avatar_ticket_title)
check(re.search(r'class="ticket-card[^>]*>[\s\S]*?src="'+re.escape(avatar_url),avatar_board_page) is not None,'assigned ticket card uses account photo')
with http_support.client.open(BASE+avatar_url) as response:
    check(response.headers['Content-Type']=='image/png' and response.read()==avatar_png,'private avatar serves exact bytes')
with urllib.request.urlopen(BASE+avatar_url) as response:
    check(response.url.endswith('/login'),'anonymous user cannot read avatar')
status,avatar_page,_=call('/account',{'_csrf':avatar_csrf,'name':avatar_name})
check(status==200 and avatar_url in avatar_page,'saving profile without upload preserves avatar')
check(upload('/account',avatar_fields,'avatar','evil.svg',b'<svg xmlns="http://www.w3.org/2000/svg"><script>bad()</script></svg>','image/svg+xml',json_accept=True)[0]==422,'active SVG avatar rejected')
check(upload('/account',dict(avatar_fields,remove_avatar='1'),'avatar','avatar.png',avatar_png,'image/png',json_accept=True)[0]==422,'ambiguous avatar removal and replacement refused')
status,avatar_page,_=upload('/account',avatar_fields,'avatar','new.png',avatar_png+b'new-avatar','image/png')
avatar_new_url=re.search(r'src="(/avatars/\d+/[a-f0-9]{64})"',avatar_page).group(1)
check(status==200 and avatar_new_url!=avatar_url and call(avatar_url)[0]==404,'replacement invalidates old avatar URL')
status,avatar_page,_=call('/account',dict(avatar_fields,remove_avatar='1'))
check(status==200 and avatar_new_url not in avatar_page and call(avatar_new_url)[0]==404,'avatar removal restores initials')
