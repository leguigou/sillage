/* Per-tab view positions. Ticket URLs carry the return URL; storage carries only scroll positions. */
(() => {
  'use strict';
  const key='sillage.view.positions.v1';
  const selectors='.timeline-panel,.kanban-board,.table-panel,.table-scroll';
  const current=()=>location.pathname+location.search+location.hash;
  function read() { try { const value=JSON.parse(sessionStorage.getItem(key)||'{}');return value && typeof value==='object' && !Array.isArray(value)?Object.fromEntries(Object.entries(value).filter(([,state])=>state && typeof state==='object' && Number.isFinite(state.time) && Array.isArray(state.panels))):{}; } catch { return {}; } }
  function save(destinations) {
    const state={time:Date.now(),x:window.scrollX,y:window.scrollY,panels:[...document.querySelectorAll(selectors)].slice(0,32).map(el=>({x:el.scrollLeft,y:el.scrollTop}))};
    const states=read();destinations.forEach(url=>{states[url]=state;});
    const entries=Object.entries(states).sort((a,b)=>b[1].time-a[1].time).slice(0,24);
    try { sessionStorage.setItem(key,JSON.stringify(Object.fromEntries(entries))); } catch {}
  }
  function leaving(event) {
    if(event.defaultPrevented) return;
    const link=event.target.closest('a[href]');if(!link) return;
    const target=new URL(link.href,location.href);
    if(target.origin!==location.origin || !/^\/tickets\/[1-9][0-9]*$/.test(target.pathname)) return;
    let destination=target.searchParams.get('return');
    if(!destination) return;
    // Fragments never reach PHP. Add the visible section to the already validated return URL.
    if(!document.querySelector('[data-ticket-detail]') && location.hash && !destination.includes('#')) {
      destination+=location.hash;target.searchParams.set('return',destination);link.href=target.pathname+target.search+target.hash;
    }
    if(!document.querySelector('[data-ticket-detail]')) save([current(),destination]);
  }
  document.addEventListener('click',leaving);
  document.addEventListener('auxclick',leaving);
  document.addEventListener('contextmenu',leaving);
  if(document.querySelector('[data-ticket-detail]')) return;
  let interacted=false;
  ['pointerdown','wheel','keydown'].forEach(name=>window.addEventListener(name,()=>{interacted=true;},{once:true,passive:true}));
  function restore(event) {
    if(event?.persisted) return; // A back-forward cached page already owns its live position.
    const state=read()[current()];if(!state || !Array.isArray(state.panels)) return;
    const coordinate=value=>Number.isFinite(value)&&value>=0?value:0;
    const apply=()=>{
      if(interacted) return;
      [...document.querySelectorAll(selectors)].slice(0,32).forEach((el,index)=>{
        const panel=state.panels[index];if(panel){el.scrollLeft=coordinate(panel.x);el.scrollTop=coordinate(panel.y);}
      });
      window.scrollTo({left:coordinate(state.x),top:coordinate(state.y),behavior:'instant'});
    };
    requestAnimationFrame(()=>{apply();requestAnimationFrame(apply);});
  }
  window.addEventListener('pageshow',restore);
})();
