"""List action permissions and canonical edit navigation with origin context."""
http_support.client=admin_client
list_action_csrf,_=get_token('/settings')
_,_,list_action_project_url=call('/projects',{'_csrf':list_action_csrf,'name':'Interactions HTTP '+uuid.uuid4().hex[:6],'key':'UI'+uuid.uuid4().hex[:6].upper()})
list_action_pid=urllib.parse.parse_qs(urllib.parse.urlsplit(list_action_project_url).query)['project'][0]
_,_,list_action_ticket_url=call('/tickets',{'_csrf':list_action_csrf,'project_id':list_action_pid,'title':'Vérification des menus et modales','description':'Message initial pour les contrôles de saisie.','estimated_hours':'7.5','progress_percent':'60','due_date':'2026-11-20'})
list_action_tid=urllib.parse.urlsplit(list_action_ticket_url).path.rsplit('/',1)[-1]
list_action_source='/list?'+urllib.parse.urlencode({'project':list_action_pid,'q':'Vérification','sort':'due','direction':'asc'})
_,list_action_html,_=call(list_action_source)
check('data-quick-field="due_date"' not in list_action_html and 'data-quick-field="estimated_hours"' not in list_action_html,'list due date and estimate are read-only')
check('20/11/2026' in list_action_html and '7,50 h' in list_action_html and '3,00 h restantes' in list_action_html,'list keeps formatted dates estimate and remaining effort')
list_action_link=re.search(r'href="([^"]+#ticket-edit-dialog)"',list_action_html)
check(list_action_link is not None and 'Ajouter un commentaire' in list_action_html and 'role="menu"' in list_action_html,'editor has menu actions and direct edit link')
list_action_href=__import__('html').unescape(list_action_link.group(1))
check(urllib.parse.parse_qs(urllib.parse.urlsplit(list_action_href).query)['return'][0]==list_action_source,'row action preserves list filters and ordering')
_,list_action_edit,_=call(list_action_href)
check('id="ticket-edit-dialog"' in list_action_edit and 'name="estimated_hours"' in list_action_edit and 'name="due_date"' in list_action_edit,'canonical ticket editor still exposes estimate and due date')
http_support.client=viewer_client
_,list_action_viewer,_=call(list_action_source)
check('#ticket-edit-dialog' not in list_action_viewer and '#ticket-comment-dialog' not in list_action_viewer and 'Copier le lien' in list_action_viewer,'read-only menu only exposes reading and copying actions')
_,list_action_viewer_ticket,_=call(list_action_href)
check('id="ticket-edit-dialog"' not in list_action_viewer_ticket,'edit fragment cannot expose editor to read-only account')
http_support.client=admin_client
print('UI inspection project '+list_action_pid+' ticket '+list_action_tid)
