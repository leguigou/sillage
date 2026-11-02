(() => {
  'use strict';
  let busy=false;
  document.addEventListener('submit',async event=>{
    const form=event.target;
    if(!form.matches('[data-tracking-form]') || event.defaultPrevented) return;
    event.preventDefault();
    if(busy) return;
    if(event.submitter?.dataset.trackingConfirm && !confirm(event.submitter.dataset.trackingConfirm)) return;
    const body=new FormData(form);
    if(event.submitter?.name) body.set(event.submitter.name,event.submitter.value);
    const buttons=[...form.querySelectorAll('button')].filter(button=>!button.disabled);
    busy=true;form.setAttribute('aria-busy','true');buttons.forEach(button=>button.disabled=true);
    let saved=false;
    try {
      const response=await fetch(form.getAttribute('action'),{method:'POST',body,headers:{Accept:'application/json'}});
      if(response.redirected || !(response.headers.get('content-type')||'').includes('application/json')) throw new Error('Votre session a expiré. Reconnectez-vous.');
      const result=await response.json();
      if(!response.ok) throw new Error(result.error||'Enregistrement impossible.');
      saved=true;
      const refreshUrl=new URL(location.href);
      if(result.release) {refreshUrl.searchParams.set('release',result.release.id);refreshUrl.searchParams.delete('delivery_offset');refreshUrl.searchParams.set('archived',result.release.archived?'1':'0');}
      const refresh=await fetch(refreshUrl,{headers:{Accept:'text/html'},cache:'no-store'});
      const page=new DOMParser().parseFromString(await refresh.text(),'text/html');
      const top=window.scrollY;
      if(result.ticket) {
        if(!page.querySelector('[data-ticket-detail]')) throw new Error('Affichage indisponible.');
        document.querySelectorAll('[data-ticket-live]').forEach(current=>{
          const incoming=page.getElementById(current.id);
          if(incoming) current.replaceChildren(...incoming.childNodes);
        });
        document.querySelectorAll('input[name="version"]').forEach(input=>input.value=result.ticket.version);
        initAppearanceMarks();
      } else {
        const current=document.querySelector('[data-release-space]'),incoming=page.querySelector('[data-release-space]');
        if(!current || !incoming) throw new Error('Affichage indisponible.');
        current.replaceChildren(...incoming.childNodes);history.replaceState(null,'',refreshUrl);
      }
      window.scrollTo({top,behavior:'instant'});toast('Enregistré.');
    } catch(error) {toast(saved?'Enregistrement effectué ; rechargez la page pour actualiser l’affichage.':error.message||'Connexion interrompue. Vérifiez l’état avant de réessayer.',true);}
    finally {busy=false;form.removeAttribute('aria-busy');buttons.forEach(button=>button.disabled=false);}
  });
})();
