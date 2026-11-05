(() => {
  'use strict';
  const dialog=document.getElementById('ticket-people-dialog');if(!dialog) return;
  const form=dialog.querySelector('form'),fields=dialog.querySelector('[data-people-fields]'),message=dialog.querySelector('[data-people-message]');
  let ticket=null,request=null;
  async function read(response) {if(response.redirected || !response.headers.get('content-type')?.includes('application/json')) throw new Error('Votre session a expiré. Rechargez la page.');const data=await response.json();if(!response.ok) throw new Error(data.error||'Impossible de charger les personnes.');return data;}
  document.addEventListener('click',async event=>{
    const button=event.target.closest('[data-ticket-people]');if(!button) return;
    ticket=button.dataset.ticketPeople;fields.replaceChildren();message.textContent='Chargement…';window.SillageDialogs.show(dialog);form.setAttribute('aria-busy','true');request=new AbortController();
    try {const data=await read(await fetch('/tickets/'+ticket+'/people',{headers:{Accept:'application/json'},cache:'no-store',signal:request.signal}));fields.innerHTML=data.html;dialog.querySelector('[data-people-title]').textContent='Affectations · '+data.key;message.textContent='';window.SillageDialogs.commit(dialog);}
    catch(error){if(error.name!=='AbortError') message.textContent=error.message;}
    finally{form.removeAttribute('aria-busy');}
  });
  fields.addEventListener('input',event=>{if(!event.target.matches('[data-people-search]')) return;const query=event.target.value.trim().toLocaleLowerCase();fields.querySelectorAll('[data-person-search]').forEach(row=>row.hidden=!row.dataset.personSearch.includes(query));});
  form.addEventListener('submit',async event=>{
    event.preventDefault();if(form.getAttribute('aria-busy')==='true'||!ticket) return;
    const body=new FormData(form),controls=[...form.querySelectorAll('input,select,button')].filter(control=>!control.disabled);form.setAttribute('aria-busy','true');controls.forEach(control=>control.disabled=true);message.textContent='Enregistrement…';
    try {
      const data=await read(await fetch('/tickets/'+ticket+'/people',{method:'POST',headers:{Accept:'application/json'},body}));
      document.querySelectorAll('.ticket-card[data-ticket-id="'+ticket+'"]').forEach(card=>{card.dataset.version=String(data.version);const current=card.querySelector('[data-ticket-people]');const wrapper=document.createElement('div');wrapper.innerHTML=data.button;current.replaceWith(wrapper.firstElementChild);});
      window.SillageDialogs.complete(dialog);toast('Personnes et équipe enregistrées.');
    }catch(error){message.textContent=error.message;}
    finally{controls.forEach(control=>control.disabled=false);form.removeAttribute('aria-busy');}
  });
  dialog.addEventListener('close',()=>{request?.abort();ticket=null;});
})();
