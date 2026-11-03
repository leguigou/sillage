"""User/project filter memory across routes, logout and session replacement."""
import json,uuid,urllib.parse,http.cookiejar,urllib.request
import http_workflows_support as s
from http_workflows_support import call,get_token,token,check
csrf,_=get_token('/login');_,html,_=call('/login',{'_csrf':csrf,'email':'qa.admin@example.com','password':'Tests-Sillage-2026!'});csrf=token(html)
suffix=uuid.uuid4().hex[:6]
_,_,url=call('/projects',{'_csrf':csrf,'name':'Filtres '+suffix,'key':'FM'+suffix.upper()});pid=urllib.parse.parse_qs(urllib.parse.urlsplit(url).query)['project'][0]
query='critere-'+suffix
_,body,_=call('/list?project='+pid+'&q='+query)
check('value="'+query+'"' in body,'explicit filter rendered')
for route in ['/board','/timeline','/list']:
 _,body,_=call(route+'?project='+pid);check('value="'+query+'"' in body,'filter survives navigation to '+route)
jar=next(h.cookiejar for h in s.client.handlers if isinstance(h,urllib.request.HTTPCookieProcessor))
cookies=list(jar);prefs=[c for c in cookies if '_filters_' in c.name]
check(len(prefs)>0 and query not in prefs[0].value,'persistent filter cookie excludes readable search text')
for c in cookies:
 if '_filters_' not in c.name: jar.clear(c.domain,c.path,c.name)
csrf,_=get_token('/login');_,html,_=call('/login',{'_csrf':csrf,'email':'qa.admin@example.com','password':'Tests-Sillage-2026!'})
_,body,_=call('/list?project='+pid);check('value="'+query+'"' in body,'encrypted cookie restores filter after a new login session')
_,body,_=call('/list?project='+pid+'&filters_reset=1');check('value="'+query+'"' not in body,'reset clears active criteria')
_,body,_=call('/board?project='+pid);check('value="'+query+'"' not in body,'reset survives next navigation')
print('Filter memory UI project '+pid)
