(() => {
  'use strict';
  const root=document.querySelector('[data-ticket-detail]');
  if(!root) return;
  const nav=root.querySelector('[data-ticket-tabs]');
  const tabs=[...nav.querySelectorAll('[data-ticket-tab]')];
  const panels=[...root.querySelectorAll('[data-ticket-panel]')];
  nav.setAttribute('role','tablist');
  tabs.forEach(tab=>{tab.setAttribute('role','tab');tab.setAttribute('aria-controls',tab.dataset.ticketTab);});
  panels.forEach(panel=>panel.setAttribute('role','tabpanel'));
  function select(id,updateHash=false) {
    if(!tabs.some(tab=>tab.dataset.ticketTab===id)) return;
    panels.forEach(panel=>panel.hidden=panel.id!==id);
    tabs.forEach(tab=>{
      const active=tab.dataset.ticketTab===id;
      tab.setAttribute('aria-selected',String(active));tab.tabIndex=active?0:-1;
    });
    if(updateHash) history.replaceState(null,'','#'+id);
  }
  function fromHash() {
    const target=document.getElementById(location.hash.slice(1));
    const panel=target?.closest('[data-ticket-panel]');
    select(panel?.id||'ticket-overview');
    if(target?.matches('details')) target.open=true;
    if(panel) requestAnimationFrame(()=>target.scrollIntoView({block:'start',behavior:'instant'}));
  }
  const navigation=root.querySelector('.ticket-navigation');
  const measure=()=>root.style.setProperty('--ticket-navigation-height',navigation.offsetHeight+'px');
  new ResizeObserver(measure).observe(navigation);measure();
  tabs.forEach((tab,index)=>{
    tab.addEventListener('click',event=>{event.preventDefault();select(tab.dataset.ticketTab,true);document.getElementById(tab.dataset.ticketTab).scrollIntoView({block:'start',behavior:'instant'});});
    tab.addEventListener('keydown',event=>{
      let next;
      if(event.key==='ArrowRight') next=(index+1)%tabs.length;
      else if(event.key==='ArrowLeft') next=(index+tabs.length-1)%tabs.length;
      else if(event.key==='Home') next=0;
      else if(event.key==='End') next=tabs.length-1;
      else return;
      event.preventDefault();select(tabs[next].dataset.ticketTab,true);tabs[next].focus();
    });
  });
  root.querySelector('[data-ticket-comment]')?.addEventListener('click',event=>{
    event.preventDefault();
    const dialog=document.getElementById('ticket-comment-dialog');
    if(!dialog) return;
    dialog.showModal();
    const composer=dialog.querySelector('[data-composer]');
    const editor=composer?.classList.contains('enhanced')?composer.querySelector('.conversation-editor'):composer?.querySelector('textarea');
    editor?.focus({preventScroll:true});
  });
  window.addEventListener('hashchange',fromHash);fromHash();
})();
