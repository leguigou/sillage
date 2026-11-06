(() => {
  'use strict';
  const panel=()=>document.getElementById('development');if(!panel()) return;
  const endpoint=location.pathname+'/development';
  let gitKind='all',gitQuery='';
  function filterTimeline() {
    const root=panel();if(!root) return;
    root.querySelectorAll('[data-git-kind]').forEach(button=>button.setAttribute('aria-pressed',String(button.dataset.gitKind===gitKind)));
    const input=root.querySelector('[data-git-timeline-search]');if(input && input.value!==gitQuery) input.value=gitQuery;
    const query=gitQuery.trim().toLocaleLowerCase();let visible=0;
    root.querySelectorAll('[data-git-event-kind]').forEach(item=>{item.hidden=(gitKind!=='all' && item.dataset.gitEventKind!==gitKind)||(query!=='' && !item.textContent.toLocaleLowerCase().includes(query));if(!item.hidden) visible++;});
    root.querySelectorAll('[data-git-day]').forEach(day=>day.hidden=![...day.querySelectorAll('[data-git-event-kind]')].some(item=>!item.hidden));
    root.querySelectorAll('[data-git-day-label]').forEach(time=>{const date=new Date(time.dateTime+'T00:00:00Z');if(!Number.isNaN(date.getTime())) time.textContent=new Intl.DateTimeFormat('fr-FR',{weekday:'long',day:'numeric',month:'long',year:'numeric',timeZone:'UTC'}).format(date);});
    const status=root.querySelector('[data-git-timeline-result]');if(status) status.textContent=visible?visible+' observation'+(visible>1?'s affichées.':' affichée.'):'Aucun élément reçu pour ces filtres.';
  }
  document.addEventListener('click',event=>{const button=event.target.closest('[data-git-kind]');if(!button) return;gitKind=button.dataset.gitKind;filterTimeline();if(button.closest('.development-counts')) panel().querySelector('.git-timeline-toolbar')?.scrollIntoView({block:'start',behavior:matchMedia('(prefers-reduced-motion: reduce)').matches?'auto':'smooth'});});
  document.addEventListener('input',event=>{if(!event.target.matches('[data-git-timeline-search]')) return;gitQuery=event.target.value;filterTimeline();});
  filterTimeline();
  async function post(path,body) {
    const response=await fetch(path,{method:'POST',body,headers:{Accept:'application/json'}});
    if(response.redirected || !response.headers.get('content-type')?.includes('application/json')) throw new Error('Votre session a expiré. Rechargez la page.');
    const data=await response.json();if(!response.ok) throw new Error(data.error||'L’opération a échoué.');return data;
  }
  async function refresh() {
    const response=await fetch(endpoint,{headers:{Accept:'text/html','X-Requested-With':'XMLHttpRequest'},cache:'no-store'});
    if(response.redirected || !response.ok) throw new Error('Impossible d’actualiser le développement. Rechargez le ticket.');
    const page=new DOMParser().parseFromString(await response.text(),'text/html'),incoming=page.getElementById('development');
    if(!incoming) throw new Error('Le panneau de développement n’est plus disponible.');
    const opened=[...panel().querySelectorAll('details[open][id]')].map(node=>node.id),guide=panel().querySelector('.ticket-development-guide')?.open;
    panel().replaceChildren(...incoming.childNodes);opened.forEach(id=>{const node=document.getElementById(id);if(node) node.open=true;});
    if(guide) panel().querySelector('.ticket-development-guide').open=true;
    filterTimeline();
  }
  document.addEventListener('click',async event=>{
    const button=event.target.closest('[data-git-recover]');if(!button) return;
    const message=button.closest('[data-git-sync-status]').querySelector('[data-git-recover-message]');button.disabled=true;
    try {const data=await post('/connections/'+button.dataset.gitRecover+'/recover',new URLSearchParams({_csrf:csrfToken,project_id:button.dataset.project}));message.textContent=data.status.worker?.active?'Rattrapage demandé. Le panneau sera actualisé automatiquement.':'Rattrapage enregistré. Le service de synchronisation doit être démarré sur le serveur.';}
    catch(error){message.textContent=error.message;}finally{button.disabled=false;}
  });
  let automaticRefresh=false;
  setInterval(async()=>{if(automaticRefresh || historyBusy || document.hidden || !panel() || panel().hidden || document.querySelector('dialog[open]') || document.activeElement?.matches('[data-git-timeline-search]')) return;automaticRefresh=true;try{await refresh();}catch{}finally{automaticRefresh=false;}},15000);
  document.addEventListener('click',async event=>{
    const button=event.target.closest('[data-ticket-development-refresh]');if(!button) return;
    button.disabled=true;try {await refresh();toast('Informations reçues actualisées.');}catch(error){toast(error.message,true);}finally{if(button.isConnected) button.disabled=false;}
  });
  let historyBusy=false;
  const historyMessage=text=>{const node=panel().querySelector('[data-git-history-status]');if(node) node.textContent=text;};
  function historyLock(value) {historyBusy=value;panel().querySelectorAll('[data-git-sync-branch],[data-git-sync-pr],[data-git-history-next]').forEach(button=>button.disabled=value);}
  async function syncBranch(link,page=1) {
    if(historyBusy) return;historyLock(true);historyMessage('Recherche des pull requests, y compris fusionnées…');
    let result=null,failures=0,partial=false,reason='',finished=0;
    try {
      result=await post(endpoint+'/links/'+link+'/sync',new URLSearchParams({_csrf:csrfToken,page:String(page)}));
      for(const id of result.pull_requests) {
        historyMessage('Récupération des commits et fichiers · '+(++finished)+' / '+result.pull_requests.length+'…');
        try {const data=await post(endpoint+'/pull-requests/'+id+'/sync',new URLSearchParams({_csrf:csrfToken}));partial ||= !data.commits_complete;}
        catch(error){failures++;reason=error.message;}
      }
      await refresh();
      historyMessage(!result.pull_requests.length?'Aucune pull request correspondante sur cette page.':failures?('Pull requests récupérées ; '+failures+' détail(s) à relancer. '+reason):('Historique synchronisé : '+result.pull_requests.length+' pull request(s) sur cette page.'+(partial?' Certains commits ne sont pas fournis par l’API ; consultez le fournisseur.':'')));
      const more=panel().querySelector('[data-git-history-next]');more.hidden=!result.has_more;more.dataset.link=link;more.dataset.page=result.next_page;
    }catch(error){historyMessage(error.message+' Les informations déjà reçues sont conservées.');}
    finally{historyLock(false);}
  }
  document.addEventListener('click',async event=>{
    const branch=event.target.closest('[data-git-sync-branch]'),more=event.target.closest('[data-git-history-next]'),pr=event.target.closest('[data-git-sync-pr]');
    if(branch) {await syncBranch(branch.dataset.gitSyncBranch);return;}
    if(more) {await syncBranch(more.dataset.link,Number(more.dataset.page));return;}
    if(!pr || historyBusy) return;historyLock(true);historyMessage('Récupération des commits et fichiers de la pull request…');
    try {const data=await post(endpoint+'/pull-requests/'+pr.dataset.gitSyncPr+'/sync',new URLSearchParams({_csrf:csrfToken}));await refresh();historyMessage(data.commits_complete?'Commits et fichiers actualisés.':'Fichiers actualisés ; la liste des commits est partielle. Consultez le fournisseur.');}
    catch(error){historyMessage(error.message+' Les derniers détails reçus sont conservés.');}
    finally{historyLock(false);}
  });
  const procedureDialog=document.getElementById('test-procedure-dialog');
  if(procedureDialog) {
    const form=procedureDialog.querySelector('form'),status=procedureDialog.querySelector('[data-procedure-status]'),publish=form.querySelector('[data-procedure-publish]');
    let target=null,loading=null;
    document.addEventListener('click',async event=>{
      const button=event.target.closest('[data-test-procedure]');if(!button) return;
      loading?.abort();const request=new AbortController();loading=request;target=endpoint+'/pull-requests/'+button.dataset.testProcedure+'/test-procedure';
      form.reset();form.querySelectorAll('input,textarea').forEach(field=>field.disabled=true);publish.disabled=true;status.textContent='Chargement…';procedureDialog.querySelector('[data-procedure-context]').textContent='';window.SillageDialogs.show(procedureDialog);
      try {
        const response=await fetch(target,{headers:{Accept:'application/json'},cache:'no-store',signal:request.signal});
        if(response.redirected || !response.headers.get('content-type')?.includes('application/json')) throw new Error('Votre session a expiré. Rechargez le ticket.');
        const data=await response.json();if(!response.ok) throw new Error(data.error||'Procédure indisponible.');if(loading!==request) return;
        form.elements.submission_key.value=data.submission_key;procedureDialog.querySelector('[data-procedure-context]').textContent=data.title;status.textContent='';form.querySelectorAll('input,textarea').forEach(field=>field.disabled=false);publish.disabled=false;window.SillageDialogs.commit(procedureDialog);
      }catch(error){if(error.name!=='AbortError' && loading===request) status.textContent=error.message;}
    });
    procedureDialog.addEventListener('close',()=>{loading?.abort();loading=null;});
    form.addEventListener('submit',async event=>{
      event.preventDefault();if(publish.disabled) return;const body=new FormData(form);publish.disabled=true;form.querySelectorAll('input,textarea').forEach(field=>field.readOnly=true);form.setAttribute('aria-busy','true');status.textContent='Publication…';
      try {
        const data=await post(target,body),thread=document.getElementById('activity');
        if(!document.getElementById('comment-'+data.id)) thread.insertAdjacentHTML('beforeend',data.html);
        thread.querySelector('[data-no-comments]')?.remove();document.querySelectorAll('.ticket-comment-count').forEach(node=>node.textContent='('+data.count+')');
        window.SillageDialogs.complete(procedureDialog);toast('Procédure publiée dans le suivi du ticket.');
      }catch(error){status.textContent=error.message;}
      finally{form.removeAttribute('aria-busy');form.querySelectorAll('input,textarea').forEach(field=>field.readOnly=false);publish.disabled=false;}
    });
  }
  const filesDialog=document.getElementById('git-files-dialog');
  if(filesDialog) {
    const title=filesDialog.querySelector('[data-git-files-title]'),message=filesDialog.querySelector('[data-git-files-status]'),list=filesDialog.querySelector('[data-git-files-list]');
    const previous=filesDialog.querySelector('[data-git-files-previous]'),next=filesDialog.querySelector('[data-git-files-next]');
    let fileEvent=null,filePage=1,request=null;
    async function loadFiles(page) {
      request?.abort();const current=new AbortController();request=current;previous.disabled=next.disabled=true;message.textContent='Chargement des fichiers…';list.replaceChildren();
      try {
        const response=await fetch(endpoint+'/pull-requests/'+fileEvent+'/files?page='+page,{headers:{Accept:'application/json'},cache:'no-store',signal:current.signal});
        if(response.redirected || !response.headers.get('content-type')?.includes('application/json')) throw new Error('Votre session a expiré. Rechargez le ticket.');
        const data=await response.json();if(!response.ok) throw new Error(data.error||'Impossible de lire les fichiers.');if(request!==current) return;
        title.textContent=data.title;filePage=data.page;previous.hidden=filePage===1;next.hidden=!data.has_more;
        message.textContent=data.details.file_count+' fichier(s) · page '+filePage+' · relevé du '+data.details.fetched_at+(data.stale?' · Données à actualiser depuis la pull request.':'');
        for(const file of data.files) {
          const row=document.createElement('article');row.className='git-file-row';
          const info=document.createElement('div'),path=document.createElement('strong'),note=document.createElement('small');path.textContent=file.path;note.textContent=file.status+(file.previous_path?' · Ancien chemin : '+file.previous_path:'');info.append(path,note);
          const added=document.createElement('span'),deleted=document.createElement('span');added.className='git-lines-added';deleted.className='git-lines-deleted';added.textContent=file.additions===null?'Ajouts inconnus':'+'+file.additions;deleted.textContent=file.deletions===null?'Suppressions inconnues':'−'+file.deletions;row.append(info,added,deleted);list.append(row);
        }
        if(!data.files.length) list.textContent='Aucun fichier modifié sur cette page.';
      }catch(error){if(error.name!=='AbortError' && request===current) message.textContent=error.message;}
      finally{if(request===current){request=null;previous.disabled=next.disabled=false;}}
    }
    document.addEventListener('click',event=>{const button=event.target.closest('[data-git-files]');if(!button) return;fileEvent=button.dataset.gitFiles;title.textContent='';previous.hidden=next.hidden=true;window.SillageDialogs.show(filesDialog);loadFiles(1);});
    previous.addEventListener('click',()=>loadFiles(filePage-1));next.addEventListener('click',()=>loadFiles(filePage+1));filesDialog.addEventListener('close',()=>{request?.abort();request=null;list.replaceChildren();});
  }
  document.addEventListener('submit',async event=>{
    const form=event.target;if(!form.matches('[data-ticket-branch-unlink]') || event.defaultPrevented) return;
    event.preventDefault();const button=form.querySelector('button');if(button.disabled) return;button.disabled=true;
    let saved=false;
    try {await post(form.getAttribute('action'),new FormData(form));saved=true;await refresh();toast('Lien manuel retiré.');}
    catch(error){toast(saved?'Lien retiré. Rechargez le ticket pour actualiser l’affichage.':error.message,true);}
    finally {if(button.isConnected) button.disabled=false;}
  });
  const form=document.querySelector('[data-ticket-branch-form]');if(!form) return;
  const dialog=form.closest('dialog'),repo=form.elements.connection_id,branch=form.elements.branch_name;
  const status=form.querySelector('[data-ticket-branch-status]'),load=form.querySelector('[data-ticket-load-branches]');
  const previous=form.querySelector('[data-ticket-branches-previous]'),next=form.querySelector('[data-ticket-branches-next]'),external=form.querySelector('[data-ticket-repository-link]');
  const search=form.querySelector('[data-ticket-branch-search]'),searchButton=form.querySelector('[data-ticket-branch-search-button]'),manual=form.querySelector('[data-ticket-branch-manual]');
  let page=1,busy=false,listed=false,listing=null,generation=0,appliedSearch='';
  function lock(value) {busy=value;form.setAttribute('aria-busy',String(value));form.querySelectorAll('button,input,select').forEach(node=>node.disabled=value);}
  function emptyChoices() {branch.replaceChildren(new Option('Choisir une branche…',''));}
  function updateRepository() {
    listing?.abort();generation++;listed=false;emptyChoices();status.textContent='';previous.hidden=next.hidden=true;page=1;search.value='';appliedSearch='';
    const href=repo.selectedOptions[0]?.dataset.repositoryUrl;external.hidden=!href;if(href) external.href=href;else external.removeAttribute('href');
    branch.required=!manual.value;
  }
  function renderChoices(items) {
    const chosen=branch.value,oldLabel=[...branch.options].find(option=>option.value===chosen)?.textContent;
    emptyChoices();
    items.forEach(item=>{
      const date=item.last_commit_at?new Date(item.last_commit_at).toLocaleString('fr-FR',{year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit'}):'date indisponible';
      branch.append(new Option(item.name+' — '+date,item.name,false,item.name===chosen));
    });
    if(chosen && !items.some(item=>item.name===chosen)) {
      const group=document.createElement('optgroup');group.label='Sélection conservée hors des résultats affichés';group.append(new Option(oldLabel||chosen,chosen,true,true));branch.append(group);
    }
  }
  async function list(target,force=false) {
    if(busy || !repo.value) return;
    listing?.abort();const current=new AbortController(),requestGeneration=++generation;listing=current;
    const query=target===1?search.value.trim():appliedSearch;
    load.disabled=previous.disabled=next.disabled=searchButton.disabled=true;status.textContent='Chargement et classement des branches…';
    try {
      const response=await fetch(form.dataset.branchesUrl,{method:'POST',headers:{Accept:'application/json'},body:new URLSearchParams({_csrf:csrfToken,connection_id:repo.value,page:String(target),search:query,refresh:force?'1':'0'}),signal:current.signal});
      if(response.redirected || !response.headers.get('content-type')?.includes('application/json')) throw new Error('Votre session a expiré. Rechargez la page.');
      const data=await response.json();if(!response.ok) throw new Error(data.error||'Catalogue indisponible.');
      if(requestGeneration!==generation) return;
      renderChoices(data.items);page=data.page;appliedSearch=query;listed=true;previous.hidden=page===1;next.hidden=!data.has_more;
      status.textContent=data.total?('Page '+page+' · '+data.items.length+' branche(s) sur '+data.total+' · dernier commit le plus récent en premier.'):'Aucune branche ne correspond à cette recherche.';
    }catch(error){if(error.name!=='AbortError' && requestGeneration===generation) status.textContent=error.message;}
    finally{if(listing===current){listing=null;if(!busy){load.disabled=searchButton.disabled=false;previous.disabled=next.disabled=search.value.trim()!==appliedSearch;}}}
  }
  repo.addEventListener('change',()=>{manual.value='';updateRepository();if(repo.value) list(1);});
  manual.addEventListener('input',()=>{if(manual.value) branch.value='';branch.required=!manual.value;});
  branch.addEventListener('change',()=>{manual.value='';branch.required=true;});
  dialog.addEventListener('sillage:dialog-discarded',updateRepository);
  dialog.addEventListener('close',()=>{listing?.abort();generation++;});
  new MutationObserver(()=>{if(dialog.open && !listed && repo.value) list(1);}).observe(dialog,{attributes:true,attributeFilter:['open']});
  updateRepository();
  load.addEventListener('click',()=>list(1,true));searchButton.addEventListener('click',()=>list(1));
  search.addEventListener('keydown',event=>{if(event.key==='Enter'){event.preventDefault();list(1);}});
  search.addEventListener('input',()=>{previous.disabled=next.disabled=Boolean(listing)||search.value.trim()!==appliedSearch;});
  previous.addEventListener('click',()=>list(page-1));next.addEventListener('click',()=>list(page+1));
  form.addEventListener('submit',async event=>{
    event.preventDefault();if(busy) return;const body=new FormData(form);if(manual.value) body.set('branch_name',manual.value);
    listing?.abort();generation++;lock(true);status.textContent='Vérification et liaison de la branche…';let saved=false,linkedId=null;
    try {const data=await post(form.getAttribute('action'),body);saved=true;linkedId=String(data.id);manual.value='';updateRepository();window.SillageDialogs.complete(dialog);await refresh();toast('Branche liée au ticket.');}
    catch(error){status.textContent=saved?'La branche est liée. Rechargez le ticket pour actualiser l’affichage.':error.message;if(saved) toast(status.textContent,true);}
    finally{lock(false);}
    if(linkedId) await syncBranch(linkedId);
  });
})();
