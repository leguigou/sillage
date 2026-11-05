(() => {
  'use strict';
  window.refreshTicketHistory=()=>{
    let previous='';
    document.querySelectorAll('#history [data-history-event]').forEach(entry=>{
      const time=entry.querySelector('time'),date=new Date(time.dateTime),heading=entry.querySelector('[data-history-day]');
      if(Number.isNaN(date.getTime())) return;
      const key=[date.getFullYear(),date.getMonth(),date.getDate()].join('-');heading.hidden=key===previous;previous=key;
      heading.textContent=date.toLocaleDateString('fr-FR',{weekday:'long',day:'numeric',month:'long',year:'numeric'});
      const display=ticketModifiedLabel(time.dateTime);time.textContent=display.label+' à '+date.toLocaleTimeString('fr-FR',{hour:'2-digit',minute:'2-digit'});time.title=display.title;
    });
  };
  window.refreshTicketHistory();
})()
