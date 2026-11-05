/* Settings save in place. Only the submitted panel is refreshed; other drafts stay intact. */
(() => {
  'use strict';
  if (location.pathname !== '/settings') return;
  const settingsPage=document.querySelector('[data-settings-page]');
  const tablist=settingsPage?.querySelector('[data-settings-tabs]');
  const panelHost=settingsPage?.querySelector('[data-settings-panels]');
  if(tablist && panelHost) {
    // The navigation owns the groups; move existing nodes to preserve drafts and handlers.
    const tabs=[...tablist.querySelectorAll('a[href^="#"]')];
    const panels=new Map();
    tablist.setAttribute('role','tablist');
    tabs.forEach(tab=>{
      const id=tab.hash.slice(1), section=document.getElementById(id);
      if(!section) return;
      const panel=document.createElement('div');
      panel.id='settings-panel-'+id;panel.className='settings-tab-panel';panel.dataset.settingsPanel=id;
      panel.setAttribute('role','tabpanel');panel.setAttribute('tabindex','0');panel.hidden=true;
      tab.id='settings-tab-'+id;tab.setAttribute('role','tab');tab.setAttribute('aria-controls',panel.id);
      panel.setAttribute('aria-labelledby',tab.id);
      panel.append(section);
      for(const extraId of (tab.dataset.settingsExtra||'').split(' ').filter(Boolean)) {
        const extra=document.getElementById(extraId);
        if(extra) panel.append(extra);
      }
      panelHost.append(panel);panels.set(id,panel);
    });
    const available=tabs.filter(tab=>panels.has(tab.hash.slice(1)));
    function select(id) {
      if(!panels.has(id)) return;
      panels.forEach((panel,key)=>panel.hidden=key!==id);
      available.forEach(tab=>{
        const active=tab.hash==='#'+id;
        tab.setAttribute('aria-selected',String(active));tab.tabIndex=active?0:-1;
      });
    }
    function fromHash() {
      let target;
      try {target=document.getElementById(decodeURIComponent(location.hash.slice(1)));} catch {}
      const id=target?.closest('[data-settings-panel]')?.dataset.settingsPanel;
      select(id||'profile');
    }
    available.forEach((tab,index)=>{
      function open() {
        select(tab.hash.slice(1));
        if(location.hash!==tab.hash) history.pushState(null,'',tab.hash);
        tablist.scrollIntoView({block:'start',behavior:'instant'});
      }
      tab.addEventListener('click',event=>{
        if(event.button!==0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        event.preventDefault();open();
      });
      tab.addEventListener('keydown',event=>{
        let next;
        if(event.key==='ArrowRight') next=(index+1)%available.length;
        else if(event.key==='ArrowLeft') next=(index+available.length-1)%available.length;
        else if(event.key==='Home') next=0;
        else if(event.key==='End') next=available.length-1;
        else return;
        event.preventDefault();available[next].click();available[next].focus({preventScroll:true});
      });
    });
    const grid=settingsPage.querySelector('.settings-grid');
    if(grid && !grid.children.length) grid.remove();
    settingsPage.classList.add('settings-tabs-ready');
    window.addEventListener('hashchange',fromHash);window.addEventListener('popstate',fromHash);
    fromHash();
  }
  const busy = new Set();
  const regions = new Set(['profile','company','email-preferences','notifications','mcp','oauth','teams','users','llm','appearance','workflow']);
  function updateRegion(id, page) {
    const current = document.getElementById(id), incoming = page.getElementById(id);
    if (!current || !incoming) throw new Error('La section n’est plus disponible. Rechargez les paramètres.');
    const detailKey=el=>el.dataset.settingsKey || el.querySelector('summary')?.textContent;
    const opened = [...current.querySelectorAll('details[open]')].map(detailKey);
    current.replaceChildren(...incoming.childNodes);
    current.querySelectorAll('details').forEach(el => {
      if (opened.includes(detailKey(el))) el.open = true;
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
      for (const selector of ['.sidebar-heading .wordmark','.app-footer']) {
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
    if(id==='workflow') {
      const vocabulary=JSON.parse(document.getElementById('workflow-data').textContent);
      for(const kind of ['status','type']) {
        document.querySelectorAll(`#ticket-dialog select[name="${kind}"]`).forEach(select=>{
          const selected=select.value;
          select.replaceChildren(...Object.entries(vocabulary[kind]).map(([value,label])=>new Option(label,value,false,value===selected)));
        });
      }
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
    // A successful control named "action" shadows HTMLFormElement.action.
    const action=new URL(form.getAttribute('action') || location.href,location.href);
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
