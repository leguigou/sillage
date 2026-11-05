(() => {
  'use strict';
  document.querySelectorAll('[data-git-test-access]').forEach(button=>button.addEventListener('click',async()=>{
    const form=button.closest('form'),results=form.querySelector('[data-git-access-results]');
    if(form.getAttribute('aria-busy')==='true') return;
    const body=new FormData(form),controls=[...form.querySelectorAll('input,button,select,textarea')].filter(control=>!control.disabled);
    form.setAttribute('aria-busy','true');controls.forEach(control=>control.disabled=true);results.textContent='Vérification des quatre accès…';
    try {
      const response=await fetch('/connections/'+button.dataset.gitTestAccess+'/test-access',{method:'POST',headers:{Accept:'application/json'},body});
      if(response.redirected || !response.headers.get('content-type')?.includes('application/json')) throw new Error('Votre session a expiré. Rechargez la page.');
      const data=await response.json();if(!response.ok) throw new Error(data.error||'Test indisponible.');
      results.replaceChildren();
      const heading=document.createElement('p');heading.className='help-text';heading.textContent=data.credential==='draft'?'Nouveau jeton testé, sans enregistrement.':'Jeton enregistré testé.';results.append(heading);
      data.checks.forEach(check=>{
        const row=document.createElement('div');row.className='git-access-check '+(check.ok?'is-success':'is-error');
        const title=document.createElement('strong');title.textContent=check.label+' · '+(check.ok?'Accès confirmé':check.http_status?'HTTP '+check.http_status:'Échec');row.append(title);
        if(!check.ok) {const detail=document.createElement('p');detail.textContent=check.message;const permission=document.createElement('p');permission.textContent='Permission à vérifier : '+check.permission;row.append(detail,permission);}
        results.append(row);
      });
      if(/^[a-f0-9]{32}$/.test(data.request_id)) {const link=document.createElement('a');link.href='/settings?log_request='+data.request_id+'#technical-logs';link.textContent='Voir les appels de ce test';results.append(link);}
    }catch(error){results.textContent=error.message;}
    finally{controls.forEach(control=>control.disabled=false);form.removeAttribute('aria-busy');}
  }));
  document.querySelectorAll('[data-webhook-reveal]').forEach(button=>{
    const section=button.closest('details'),dialog=button.closest('dialog'),group=section.querySelector('[data-webhook-secret-group]'),input=group.querySelector('input'),status=section.querySelector('[data-webhook-status]');
    let generation=0;
    button.addEventListener('click',async()=>{
      const requestGeneration=++generation;
      button.disabled=true;status.textContent='Chargement du secret…';
      try {
        const response=await fetch('/connections/'+button.dataset.webhookReveal+'/webhook',{method:'POST',headers:{Accept:'application/json'},body:new URLSearchParams({_csrf:csrfToken})});
        if(response.redirected || !response.headers.get('content-type')?.includes('application/json')) throw new Error('Votre session a expiré. Rechargez la page.');
        const data=await response.json();if(!response.ok) throw new Error(data.error||'Accès refusé.');
        if(!dialog.open || requestGeneration!==generation) return;
        input.value=data.secret;group.hidden=false;button.hidden=true;status.textContent='Utilisez Copier pour coller ce secret dans le fournisseur. Il sera masqué à la fermeture.';
      }catch(error){if(requestGeneration===generation) status.textContent=error.message;}
      finally{if(requestGeneration===generation) button.disabled=false;}
    });
    const clear=()=>{generation++;input.value='';group.hidden=true;button.hidden=false;button.disabled=false;status.textContent='';};
    dialog.addEventListener('close',clear);window.addEventListener('pagehide',clear);
  });
})();
