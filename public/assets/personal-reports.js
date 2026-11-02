(() => {
  'use strict';
  const dialog=document.getElementById('save-view-dialog'),form=dialog?.querySelector('[data-personal-report]');if(!form) return;
  const columns=form.querySelector('[data-report-columns]'),order=form.querySelector('[data-report-order]'),status=form.querySelector('[data-report-status]');
  const open=form.querySelector('[data-report-open]'),copy=form.querySelector('[data-report-copy]'),remove=form.querySelector('[data-report-delete]');
  let busy=false;
  const rows=()=>[...columns.querySelectorAll('[data-report-column]')];
  function recordOrder(){order.value=JSON.stringify(rows().map(node=>node.dataset.reportColumn));}
  function arrange(keys){keys.forEach(key=>{const node=rows().find(row=>row.dataset.reportColumn===key);if(node) columns.append(node);});}
  columns.addEventListener('click',event=>{
    const button=event.target.closest('[data-report-move]');if(!button) return;
    const row=button.closest('[data-report-column]'),sibling=button.dataset.reportMove==='up'?row.previousElementSibling:row.nextElementSibling;
    if(sibling) {if(button.dataset.reportMove==='up') columns.insertBefore(row,sibling);else columns.insertBefore(sibling,row);recordOrder();button.focus({preventScroll:true});}
  });
  form.querySelector('[data-required-column]')?.addEventListener('change',event=>{event.target.checked=true;});
  dialog.addEventListener('sillage:dialog-discarded',()=>{arrange(JSON.parse(order.value));});
  document.addEventListener('click',event=>{
    if(!event.target.closest('[data-open="save-view-dialog"]') || dialog.open) return;
    const headers=[...document.querySelectorAll('.ticket-table th[data-column]')];
    if(headers.length) {
      arrange(headers.map(node=>node.dataset.column));
      for(const row of rows()) {const header=headers.find(node=>node.dataset.column===row.dataset.reportColumn);row.querySelector('input').checked=row.dataset.reportColumn==='title'||!header?.hidden;}
      const progress=document.querySelector('[data-column-toggle="progress_percent"]');if(progress) form.elements.show_progress.checked=progress.checked;
      recordOrder();
    }
  },true);
  function lock(value){busy=value;form.setAttribute('aria-busy',String(value));form.querySelectorAll('button,input,select,textarea').forEach(node=>node.disabled=value);}
  async function request(url,body){const response=await fetch(url,{method:'POST',body,headers:{Accept:'application/json'}});if(response.redirected || !response.headers.get('content-type')?.includes('application/json')) throw new Error('Votre session a expiré. Rechargez la page.');const data=await response.json();if(!response.ok) throw new Error(data.error||'Enregistrement impossible.');return data;}
  async function saveReport(mode) {
    if(busy) return;recordOrder();const body=new FormData(form);body.set('mode',mode);lock(true);status.textContent='Enregistrement…';
    try {
      const result=await request('/views',body);form.elements.view_id.value=result.id;form.elements.revision.value=result.revision;form.elements.name.value=result.name;
      open.hidden=copy.hidden=remove.hidden=false;open.href=result.url;
      const links=document.querySelector('[data-personal-reports]');let link=links?.querySelector('[data-personal-report-link="'+result.id+'"]');
      if(links && !link){link=document.createElement('a');link.className='saved-view';link.dataset.personalReportLink=result.id;links.insertBefore(link,links.querySelector('button'));}
      if(link){link.href=result.url;link.textContent=result.name;}
      status.textContent='Rapport enregistré dans votre compte. « Afficher ce rapport » applique ses filtres et sa présentation.';window.SillageDialogs.commit(dialog);
    }catch(error){status.textContent=error.message;}
    finally{lock(false);}
  }
  form.addEventListener('submit',event=>{event.preventDefault();saveReport('save');});
  copy.addEventListener('click',()=>{if(form.reportValidity()) saveReport('copy');});
  remove.addEventListener('click',async()=>{
    if(busy || !form.elements.view_id.value || form.elements.view_id.value==='0') return;
    if(!await window.SillageDialogs.confirm({title:'Supprimer mon rapport ?',message:'Le rapport personnel « '+form.elements.name.value+' » sera supprimé. Les tickets sont conservés.',accept:'Supprimer le rapport',danger:true})) return;
    const id=form.elements.view_id.value;lock(true);
    try {await request('/views/'+id+'/delete',new URLSearchParams({_csrf:csrfToken}));document.querySelector('[data-personal-report-link="'+id+'"]')?.remove();form.elements.view_id.value='0';form.elements.revision.value='';form.elements.name.value='';open.hidden=copy.hidden=remove.hidden=true;status.textContent='Rapport supprimé. Vous pouvez en créer un nouveau.';window.SillageDialogs.commit(dialog);}
    catch(error){status.textContent=error.message;}
    finally{lock(false);}
  });
})();
