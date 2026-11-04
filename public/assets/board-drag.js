/* Manual board order: one gap owns both the visual preview and the saved insertion point. */
(() => {
  'use strict';
  let drag=null,saving=false;
  function clean() {
    if(drag){drag.card.classList.remove('dragging','drag-source-hidden');drag.gap.remove();}
    document.querySelectorAll('.drop-hover').forEach(column=>column.classList.remove('drop-hover'));drag=null;
  }
  function allowed(column) {return drag && !column.classList.contains('collapsed') && column.closest('.kanban-board')===drag.board;}
  document.querySelectorAll('.ticket-card[draggable="true"]').forEach(card=>{
    card.addEventListener('dragstart',event=>{
      if(saving || event.target.closest('a,button,summary,input,select,textarea')) {event.preventDefault();return;}
      clean();const gap=document.createElement('div');gap.className='board-drop-placeholder';gap.style.height=card.getBoundingClientRect().height+'px';gap.setAttribute('aria-hidden','true');
      drag={card,gap,board:card.closest('.kanban-board'),origin:card.parentNode,next:card.nextElementSibling};
      event.dataTransfer.effectAllowed='move';event.dataTransfer.setData('text/plain',card.dataset.ticketId);card.classList.add('dragging');
      // Let the browser capture the native drag image before hiding the source card.
      requestAnimationFrame(()=>{if(drag?.card===card){card.before(gap);card.classList.add('drag-source-hidden');}});
    });
    card.addEventListener('dragend',clean);
  });
  document.querySelectorAll('[data-drop-status]').forEach(column=>{
    column.addEventListener('dragover',event=>{
      if(!allowed(column)) return;
      event.preventDefault();event.dataTransfer.dropEffect='move';
      document.querySelectorAll('.drop-hover').forEach(other=>{if(other!==column) other.classList.remove('drop-hover');});column.classList.add('drop-hover');
      const container=column.querySelector('.column-cards'),rect=container.getBoundingClientRect();
      if(event.clientY<rect.top+30) container.scrollTop-=10;else if(event.clientY>rect.bottom-30) container.scrollTop+=10;
      const before=[...container.querySelectorAll('.ticket-card')].find(card=>card!==drag.card && card.getClientRects().length && event.clientY<card.getBoundingClientRect().top+card.getBoundingClientRect().height/2);
      if(drag.gap.parentNode!==container || drag.gap.nextElementSibling!==(before||null)) container.insertBefore(drag.gap,before||null);
    });
    column.addEventListener('dragleave',event=>{if(!column.contains(event.relatedTarget)) column.classList.remove('drop-hover');});
    column.addEventListener('drop',async event=>{
      if(!allowed(column) || drag.gap.parentNode!==column.querySelector('.column-cards')) return;
      event.preventDefault();const current=drag,{card,gap,origin,next}=current;
      const before=gap.nextElementSibling;
      if(gap.parentNode===origin && before===next) {clean();return;}
      gap.before(card);clean();saving=true;card.classList.add('board-saving');
      try {
        await post('/tickets/'+card.dataset.ticketId+'/move',{status:column.dataset.dropStatus,before_id:before?.dataset.ticketId||'',version:card.dataset.version});
        sessionStorage.setItem('sillage.notice','Ordre du tableau enregistré.');location.reload();
      } catch(error) {
        origin.insertBefore(card,next?.parentNode===origin?next:null);toast(error.message,true);
      } finally {saving=false;card.classList.remove('board-saving');}
    });
  });
  window.addEventListener('pagehide',clean);
})()
