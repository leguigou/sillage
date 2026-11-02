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
  const choices=form.querySelector('datalist'),status=form.querySelector('[data-ticket-branch-status]'),load=form.querySelector('[data-ticket-load-branches]');
  const previous=form.querySelector('[data-ticket-branches-previous]'),next=form.querySelector('[data-ticket-branches-next]'),external=form.querySelector('[data-ticket-repository-link]');
  let page=1,busy=false;
  function lock(value) {busy=value;form.setAttribute('aria-busy',String(value));form.querySelectorAll('button,input,select').forEach(node=>node.disabled=value);}
  function updateRepository() {
    choices.replaceChildren();status.textContent='';previous.hidden=next.hidden=true;page=1;
    const href=repo.selectedOptions[0]?.dataset.repositoryUrl;external.hidden=!href;if(href) external.href=href;else external.removeAttribute('href');
  }
  repo.addEventListener('change',()=>{branch.value='';updateRepository();});dialog.addEventListener('sillage:dialog-discarded',updateRepository);updateRepository();
  async function list(target) {
    if(busy || !repo.reportValidity()) return;
    lock(true);status.textContent='Chargement des branches…';
    try {
      const data=await post(form.dataset.branchesUrl,new URLSearchParams({_csrf:csrfToken,connection_id:repo.value,page:String(target)}));
      choices.replaceChildren(...data.branches.map(name=>new Option(name,name)));page=data.page;previous.hidden=page===1;next.hidden=!data.has_more;
      status.textContent='Page '+page+' · '+data.branches.length+' branche(s). Choisissez une suggestion ou saisissez le nom exact.';
    }catch(error){status.textContent=error.message;}
    finally{lock(false);}
  }
  load.addEventListener('click',()=>list(1));previous.addEventListener('click',()=>list(page-1));next.addEventListener('click',()=>list(page+1));
  form.addEventListener('submit',async event=>{
    event.preventDefault();if(busy) return;const body=new FormData(form);lock(true);status.textContent='Vérification et liaison de la branche…';let saved=false;
    try {await post(form.getAttribute('action'),body);saved=true;window.SillageDialogs.complete(dialog);await refresh();toast('Branche liée au ticket.');}
    catch(error){status.textContent=saved?'La branche est liée. Rechargez le ticket pour actualiser l’affichage.':error.message;if(saved) toast(status.textContent,true);}
    finally{lock(false);}
  });
})();
