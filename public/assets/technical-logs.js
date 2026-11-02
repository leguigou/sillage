(() => {
  'use strict';
  const root=document.querySelector('[data-technical-logs]');if(!root) return;
  const form=root.querySelector('[data-log-filters]'),rows=root.querySelector('[data-log-rows]'),status=root.querySelector('[data-log-status]');
  const more=root.querySelector('[data-log-more]'),empty=root.querySelector('[data-log-empty]'),reset=root.querySelector('[data-log-reset]');
  const channels={api:'API / AJAX',mcp:'MCP',oauth:'OAuth MCP',development:'Développement Git',llm:'Intelligence artificielle'};
  const providers=Object.fromEntries([...form.elements.provider.options].map(option=>[option.value,option.textContent]));
  let controller=null,next=null,loaded=false,count=0,appliedQuery='';
  const queryFromForm=()=>new URLSearchParams(new FormData(form));
  const clearFilters=()=>form.querySelectorAll('input,select').forEach(field=>field.value='');
  const text=(tag,value,className='')=>{const node=document.createElement(tag);node.textContent=value;node.className=className;return node;};
  function diagnostic(code) {
    if(!code) return '—';
    const labels={tool_error:'L’outil a refusé ou échoué à exécuter l’action.',internal_error:'Erreur interne du serveur.',invalid_response_or_configuration:'Configuration ou réponse invalide.','rpc:-32700':'Message JSON invalide.','rpc:-32600':'Requête JSON-RPC invalide.','rpc:-32601':'Méthode MCP inconnue.','rpc:-32602':'Paramètres ou droits MCP invalides.','rpc:-32603':'Erreur interne MCP.','transport:6':'Résolution du nom de serveur impossible.','transport:7':'Connexion au fournisseur impossible.','transport:28':'Délai d’attente dépassé.','transport:60':'Vérification du certificat HTTPS refusée.'};
    return (labels[code]|| (code.startsWith('http:')?'Requête refusée par le serveur.':'Échec du transport réseau.'))+' ('+code+')';
  }
  function render(entry) {
    const row=document.createElement('tr');row.dataset.logId=entry.id;
    const date=new Date(entry.created_at.replace(' ','T')+'Z');
    row.append(text('td',Number.isNaN(date.getTime())?entry.created_at:date.toLocaleString('fr-FR')));
    const service=document.createElement('td');service.append(text('strong',entry.direction==='incoming'?'Entrant':'Sortant'),text('small',channels[entry.channel]||entry.channel));
    if(entry.provider) service.append(text('small',providers[entry.provider]||entry.provider));row.append(service);
    const call=document.createElement('td');call.append(text('strong',entry.method+' '+(entry.operation||entry.endpoint)),text('small',entry.operation?entry.endpoint:''));
    const detail=document.createElement('details');detail.append(text('summary','Détails de l’appel'));
    const meta=document.createElement('dl');
    for(const [label,value] of [['Requête',entry.request_id],['Clé MCP',entry.token_id?'#'+entry.token_id:'—'],['Connexion Git',entry.connection_id?'#'+entry.connection_id:'—'],['Taille envoyée',entry.request_bytes===null?'Non mesurée':entry.request_bytes+' octets'],['Taille reçue',entry.response_bytes===null?'Non mesurée':entry.response_bytes+' octets'],['Diagnostic',diagnostic(entry.error_code)]]) meta.append(text('dt',label),text('dd',value));
    const actions=document.createElement('div');actions.className='settings-actions';
    const related=text('button','Voir les appels liés','button subtle');related.type='button';related.dataset.logRelated=entry.request_id;
    const copy=text('button','Copier l’identifiant','button subtle');copy.type='button';copy.dataset.copy=entry.request_id;actions.append(related,copy);detail.append(meta,actions);call.append(detail);row.append(call);
    const result=document.createElement('td');result.append(text('span',entry.outcome==='error'?'Erreur':'Réussite','pill '+(entry.outcome==='error'?'danger':'success')),text('small',entry.http_status?'HTTP '+entry.http_status:'Sans réponse HTTP'));
    row.append(result,text('td',Number(entry.duration_ms).toLocaleString('fr-FR')+' ms'),text('td',entry.user_name||(entry.user_id?'Compte #'+entry.user_id:'Système / non authentifié')));return row;
  }
  async function load(append=false) {
    controller?.abort();const current=new AbortController();controller=current;
    const query=append?new URLSearchParams(appliedQuery):queryFromForm();
    if(append && next) query.set('before',String(next));
    reset.hidden=![...query.entries()].some(([key,value])=>key!=='before' && value!=='');
    root.setAttribute('aria-busy','true');status.textContent='Chargement des appels…';more.disabled=true;
    try {
      const response=await fetch('/administration/logs?'+query,{headers:{Accept:'application/json'},signal:current.signal,cache:'no-store'});
      if(!response.ok) throw new Error(response.status===403?'Accès réservé aux administrateurs.':'Impossible de charger les appels (HTTP '+response.status+').');
      if(!response.headers.get('content-type')?.includes('application/json')) throw new Error('Votre session a expiré. Reconnectez-vous.');
      const data=await response.json();if(controller!==current) return;
      if(!append) {rows.replaceChildren();count=0;appliedQuery=query.toString();}
      rows.append(...data.rows.map(render));count+=data.rows.length;next=data.next;loaded=true;
      more.hidden=!next;empty.hidden=count!==0;status.textContent=count+' appel'+(count===1?'':'s')+' affiché'+(count===1?'':'s')+(next?' · d’autres appels sont disponibles.':'.');
    } catch(error) {
      if(error.name==='AbortError') return;
      status.textContent=error.message+' Les résultats précédents restent affichés.';
    } finally {if(controller===current) {controller=null;root.removeAttribute('aria-busy');more.disabled=queryFromForm().toString()!==appliedQuery;}}
  }
  form.addEventListener('submit',event=>{event.preventDefault();load();});
  form.addEventListener('reset',event=>{event.preventDefault();clearFilters();load();});
  form.addEventListener('input',()=>{
    const query=queryFromForm();reset.hidden=![...query.values()].some(value=>value!=='');
    const pending=loaded && query.toString()!==appliedQuery;more.disabled=Boolean(controller)||pending;
    if(pending) status.textContent='Filtres modifiés. Appliquez-les pour afficher les appels correspondants.';
  });
  root.querySelector('[data-log-refresh]').addEventListener('click',()=>load());
  more.addEventListener('click',()=>load(true));
  root.addEventListener('click',event=>{
    const related=event.target.closest('[data-log-related]');if(!related) return;
    clearFilters();
    form.elements.request_id.value=related.dataset.logRelated;load();
  });
  const panel=root.closest('[data-settings-panel]');
  function onOpen() {if(!loaded && (!panel || !panel.hidden)) load();}
  if(panel) new MutationObserver(onOpen).observe(panel,{attributes:true,attributeFilter:['hidden']});
  onOpen();
})();
