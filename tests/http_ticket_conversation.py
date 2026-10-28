"""Extra real HTTP checks, executed in the authenticated workflow fixture context."""
import base64
csrf, body = get_token('/tickets/'+ticket_id)
current_version = re.search(r'name="version" value="(\d+)"',body).group(1)
_,same_ticket,_=call('/tickets/'+ticket_id)
check(re.search(r'name="version" value="(\d+)"',same_ticket).group(1)==current_version,'opening ticket twice does not change its version or stored information')
check('id="ticket-edit-dialog"' in body and 'data-ticket-comment' in body and 'data-ticket-tab="development"' in body,'ticket exposes explicit editing quick comment and separate development tab')
check('data-file-queue' in body and 'data-upload-submit hidden' in body and 'danger-outline' in body, 'ticket exposes contextual attachment queue and outlined archive')
for invalid in ['-1','101','1.5']:
    check(call('/tickets/'+ticket_id+'/quick',{'_csrf':csrf,'field':'progress_percent','value':invalid,'version':current_version},True)[0]==422,'invalid progress rejected '+invalid)
status, body, _ = call('/tickets/'+ticket_id+'/quick',{'_csrf':csrf,'field':'progress_percent','value':'65','version':current_version},True)
check(status==200 and json.loads(body)['ticket']['progress_percent']==65, 'ticket progress persists over HTTP')
status, body, _ = call('/export?project='+pid+'&q='+marker+'&format=json')
check(json.loads(body)[0]['progress_percent']==65, 'ticket progress exported')
status, body, _ = call('/tickets/'+ticket_id+'/comments',{'_csrf':csrf,'body_format':'html','body':'<p onclick="evil()"><b>Riche-QA</b></p><script>evil()</script>'})
check(status==200 and '<strong>Riche-QA</strong>' in body and '<script>evil()' not in body and 'onclick="evil()"' not in body, 'rich comment sanitized and formatted over HTTP')
png=base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=')
status, body, _ = upload('/tickets/'+ticket_id+'/attachments',{'_csrf':csrf},'files[]','paste.png',png,'image/png')
image_id=re.findall(r'data-attachment-id="(\d+)"',body)[-1]
check(status==200 and 'paste.png' in body, 'multiple-file endpoint accepts image upload')
image_html='<p>Capture QA</p><img src="/attachments/'+image_id+'/preview" onerror="evil()">'
status, body, _=call('/tickets/'+ticket_id+'/comments',{'_csrf':csrf,'body_format':'html','body':image_html})
check(status==200 and 'src="/attachments/'+image_id+'/preview"' in body and 'onerror=' not in body, 'attached image embeds safely in conversation')
with http_support.client.open(BASE+'/attachments/'+image_id+'/preview') as response:
    check(response.status==200 and response.headers['Content-Type']=='image/png' and response.read()==png, 'authenticated image preview serves original bytes')
check(call('/attachments/'+attachment.group(1)+'/preview')[0]==415, 'non-image preview refused')
status, body, _ = call('/tickets/'+ticket_id+'/comments',{'_csrf':csrf,'body_format':'html','body':'<img src="/attachments/999999999/preview">'},True)
check(status==422, 'foreign or missing attachment cannot be embedded')
status, body, _=call('/attachments/'+image_id+'/delete',{'_csrf':csrf},True)
check(status==200 and call('/attachments/'+image_id+'/preview')[0]==404, 'attachment deletion removes private image')
check(call('/attachments/'+attachment.group(1)+'/delete',{},True)[0]==403, 'attachment deletion requires CSRF')
status, body, _ = upload('/tickets/'+ticket_id+'/attachments',{'_csrf':csrf},'files[]','first.txt',b'First','text/plain',extra_files=[('second.txt',b'Second','text/plain'),('unsafe.html',b'<html><body>unsafe</body></html>','text/html')],json_accept=True)
batch=json.loads(body)
check(status==200 and len(batch['files'])==2 and len(batch['errors'])==1 and batch['errors'][0]['index']==2, 'multipart batch returns successes and rejected file separately')
saved_client=http_support.client
try:
    http_support.client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    anonymous_status, _, anonymous_url=call('/attachments/'+str(batch['files'][0]['id'])+'/preview')
    check(anonymous_status==200 and anonymous_url.endswith('/login'), 'anonymous attachment preview requires authentication')
    viewer_csrf,_=get_token('/login')
    _, viewer_html,_=call('/login',{'_csrf':viewer_csrf,'email':'qa.viewer@example.com','password':'Tests-Sillage-2026!'})
    viewer_csrf=token(viewer_html)
    check(call('/attachments/'+str(batch['files'][0]['id'])+'/delete',{'_csrf':viewer_csrf},True)[0]==403,'read-only member cannot delete attachments')
finally:
    http_support.client=saved_client
for file in batch['files']:
    check(call('/attachments/'+str(file['id'])+'/delete',{'_csrf':csrf},True)[0]==200, 'successful batch attachment can be removed')
