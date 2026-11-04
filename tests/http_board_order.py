"""Manual board rank is independent of remembered list presentation."""
import json,re,uuid,urllib.parse
from http_workflows_support import call,get_token,token,check,passed_count
csrf,_=get_token('/login');_,page,_=call('/login',{'_csrf':csrf,'email':'qa.admin@example.com','password':'Tests-Sillage-2026!'});csrf=token(page)
suffix=uuid.uuid4().hex[:6];_,_,url=call('/projects',{'_csrf':csrf,'name':'Ordre manuel '+suffix,'key':'BO'+suffix.upper()});pid=urllib.parse.parse_qs(urllib.parse.urlsplit(url).query)['project'][0]
ids=[];versions={}
for title in ['Zulu','Alpha','Milieu']:
 _,page,url=call('/tickets',{'_csrf':csrf,'project_id':pid,'title':title});tid=urllib.parse.urlsplit(url).path.rsplit('/',1)[-1];ids.append(tid);versions[tid]=re.search(r'name="version" value="(\d+)"',page).group(1)
def order(route):
 _,html,_=call(route);return re.findall(r'(?:class="ticket-card[^"\n]*"[^>]*|<tr[^>]*)data-ticket-id="(\d+)"',html)
check(order('/list?project='+pid+'&sort=title&direction=asc')==[ids[1],ids[2],ids[0]],'list applies chosen alphabetical presentation')
check(order('/board?project='+pid)==ids,'board ignores remembered list sorting and keeps manual rank')
check(order('/board?project='+pid+'&sort=progress_percent&direction=desc&sort2=title')==ids,'explicit multi-sort does not override manual board rank')
_,_,_=call('/list?project='+pid+'&sort=title&direction=desc')
check(call('/tickets/'+ids[2]+'/move',{'status':'todo','before_id':ids[0],'version':versions[ids[2]]},True)[0]==403,'manual move still requires CSRF')
status,body,_=call('/tickets/'+ids[2]+'/move',{'_csrf':csrf,'status':'todo','before_id':ids[0],'version':versions[ids[2]]},True)
check(status==200,'manual insertion before another card persisted')
check(order('/board?project='+pid)==[ids[2],ids[0],ids[1]],'board reload restores exact manual insertion')
check(order('/list?project='+pid)==[ids[0],ids[2],ids[1]],'board navigation preserves list sort preference')
check(call('/tickets/'+ids[2]+'/move',{'_csrf':csrf,'status':'todo','before_id':ids[1],'version':versions[ids[2]]},True)[0]==422,'stale manual reorder cannot overwrite newer position')
print(str(passed_count())+' board order HTTP checks passed')
