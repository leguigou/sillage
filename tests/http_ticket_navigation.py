"""Return context survives real ticket navigation and mutations without accepting external destinations."""
from html.parser import HTMLParser
class NavigationLinks(HTMLParser):
    def __init__(self,page):
        super().__init__();self.links=[];self.feed(page)
    def handle_starttag(self,tag,attrs):
        if tag=='a':self.links.append(dict(attrs))

def ticket_back(page): return next(a for a in NavigationLinks(page).links if 'data-ticket-back' in a)
nav_source='/timeline?'+urllib.parse.urlencode({'project':pid,'scale':'months','from':'2026-11-02','q':marker})
_,nav_page,_=call(nav_source)
nav_link=next(a['href'] for a in NavigationLinks(nav_page).links if 'timeline-task' in a.get('class',''))
check(urllib.parse.parse_qs(urllib.parse.urlsplit(nav_link).query)['return']==[nav_source],'timeline ticket links retain period scale project and search')
_,nav_ticket,_=call(nav_link)
check(ticket_back(nav_ticket)['href']==nav_source and ticket_back(nav_ticket)['aria-label']=='Revenir à la chronologie','ticket back link names and targets the originating timeline')
status,nav_ticket,nav_url=call('/tickets/'+ticket_id+'/comments',{'_csrf':token(nav_ticket),'_ticket_return':nav_source,'body':'Retour vers la chronologie après commentaire'})
check(status==200 and urllib.parse.parse_qs(urllib.parse.urlsplit(nav_url).query).get('return')==[nav_source] and ticket_back(nav_ticket)['href']==nav_source,'adding comment preserves original ticket return context')
nav_version=re.search(r'name="version" value="(\d+)"',nav_ticket).group(1)
status,nav_ticket,nav_url=call('/tickets/'+ticket_id,{'_csrf':token(nav_ticket),'_ticket_return':nav_source,'version':nav_version,'title':marker,'description':'<script>alert(1)</script>','status':'progress','type':'task','priority':'high','estimated_hours':'3.25','progress_percent':'65','start_date':'2026-10-26','due_date':'2026-11-04','labels':'qa, test'})
check(status==200 and ticket_back(nav_ticket)['href']==nav_source,'editing ticket preserves timeline return context')
for invalid in ['https://outside.example/','//outside.example/','/\\outside.example/','/oauth/authorize','/tickets/1','/%2ftimeline','/timeline\r\nLocation: https://outside.example/']:
    _,page,_=call('/tickets/'+ticket_id+'?'+urllib.parse.urlencode({'return':invalid}))
    check(ticket_back(page)['href']=='/list?project='+pid,'unsafe or unsupported ticket return destination refused '+repr(invalid))
for source,label in [('/list?project='+pid+'&priority=high&page=2','Revenir à la liste'),('/board?project='+pid+'&group=team','Revenir au tableau')]:
    _,page,_=call('/tickets/'+ticket_id+'?'+urllib.parse.urlencode({'return':source}))
    check(ticket_back(page)['href']==source and ticket_back(page)['aria-label']==label,'other workspace view retains its own return context')
request=urllib.request.Request(BASE+'/tickets/'+ticket_id,headers={'Referer':BASE+nav_source})
with http_support.client.open(request) as response:
    check(ticket_back(response.read().decode())['href']==nav_source,'same-origin workspace referrer supports older ticket links')
request=urllib.request.Request(BASE+'/tickets/'+ticket_id,headers={'Referer':'https://outside.example/timeline'})
with http_support.client.open(request) as response:
    check(ticket_back(response.read().decode())['href']=='/list?project='+pid,'external referrer cannot control ticket back navigation')
