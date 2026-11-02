"""Actual Apache ingress, MCP refusals and local synthetic LLM egress metadata."""
http_support.client=admin_client
_,technical_settings,_=call('/settings')
check('href="#technical-logs"' in technical_settings and 'data-log-filters' in technical_settings,'administrator sees technical log tab')
check(call('/administration/logs?provider=unknown',json_accept=True)[0]==422,'unknown provider log filter rejected')
check(call('/administration/logs?from=2026-02-30',json_accept=True)[0]==422,'invalid log date rejected')
technical_secret='DO-NOT-STORE-'+uuid.uuid4().hex
with admin_client.open(urllib.request.Request(BASE+'/notifications/count?token='+technical_secret,headers={'Accept':'application/json'})) as technical_response:
    technical_response.read();technical_request_id=technical_response.headers.get('X-Request-ID')
check(bool(re.fullmatch('[a-f0-9]{32}',technical_request_id or '')),'incoming request receives server generated correlation id')
_,technical_body,_=call('/administration/logs?request_id='+technical_request_id,json_accept=True)
technical_records=json.loads(technical_body)['rows']
check(len(technical_records)==1 and technical_records[0]['endpoint']=='/notifications/count' and technical_records[0]['outcome']=='success' and technical_records[0]['user_name']=='QA Admin','actual API ingress recorded with route result and authenticated actor')
check(technical_secret not in technical_body and 'token=' not in technical_body,'request query credential omitted from persisted API log')
try:
    admin_client.open(urllib.request.Request(BASE+'/mcp',data=b'{"jsonrpc":"2.0","id":1,"method":"ping"}',headers={'Accept':'application/json','Content-Type':'application/json','Authorization':'Bearer '+technical_secret}))
except urllib.error.HTTPError as technical_error:
    technical_error.read();technical_mcp_request=technical_error.headers.get('X-Request-ID')
_,technical_body,_=call('/administration/logs?request_id='+technical_mcp_request,json_accept=True)
technical_mcp=json.loads(technical_body)['rows']
check(len(technical_mcp)==1 and technical_mcp[0]['channel']=='mcp' and technical_mcp[0]['http_status']==401 and technical_mcp[0]['outcome']=='error','MCP authentication refusal captured before authentication')
check(technical_secret not in technical_body and technical_mcp[0]['user_id'] is None,'refused MCP bearer and browser identity are not trusted')
_,technical_body,_=call('/administration/logs?channel=development&provider=github&direction=incoming',json_accept=True)
technical_git=json.loads(technical_body)['rows']
check(any(row['http_status']==401 and row['connection_id'] for row in technical_git) and any(row['http_status']==200 for row in technical_git),'signed Git webhooks and invalid signatures appear in development logs')
_,technical_body,_=call('/administration/logs?channel=llm&provider=lmstudio&direction=outgoing',json_accept=True)
technical_llm=json.loads(technical_body)['rows']
check(any(row['http_status']==200 for row in technical_llm) and any(row['http_status']==401 for row in technical_llm),'real local synthetic provider transfers log success and HTTP rejection')
check('synthetic-secret' not in technical_body and 'Proposition de test' not in technical_body and 'secret_cipher' not in technical_body,'LLM logs exclude credentials and generated content')
technical_llm_request=technical_llm[0]['request_id']
_,technical_body,_=call('/administration/logs?request_id='+technical_llm_request,json_accept=True)
technical_related=json.loads(technical_body)['rows']
check(any(row['direction']=='outgoing' for row in technical_related) and any(row['direction']=='incoming' for row in technical_related),'API ingress and provider egress share correlation id')
technical_older=json.loads(call('/administration/logs',json_accept=True)[1])
check(len(technical_older['rows'])==50 and technical_older['next'] is not None,'technical logs expose bounded cursor page')
technical_next=json.loads(call('/administration/logs?before='+str(technical_older['next']),json_accept=True)[1])
check(not ({row['id'] for row in technical_older['rows']} & {row['id'] for row in technical_next['rows']}),'cursor pagination does not repeat records when new calls arrive')
http_support.client=viewer_client
check(call('/administration/logs',json_accept=True)[0]==403,'read-only user cannot retrieve technical logs directly')
_,technical_viewer,_=call('/settings')
check('id="technical-logs"' not in technical_viewer and 'href="#technical-logs"' not in technical_viewer,'technical log interface absent for read-only user')
http_support.client=admin_client
