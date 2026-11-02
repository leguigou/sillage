(() => {
  'use strict';
  const panel=()=>document.getElementById('development');if(!panel()) return;
  const endpoint=location.pathname+'/development';
  document.addEventListener('click',event=>{
    const button=event.target.closest('[data-development-open]');if(!button) return;
    const details=document.getElementById(button.dataset.developmentOpen);
    if(details) {details.open=true;details.scrollIntoView({block:'nearest',behavior:matchMedia('(prefers-reduced-motion: reduce)').matches?'auto':'smooth'});}
  });
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
  }
  document.addEventListener('click',async event=>{
    const button=event.target.closest('[data-ticket-development-refresh]');if(!button) return;
    button.disabled=true;try {await refresh();toast('Informations reçues actualisées.');}catch(error){toast(error.message,true);}finally{if(button.isConnected) button.disabled=false;}
  });
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
    listing?.abort();generation++;lock(true);status.textContent='Vérification et liaison de la branche…';let saved=false;
    try {await post(form.getAttribute('action'),body);saved=true;manual.value='';updateRepository();window.SillageDialogs.complete(dialog);await refresh();toast('Branche liée au ticket.');}
    catch(error){status.textContent=saved?'La branche est liée. Rechargez le ticket pour actualiser l’affichage.':error.message;if(saved) toast(status.textContent,true);}
    finally{lock(false);}
  });
})();
