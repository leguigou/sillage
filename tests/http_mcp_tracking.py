"""MCP uses the same review, worklog and delivery contracts as HTTP."""
mt_project=tool('projects_create',{'name':'Livraisons MCP '+marker,'key':'RT'+marker,'require_ticket_validation':True});mt_pid=mt_project['id']
mt_release=tool('releases_save',{'project_id':mt_pid,'name':'2.0.0','target_date':'2026-12-10'})
mt_ticket=tool('tickets_create',{'project_id':mt_pid,'fields':{'title':'Livraison MCP','estimated_hours':'10','progress_percent':60,'release_id':mt_release['id']}})
check(mt_ticket['remaining_hours']=='4.00','MCP exposes progress-based remaining effort')
mt_time=tool('tickets_time_save',{'id':mt_ticket['id'],'version':mt_ticket['version'],'hours':'2.5','worked_on':'2026-01-01','note':'Contrôle MCP'});mt_ticket=mt_time['ticket']
check(mt_ticket['spent_hours']=='2.50' and mt_ticket['remaining_hours']=='4.00','MCP tracks actual time independently')
tool('tickets_time_save',{'id':mt_ticket['id'],'version':mt_ticket['version']-1,'hours':1,'worked_on':'2026-01-01'},success=False)
check(tool('tickets_time_list',{'id':mt_ticket['id'],'limit':1},key=read_key)['total']==1,'read token pages through worklogs')
mt_ticket=tool('tickets_time_delete',{'id':mt_ticket['id'],'version':mt_ticket['version'],'entry_id':mt_time['entry']['id']})['ticket']
check(float(mt_ticket['spent_hours'])==0,'MCP worklog deletion updates total')
mt_history=tool('tickets_history',{'id':mt_ticket['id'],'limit':1},key=read_key)
check(mt_history['entries'][0]['action']=='time.deleted' and mt_history['total']>=3,'MCP history retains deleted worklog data')
tool('tickets_update',{'id':mt_ticket['id'],'version':mt_ticket['version'],'fields':{'status':'done','resolution':'fixed'}},success=False)
mt_ticket=tool('tickets_validate',{'id':mt_ticket['id'],'version':mt_ticket['version'],'approved':True,'note':'Test réalisé'})
mt_ticket=tool('tickets_update',{'id':mt_ticket['id'],'version':mt_ticket['version'],'fields':{'status':'done','resolution':'fixed'}})
check(mt_ticket['resolution']=='fixed','MCP closes approved ticket with explicit resolution')
check(tool('tickets_list',{'project_id':mt_pid,'filters':{'release':mt_release['id'],'resolution':'fixed','validation':'approved'}})['total']==1,'MCP filters version and closure data')
mt_release=tool('releases_deliver',{'id':mt_release['id'],'version':mt_release['version'],'environment':'staging','outcome':'success','reference':'v2.0.0-rc1'})
check(mt_release['delivery_state']['production'] is None,'MCP keeps staging separate from production')
mt_release=tool('releases_deliver',{'id':mt_release['id'],'version':mt_release['version'],'environment':'production','outcome':'success','reference':'v2.0.0'})
check(tool('release_deliveries_list',{'id':mt_release['id']},key=read_key)['total']==2,'MCP reads delivery history')
check(tool('releases_list',{'project_id':mt_pid},key=read_key)['releases'][0]['delivery_state']['production']['outcome']=='success','MCP list reflects explicit production delivery')
mt_release=tool('releases_save',{'project_id':mt_pid,'id':mt_release['id'],'version':mt_release['version'],'archived':True,'target_date':None})
check(mt_release['target_date'] is None and tool('releases_list',{'project_id':mt_pid,'archived':True})['total']==1,'MCP archives version and clears target date')
for mt_name,mt_args in [('tickets_validate',{'id':mt_ticket['id'],'version':mt_ticket['version'],'approved':True}),('releases_save',{'project_id':mt_pid,'name':'Interdite'}),('releases_deliver',{'id':mt_release['id'],'version':mt_release['version'],'environment':'production','outcome':'success','reference':'v2'})]:
    _,mt_denied=rpc('tools/call',{'name':mt_name,'arguments':mt_args},read_key)
    check('error' in mt_denied,'read scope cannot call '+mt_name)
