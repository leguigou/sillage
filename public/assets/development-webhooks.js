(() => {
  'use strict';
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
