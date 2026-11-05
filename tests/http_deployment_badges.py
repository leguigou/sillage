"""Real signed webhooks feed the list badges; Git connection verification uses isolated transport."""
import json,uuid,hmac,hashlib,urllib.request,urllib.parse,re
from http_workflows_support import call,get_token,token,check,passed_count,BASE
csrf,_=get_token('/login');_,html,_=call('/login',{'_csrf':csrf,'email':'qa.admin@example.com','password':'Tests-Sillage-2026!'});csrf=token(html)
suffix=uuid.uuid4().hex[:6];_,_,url=call('/projects',{'_csrf':csrf,'name':'Badges déploiement '+suffix,'key':'DP'+suffix.upper()});pid=urllib.parse.parse_qs(urllib.parse.urlsplit(url).query)['project'][0]
repo='demo/github-'+suffix
status,body,_=call('/connections',{'_csrf':csrf,'project_id':pid,'provider':'github','repository':repo,'token':'synthetic-git-token'},True);check(status==200,'badge project connected');cid=json.loads(body)['id'];secret=json.loads(call('/connections/'+str(cid)+'/webhook',{'_csrf':csrf},True)[1])['secret']
def send(event,payload):
    raw=json.dumps({'repository':{'full_name':repo},**payload}).encode();signature='sha256='+hmac.new(secret.encode(),raw,hashlib.sha256).hexdigest()
    with urllib.request.urlopen(urllib.request.Request(BASE+'/webhooks/'+str(cid),data=raw,headers={'Content-Type':'application/json','X-Hub-Signature-256':signature,'X-GitHub-Event':event})) as response:check(response.status==200,'signed '+event+' received')
cases=[('Correction du sitemap','deployment','success','staging','À tester'),('Marge et promotions automatiques','pr','open','staging','PR ouverte'),('Pièces jointes du formulaire de contact','deployment','success','production','En production'),('Filtre des tailles sur mobile','pr','merged','staging','Fusionné sur staging'),('Synchronisation du catalogue','deployment','failure','staging','Déploiement échoué'),('Gestion des stocks magasins','deployment','pending','production','Déploiement en attente'),('Préparer la livraison','pr','merged','production','Fusionné sur production')]
for index,(title,kind,state,target,label) in enumerate(cases,1):
    _,page,url=call('/tickets',{'_csrf':csrf,'project_id':pid,'title':title,'estimated_hours':'4','progress_percent':str(index*10)});tid=urllib.parse.urlsplit(url).path.rsplit('/',1)[-1];key=re.search(r'<span class="ticket-key">([^<]+)</span>',page).group(1);sha=hashlib.sha1((suffix+str(index)).encode()).hexdigest();date='2026-11-05T10:00:00Z'
    if kind=='pr':send('pull_request',{'action':'opened' if state=='open' else 'closed','pull_request':{'number':8000+index,'title':key+' '+title,'state':'open' if state=='open' else 'closed','merged_at':date if state=='merged' else None,'updated_at':date,'head':{'ref':'feature/'+key,'sha':sha,'repo':{'full_name':repo}},'base':{'ref':target,'sha':'b'*40}}})
    else:
        send('push',{'ref':'refs/heads/staging','commits':[{'id':sha,'message':key+' '+title,'timestamp':date}]})
        send('deployment_status',{'deployment':{'id':8000+index,'environment':target,'sha':sha,'ref':'staging','created_at':date},'deployment_status':{'state':state,'updated_at':date}})
    _,listing,_=call('/list?project='+pid);row=re.search(r'<tr data-ticket-id="'+tid+r'".*?</tr>',listing,re.S).group(0)
    check('data-column="development"' in row and label in row,'list shows verified '+label)
    check('/tickets/'+tid in row and '#development' in row,'badge links to ticket development')
_,listing,_=call('/list?project='+pid);check('data-column-toggle="development"' in listing and 'value="development"' in listing,'deployment column available in view options and report columns')
print('Deployment badges UI project '+pid)
print(str(passed_count())+' deployment badge HTTP checks passed')
