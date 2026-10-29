"""Persisted appearance, cross-view identity and administration boundaries on real Apache/MySQL."""
import html as appearance_html
def appearance_fields(page):
    region=re.search(r'<section[^>]+id="appearance"[\s\S]*?</section>',page).group()
    fields={}
    for element in re.findall(r'<input\b[^>]*>',region):
        attrs=dict(re.findall(r'([a-z_]+)="([^"]*)"',element))
        if attrs.get('type')=='radio' and not re.search(r'\schecked(?:\s|>)',element): continue
        if 'name' in attrs: fields[attrs['name']]=appearance_html.unescape(attrs.get('value',''))
    return fields

http_support.client=admin_client
_,appearance_page,_=call('/settings')
appearance_original=appearance_fields(appearance_page)
check(call('/appearance',{},True)[0]==403,'appearance changes require CSRF')
appearance_update=dict(appearance_original)
appearance_update['marks[type][bug][icon]']='target'
appearance_update['marks[type][bug][color]']='#15803d'
appearance_update['marks[priority][medium][icon]']='flag'
appearance_update['marks[priority][medium][color]']='#7166eb'
status,appearance_page,_=call('/appearance',appearance_update,True)
check(status==200 and 'Pictogrammes et couleurs enregistrés' in appearance_page,'admin saves visual configuration via AJAX-compatible response')
check(call('/appearance',appearance_update,True)[0]==422,'stale visual configuration refused over HTTP')
appearance_current=appearance_fields(appearance_page)
bad_appearance=dict(appearance_current);bad_appearance['marks[type][bug][icon]']='javascript:alert(1)'
check(call('/appearance',bad_appearance,True)[0]==422,'arbitrary icon payload refused over HTTP')
_,appearance_project,appearance_project_url=call('/projects',{'_csrf':token(appearance_page),'name':'Identité visuelle '+uuid.uuid4().hex[:6],'key':'VI'+uuid.uuid4().hex[:6].upper(),'icon':'globe','color':'#c2410c'})
appearance_pid=urllib.parse.parse_qs(urllib.parse.urlsplit(appearance_project_url).query)['project'][0]
_,appearance_ticket,appearance_ticket_url=call('/tickets',{'_csrf':token(appearance_project),'project_id':appearance_pid,'title':'Bug avec repères personnalisés','type':'bug','priority':'medium','start_date':'2026-11-02','due_date':'2026-11-06'})
appearance_id=urllib.parse.urlsplit(appearance_ticket_url).path.rsplit('/',1)[-1]
for path in ['/list','/board','/timeline','/tickets/'+appearance_id]:
    _,rendered,_=call(path+'?project='+appearance_pid)
    check('data-mark-kind="type" data-mark-key="bug" data-mark-icon="target" data-mark-color="#15803d"' in rendered,'saved bug SVG identity used in '+path.split('?')[0])
_,appearance_settings,_=call('/settings?project='+appearance_pid)
project_marks=re.findall(r'<span class="project-mark [^"]+" data-project-id="'+appearance_pid+r'" data-mark-icon="([^"]+)" data-project-color="([^"]+)"',appearance_settings)
check(len(project_marks)==3 and all(mark==('globe','#c2410c') for mark in project_marks),'sidebar breadcrumb and project directory share persisted identity')
check('/settings?project='+appearance_pid in appearance_settings,'settings link preserves explicit selected project')
status,appearance_json,_=call('/projects/'+appearance_pid+'/update',{'_csrf':token(appearance_settings),'icon':'server','color':'#0891b2'},True)
appearance_saved=json.loads(appearance_json)['project']
check(status==200 and appearance_saved['icon']=='server' and appearance_saved['color']=='#0891b2','project identity update returns persisted values without navigation')
check(call('/projects/'+appearance_pid+'/update',{'_csrf':token(appearance_settings),'icon':'<svg>'},True)[0]==422,'invalid project icon refused')
http_support.client=viewer_client
_,appearance_viewer,_=call('/settings?project='+appearance_pid)
check('id="appearance"' not in appearance_viewer and 'id="project-settings-dialog"' not in appearance_viewer,'viewer cannot see appearance administration')
check(call('/appearance',dict(appearance_current,_csrf=token(appearance_viewer)),True)[0]==403,'viewer cannot change ticket appearance')
check(call('/projects/'+appearance_pid+'/update',{'_csrf':token(appearance_viewer),'icon':'bug'},True)[0]==403,'viewer cannot change project identity')
http_support.client=admin_client
_,appearance_restore_page,_=call('/settings')
appearance_restore=dict(appearance_original,version=appearance_fields(appearance_restore_page)['version'],_csrf=token(appearance_restore_page))
check(call('/appearance',appearance_restore,True)[0]==200,'test restores installation appearance after verification')
