/* Settings save in place. Only the submitted panel is refreshed; other drafts stay intact. */
(() => {
  'use strict';
  if (location.pathname !== '/settings') return;
  const busy = new Set();
  const regions = new Set(['profile','company','email-preferences','notifications','mcp','oauth','teams','users','llm','appearance']);
  function updateRegion(id, page) {
    const current = document.getElementById(id), incoming = page.getElementById(id);
    if (!current || !incoming) throw new Error('La section n’est plus disponible. Rechargez les paramètres.');
    const opened = [...current.querySelectorAll('details[open]')].map(el => el.querySelector('summary')?.textContent);
    current.replaceChildren(...incoming.childNodes);
    current.querySelectorAll('details').forEach(el => {
      if (opened.includes(el.querySelector('summary')?.textContent)) el.open = true;
    });
    initSettingsPreviews(current);
    initAppearanceMarks(current);
    initAppearancePickers(current);
  }
  function apply(id, page) {
    if (!page.querySelector('.settings-shortcuts')) throw new Error('Votre session a expiré. Reconnectez-vous avant de réessayer.');
    const position = {left:window.scrollX, top:window.scrollY, behavior:'instant'};
    updateRegion(id,page);
    if (id==='company') {
      updateRegion('company-summary',page);
      for (const selector of ['.company-badge','.app-footer']) {
        const current=document.querySelector(selector), next=page.querySelector(selector);
        if(current && next) current.replaceWith(next);
      }
    }
    if (id==='profile' || id==='users') {
      updateRegion('directory',page);
      const current=document.querySelector('.profile-row'), next=page.querySelector('.profile-row');
      if(current && next) current.replaceWith(next);
    }
    if(id==='llm') {
      document.getElementById('llm-profile-data').textContent=page.getElementById('llm-profile-data').textContent;
      // Replace recipient information and identity together before binding the assistant again.
      const assistant=document.getElementById('llm-assistant-dialog'), next=page.getElementById('llm-assistant-dialog');
      assistant?.remove();
      if(next) document.body.append(next);
      const opener=document.querySelector('[data-llm-open]'), nextOpener=page.querySelector('[data-llm-open]');
      if(opener) { if(nextOpener) opener.replaceWith(nextOpener); else opener.remove(); }
      else if(nextOpener) document.querySelector('.topbar-actions').prepend(nextOpener);
    }
    document.dispatchEvent(new CustomEvent('sillage:settings-updated',{detail:{id}}));
    window.scrollTo(position);
  }
  async function documentResponse(response) {
    if ((response.headers.get('content-type')||'').includes('application/json')) {
      const data=await response.json();
      throw new Error(data.error || 'Réponse serveur inattendue. Rechargez les paramètres.');
    }
    if(!response.ok) throw new Error(response.status===403?'Accès refusé ou session expirée. Rechargez les paramètres.':'Le serveur n’a pas pu enregistrer cette modification.');
    return new DOMParser().parseFromString(await response.text(),'text/html');
  }
  async function refresh(id) {
    const response=await fetch(location.pathname+location.search,{headers:{Accept:'text/html'},cache:'no-store'});
    apply(id,await documentResponse(response));
  }
  window.SillageSettings={refresh};
  document.addEventListener('submit',async event=>{
    const form=event.target, region=form.closest('section.panel[id]');
    if(event.defaultPrevented || form.method.toLowerCase()!=='post' || !region || !regions.has(region.id)) return;
    const action=new URL(form.action,location.href);
    if(action.origin!==location.origin) return;
    event.preventDefault();
    if(busy.has(region.id)) return;
    const id=region.id, data=new FormData(form);
    if(event.submitter?.name) data.append(event.submitter.name,event.submitter.value);
    const buttons=[...form.querySelectorAll('button')].filter(button=>!button.disabled);
    busy.add(id); region.setAttribute('aria-busy','true'); buttons.forEach(button=>button.disabled=true);
    try {
      const response=await fetch(action,{method:'POST',body:data,headers:{Accept:'application/json'},cache:'no-store'});
      const page=await documentResponse(response);
      apply(id,page);
      const notices=[...page.querySelectorAll('.main-content > .notice')];
      if(notices.length) notices.forEach(notice=>toast(notice.textContent.trim(),notice.classList.contains('error')));
      else toast('Modification enregistrée.');
    } catch(error) { toast(error.message || 'Connexion interrompue. Vérifiez l’état avant de réessayer.',true); }
    finally { busy.delete(id); region.removeAttribute('aria-busy'); buttons.forEach(button=>button.disabled=false); }
  });
})();
