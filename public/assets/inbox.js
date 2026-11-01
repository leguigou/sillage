'use strict';
let inboxChecking=false;
async function refreshInboxCount() {
  if(inboxChecking || document.hidden || !$('[data-inbox-count]'))return;
  inboxChecking=true;
  try { const response=await fetch('/notifications/count',{headers:{Accept:'application/json'},cache:'no-store'});if(response.ok && !response.redirected && (response.headers.get('content-type')||'').includes('application/json'))updateInboxCount((await response.json()).unread); }
  catch {} finally {inboxChecking=false;}
}
setInterval(refreshInboxCount,45000);
window.addEventListener('focus',refreshInboxCount);
function updateInboxCount(count) {
  $$('[data-inbox-count]').forEach(el=>{el.textContent=String(count);if(el.classList.contains('inbox-badge'))el.hidden=count===0;});
}
async function refreshUnreadInbox() {
  if(location.pathname!=='/notifications' || new URLSearchParams(location.search).get('unread')!=='1')return;
  const response=await fetch(location.pathname+location.search,{headers:{Accept:'text/html'},cache:'no-store'});
  if(!response.ok || response.redirected)throw new Error('État enregistré. Actualisez la boîte pour mettre à jour le filtre.');
  const next=new DOMParser().parseFromString(await response.text(),'text/html').querySelector('[data-inbox]');
  const current=$('[data-inbox]');if(!next || !current)throw new Error('État enregistré. Actualisez la boîte.');
  const position={left:scrollX,top:scrollY,behavior:'instant'};current.replaceChildren(...next.childNodes);window.scrollTo(position);
}
document.addEventListener('submit',async event=>{
  const form=event.target;
  if(!form.matches('[data-ticket-follow],[data-inbox-state],[data-inbox-all]')) return;
  event.preventDefault();const button=$('button',form);if(button.disabled)return;
  button.disabled=true;
  try {
    const response=await fetch(form.getAttribute('action'),{method:'POST',body:new FormData(form),headers:{Accept:'application/json'}});
    if(response.redirected || !(response.headers.get('content-type')||'').includes('application/json'))throw new Error('Votre session a expiré. Rechargez la page.');
    const data=await response.json();if(!response.ok)throw new Error(data.error||'Modification impossible.');
    if(form.matches('[data-ticket-follow]')) {
      $('input[name="action"]',form).value=data.following?'unfollow':'follow';
      $('[data-follow-label]',form).textContent=data.following?'Ne plus suivre':'Suivre ce ticket';
      $('[data-follow-count]',form).textContent=`${data.count} personne(s) suivent ce ticket`;
      button.setAttribute('aria-pressed',String(data.following));toast(data.following?'Vous suivez ce ticket.':'Vous ne suivez plus ce ticket.');
    } else {
      updateInboxCount(data.unread);
      if(form.matches('[data-inbox-all]')) {
        $$('[data-inbox-state]').forEach(item=>{$('input[name="action"]',item).value='unread';$('button',item).textContent='Marquer non lu';item.closest('[data-inbox-id]').classList.remove('is-unread');});
        toast('Toutes vos notifications sont marquées comme lues.');
      } else {
        $('input[name="action"]',form).value=data.read?'unread':'read';button.textContent=data.read?'Marquer non lu':'Marquer comme lu';form.closest('[data-inbox-id]').classList.toggle('is-unread',!data.read);
      }
      const all=$('[data-inbox-all] button');if(all)all.disabled=data.unread===0;
      await refreshUnreadInbox();
    }
  } catch(error){toast(error.message,true);}
  finally { if(!form.matches('[data-inbox-all]'))button.disabled=false;else button.disabled=Number($('[data-inbox-count]')?.textContent||0)===0; }
});
