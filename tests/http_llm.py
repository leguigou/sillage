"""HTTP/MySQL/provider-boundary checks. Only synthetic local servers and keys are used."""
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

from llm_fixture import LlmFixtureHandler

llm_server=ThreadingHTTPServer(('127.0.0.1',0),LlmFixtureHandler)
llm_thread=threading.Thread(target=llm_server.serve_forever,daemon=True);llm_thread.start()
try:
    http_support.client=admin_client;csrf,llm_html=get_token('/settings')
    llm_base='http://127.0.0.1:'+str(llm_server.server_port)
    check('Configurer un fournisseur LLM' in llm_html and 'Anthropic (Claude)' in llm_html,'all provider choices rendered in administrator settings')
    check(call('/llm/profiles/test',{'provider':'lmstudio','base_url':llm_base+'/v1'},True)[0]==403,'LLM connection test requires CSRF')
    check(call('/llm/profiles',{'_csrf':csrf,'name':'Invalid','model':'test'},True)[0]==422,'LLM cannot save before successful connection test')
    for suffix in ['/missing/v1','/empty/v1','/redirect/v1','/bad-json/v1']:
        llm_status,llm_body,_=call('/llm/profiles/test',{'_csrf':csrf,'provider':'lmstudio','base_url':llm_base+suffix,'token':'synthetic-secret'},True)
        check(llm_status==422 and 'synthetic-secret' not in llm_body,'LLM rejects bad endpoint without exposing key '+suffix)
    check(call('/llm/profiles/test',{'_csrf':csrf,'provider':'lmstudio','base_url':llm_base+'/v1','token':'bad'},True)[0]==422,'LLM displays provider authentication rejection')
    _,llm_body,_=call('/llm/profiles/test',{'_csrf':csrf,'provider':'lmstudio','base_url':llm_base+'/v1','token':'synthetic-secret'},True)
    llm_discovery=json.loads(llm_body)
    check(len(llm_discovery['models'])==2 and 'synthetic-secret' not in llm_body,'successful test lists models without returning token')
    check(call('/llm/profiles',{'_csrf':csrf,'proof':llm_discovery['proof'],'name':'Unauthorized model','model':'not-listed','enabled':'1'},True)[0]==422,'browser cannot save a model outside verified catalog')
    llm_status,llm_body,_=call('/llm/profiles',{'_csrf':csrf,'proof':llm_discovery['proof'],'name':'Assistant local — test','model':'sillage-text-test','enabled':'1','make_default':'1'},True)
    llm_id=json.loads(llm_body)['id']
    check(llm_status==200,'verified LLM profile saved as default')
    check(call('/llm/profiles',{'_csrf':csrf,'proof':llm_discovery['proof'],'name':'Replay','model':'sillage-text-test','enabled':'1'},True)[0]==422,'successful connection proof is single use')
    _,llm_html,_=call('/settings')
    llm_catalog=json.loads(re.search(r'<script type="application/json" id="llm-profile-data">(.*?)</script>',llm_html,re.S).group(1))
    llm_profile=next(p for p in llm_catalog['profiles'] if p['id']==llm_id)
    check(llm_profile['model']=='sillage-text-test' and 'synthetic-secret' not in llm_html and 'secret_cipher' not in llm_html,'saved configuration restores provider URL and model but no secret')
    llm_args={'_csrf':csrf,'mode':'draft','text':'Besoin de clarifier le paiement','project_id':pid,'profile_id':llm_id,'profile_version':llm_profile['version']}
    llm_status,llm_body,_=call('/llm/assist',llm_args,True)
    check(llm_status==200 and 'Proposition de test' in json.loads(llm_body)['text'],'draft sent through real local HTTP provider boundary')
    check(LlmFixtureHandler.calls[-1]['authorization']=='Bearer synthetic-secret' and LlmFixtureHandler.calls[-1]['body']['model']=='sillage-text-test','generation uses stored encrypted token and selected model')
    _,llm_html,_=call('/settings');check('Dernier usage réussi : Aucun' not in llm_html,'last successful LLM use persisted')
    llm_marker='RechercheLLM'+uuid.uuid4().hex[:7]
    _,_,llm_ticket_url=call('/tickets',{'_csrf':csrf,'project_id':pid,'title':llm_marker,'description':'Erreur paiement à confirmer'})
    llm_ticket_id=llm_ticket_url.rsplit('/',1)[-1]
    _,llm_body,_=call('/llm/assist',{**llm_args,'mode':'search','text':llm_marker},True)
    llm_answer=json.loads(llm_body)
    check(llm_answer['total']==1 and str(llm_answer['sources'][0]['id'])==llm_ticket_id,'research returns local ticket sources')
    check(llm_marker in LlmFixtureHandler.calls[-1]['body']['messages'][1]['content'],'research sends only retrieved ticket context')
    llm_before=len(LlmFixtureHandler.calls)
    _,llm_body,_=call('/llm/assist',{**llm_args,'mode':'search','text':'NoSuchTicket'+uuid.uuid4().hex},True)
    check(json.loads(llm_body)['sent']==False and len(LlmFixtureHandler.calls)==llm_before,'no matches does not call model')
    check(call('/llm/assist',{**llm_args,'profile_version':999},True)[0]==422,'stale displayed recipient cannot silently switch provider')
    # Editing with blank token reuses the saved key only at the same endpoint.
    _,llm_body,_=call('/llm/profiles/test',{'_csrf':csrf,'id':llm_id,'version':llm_profile['version'],'provider':'lmstudio','base_url':llm_base+'/v1','token':''},True)
    llm_proof=json.loads(llm_body)['proof']
    check(call('/llm/profiles',{'_csrf':csrf,'proof':llm_proof,'name':'Assistant local — test','model':'other-test-model','enabled':'1','make_default':'1'},True)[0]==200,'edit profile with preserved key and new verified model')
    check(call('/llm/profiles/test',{'_csrf':csrf,'id':llm_id,'version':2,'provider':'lmstudio','base_url':llm_base+'/elsewhere','token':''},True)[0]==422,'blank key never follows changed endpoint')
    _,llm_body,_=call('/llm/profiles/test',{'_csrf':csrf,'provider':'ollama','base_url':llm_base+'/ollama','token':''},True)
    llm_proof=json.loads(llm_body)['proof']
    _,llm_body,_=call('/llm/profiles',{'_csrf':csrf,'proof':llm_proof,'name':'Ollama — test','model':'local-text-test','enabled':'1','make_default':'1'},True)
    ollama_id=json.loads(llm_body)['id']
    _,llm_html,_=call('/settings');llm_catalog=json.loads(re.search(r'id="llm-profile-data">(.*?)</script>',llm_html,re.S).group(1))
    check(len([p for p in llm_catalog['profiles'] if p['is_default']])==1 and len(llm_catalog['profiles'])==2,'several saved profiles and one default only')
    llm_status,llm_body,_=call('/llm/assist',{**llm_args,'profile_id':ollama_id,'profile_version':1},True)
    check(llm_status==200 and json.loads(llm_body)['text']=='Réponse locale de test.','Ollama native chat protocol used without key')
    check(call('/llm/assist',llm_args,True)[0]==422,'changed default requires refreshed displayed recipient')
    check(call('/llm/profiles/'+str(ollama_id)+'/action',{'_csrf':csrf,'version':1,'action':'disable'})[0]==200,'default provider can be disabled')
    check(call('/llm/assist',{**llm_args,'profile_id':ollama_id,'profile_version':2},True)[0]==422,'disabled default prevents generation')
    check(call('/llm/profiles/'+str(llm_id)+'/action',{'_csrf':csrf,'version':2,'action':'default'})[0]==200,'saved provider can become default again')
    check(call('/llm/profiles/'+str(ollama_id)+'/action',{'_csrf':csrf,'version':2,'action':'delete'})[0]==200,'saved provider can be deleted')
    check(call('/llm/profiles/'+str(llm_id)+'/action',{'_csrf':csrf,'version':1,'action':'delete'},True)[0]==422,'obsolete profile deletion refused')
    # Keep test profile disabled after the fixture server is stopped.
    call('/llm/profiles/'+str(llm_id)+'/action',{'_csrf':csrf,'version':3,'action':'disable'})
finally:
    llm_server.shutdown();llm_server.server_close();llm_thread.join(timeout=3)
