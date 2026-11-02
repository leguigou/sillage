"""The shipped application changelog is visible to administrators only."""
http_support.client=admin_client
status,application_history_page,_=call('/settings')
check(status==200 and 'href="#application-history"' in application_history_page,'administrator can open application history tab')
check('id="application-history"' in application_history_page and 'Version du code installé' in application_history_page,'administration shows the installed application version')
check('Un historique des améliorations dans l’administration' in application_history_page and 'Première base fonctionnelle de Sillage' in application_history_page,'application history includes current and initial improvements')
check('data-history-search' in application_history_page and 'data-history-category' in application_history_page,'history exposes search and change category controls')
check(call('/resources/application-history.json')[0] in (403,404,422),'canonical history file stays outside the public document root')
http_support.client=viewer_client
_,application_history_viewer,_=call('/settings')
check('id="application-history"' not in application_history_viewer and 'href="#application-history"' not in application_history_viewer,'read-only account does not receive administration history')
http_support.client=admin_client
