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
    root.classList.toggle('git-development-wide',id==='development');
    tabs.forEach(tab=>{
      const active=tab.dataset.ticketTab===id;
      tab.setAttribute('aria-selected',String(active));tab.tabIndex=active?0:-1;
    });
    if(updateHash) history.replaceState(null,'','#'+id);
  }
  function fromHash() {
    const target=document.getElementById(location.hash.slice(1));
    const panel=target?.closest('[data-ticket-panel]');
    if(['ticket-edit-dialog','ticket-comment-dialog'].includes(target?.id)) window.SillageDialogs.show(target);
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
  const commentDialog=document.getElementById('ticket-comment-dialog');
  let commentMode='new';
  // Prepares the shared comment dialog as a new comment (source=null), a reply to a comment article, or (edit=true)
  // the edition of the user's own message, prefilled with its current content and posted to /comments/{id}.
  function openComment(source,edit=false) {
    if(!commentDialog) return;
    const form=commentDialog.querySelector('form');
    const editor=commentDialog.querySelector('.conversation-editor'),textarea=commentDialog.querySelector('[data-comment-source]');
    const context=commentDialog.querySelector('[data-comment-reply-context]');
    const title=commentDialog.querySelector('#ticket-comment-title');
    title.dataset.defaultTitle??=title.textContent;
    form.dataset.defaultAction??=form.getAttribute('action');
    const content=source?.querySelector(':scope > .comment-body > .conversation-content');
    if(edit) {
      form.setAttribute('action',form.dataset.defaultAction+'/'+source.dataset.commentId);
      if(editor) editor.innerHTML=content?.innerHTML||'';
      if(textarea) textarea.value=content?.innerText||'';
    } else {
      form.setAttribute('action',form.dataset.defaultAction);
      if(commentMode==='edit') {if(editor) editor.innerHTML='';if(textarea) textarea.value='';}
    }
    commentMode=edit?'edit':'new';
    commentDialog.querySelector('[data-comment-parent]').value=source && !edit?source.dataset.commentId:'';
    context.hidden=!source || edit;
    if(source && !edit) {
      const author=source.querySelector('[data-comment-author]')?.textContent||'';
      const text=(content?.textContent||'').replace(/\s+/g,' ').trim();
      context.querySelector('[data-comment-reply-author]').textContent=author;
      context.querySelector('[data-comment-reply-excerpt]').textContent=text.length>160?text.slice(0,160)+'…':text;
    }
    title.textContent=edit?'Modifier mon message':source?'Répondre à '+(source.querySelector('[data-comment-author]')?.textContent||'un commentaire'):title.dataset.defaultTitle;
    commentDialog.querySelector('[data-comment-submit]').textContent=edit?'Enregistrer la modification':source?'Publier la réponse':'Ajouter un commentaire';
    window.SillageDialogs.show(commentDialog);
  }
  root.querySelector('[data-ticket-comment]')?.addEventListener('click',event=>{event.preventDefault();openComment(null);});
  document.addEventListener('click',event=>{
    const button=event.target.closest('[data-comment-reply],[data-comment-edit]');
    if(!button) return;
    event.preventDefault();openComment(button.closest('[data-comment-id]'),button.hasAttribute('data-comment-edit'));
  });
  window.addEventListener('hashchange',fromHash);fromHash();
})();
