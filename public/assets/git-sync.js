(() => {
  'use strict';
  document.addEventListener('submit',async event=>{
    const form=event.target;if(!form.matches('[data-git-sync-config]')) return;event.preventDefault();if(form.getAttribute('aria-busy')==='true') return;
    const body=new FormData(form),message=form.querySelector('[data-git-config-message]'),controls=[...form.elements].filter(control=>!control.disabled);form.setAttribute('aria-busy','true');controls.forEach(control=>control.disabled=true);message.textContent='Enregistrement…';
    try {const response=await fetch(form.getAttribute('action'),{method:'POST',body,headers:{Accept:'application/json'}});if(response.redirected || !response.headers.get('content-type')?.includes('application/json')) throw new Error('Session expirée. Rechargez la page.');const data=await response.json();if(!response.ok) throw new Error(data.error||'Enregistrement impossible.');message.textContent='Suivi enregistré pour les projets utilisant ce dépôt.';window.SillageDialogs.commit(form);}
    catch(error){message.textContent=error.message;}finally{controls.forEach(control=>control.disabled=false);form.setAttribute('aria-busy','false');}
  });
  // The ticket handler also owns recovery there; this handler covers the repository screen only.
  document.addEventListener('click',async event=>{
    if(document.getElementById('development')) return;const button=event.target.closest('[data-git-recover]');if(!button) return;button.disabled=true;
    const message=button.closest('[data-git-sync-status]').querySelector('[data-git-recover-message]');
    try {const response=await fetch('/connections/'+button.dataset.gitRecover+'/recover',{method:'POST',body:new URLSearchParams({_csrf:csrfToken,project_id:button.dataset.project}),headers:{Accept:'application/json'}});if(response.redirected || !response.headers.get('content-type')?.includes('application/json')) throw new Error('Session expirée. Rechargez la page.');const data=await response.json();if(!response.ok) throw new Error(data.error||'Synchronisation indisponible.');message.textContent=data.status.worker?.active?'Rattrapage enregistré. Consultez le ticket ou actualisez cette vue.':'Rattrapage enregistré. Démarrez le service de synchronisation sur le serveur.';}
    catch(error){message.textContent=error.message;}finally{button.disabled=false;}
  });
})();
