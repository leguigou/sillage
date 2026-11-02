/* Owns modal opening, pending drafts, dismissal and in-app confirmations. */
(() => {
  'use strict';
  const states=new WeakMap(),known=new WeakSet(),pending=new WeakSet();
  const controls=dialog=>[...dialog.querySelectorAll('input,textarea,select,[contenteditable="true"]')].filter(el=>!el.readOnly && !['hidden','submit','button','reset','image'].includes(el.type) && !el.matches('[data-dialog-ignore]'));
  function value(el) {
    if(el.type==='file') return [...el.files];
    if(el.isContentEditable) return el.innerHTML;
    if(['checkbox','radio'].includes(el.type)) return el.checked;
    if(el.tagName==='SELECT' && el.multiple) return [...el.selectedOptions].map(option=>option.value);
    return el.value;
  }
  const equal=(a,b)=>JSON.stringify(a,(key,item)=>item instanceof File?[item.name,item.size,item.type,item.lastModified]:item)===JSON.stringify(b,(key,item)=>item instanceof File?[item.name,item.size,item.type,item.lastModified]:item);
  function commit(dialog) {if(dialog) states.set(dialog,new Map(controls(dialog).map(el=>[el,value(el)])));}
  function changed(dialog) {
    const baseline=states.get(dialog);if(!baseline) return false;
    return controls(dialog).some(el=>baseline.has(el) && !equal(value(el),baseline.get(el)));
  }
  function restore(dialog) {
    for(const [el,initial] of states.get(dialog)||[]) {
      if(!el.isConnected) continue;
      if(el.type==='file') {const files=new DataTransfer();initial.forEach(file=>files.items.add(file));el.files=files.files;}
      else if(el.isContentEditable) el.innerHTML=initial;
      else if(['checkbox','radio'].includes(el.type)) el.checked=initial;
      else if(el.tagName==='SELECT' && el.multiple) [...el.options].forEach(option=>option.selected=initial.includes(option.value));
      else el.value=initial;
    }
    dialog.dispatchEvent(new CustomEvent('sillage:dialog-discarded',{bubbles:true}));
  }
  function observe(dialog) {
    if(known.has(dialog)) return;known.add(dialog);
    dialog.addEventListener('cancel',event=>{event.preventDefault();requestClose(dialog);});
    dialog.addEventListener('close',()=>{states.delete(dialog);});
    if(dialog.open) commit(dialog);
  }
  function show(dialog) {
    if(typeof dialog==='string') dialog=document.getElementById(dialog);
    if(!dialog || dialog.open) return;
    observe(dialog);commit(dialog);dialog.showModal();
    const editable='input:not([type="hidden"]):not([type="file"]):not(:disabled):not([readonly]),textarea:not(:disabled):not([readonly]),select:not(:disabled)';
    const focus=[dialog.querySelector('[autofocus]'),dialog.querySelector('[contenteditable="true"]'),...dialog.querySelectorAll(editable),dialog.querySelector('button')].find(el=>el && el.getClientRects().length);
    focus?.focus({preventScroll:true});
  }
  function complete(dialog) {if(!dialog) return;states.delete(dialog);dialog.close();}
  let question=null;
  const confirmDialog=document.createElement('dialog');confirmDialog.id='confirmation-dialog';confirmDialog.className='dialog compact interaction-dialog';confirmDialog.setAttribute('aria-labelledby','confirmation-title');confirmDialog.dataset.dialogUtility='true';
  const title=document.createElement('h2');title.id='confirmation-title';
  const message=document.createElement('p');message.id='confirmation-message';confirmDialog.setAttribute('aria-describedby',message.id);
  const actions=document.createElement('div');actions.className='modal-actions';
  const cancel=document.createElement('button'),accept=document.createElement('button');cancel.type=accept.type='button';cancel.className='button subtle';accept.className='button primary';actions.append(cancel,accept);confirmDialog.append(title,message,actions);document.body.append(confirmDialog);
  function settle(ok) {
    if(!question) return;
    const current=question;question=null;confirmDialog.close();
    if(current.focus?.isConnected) current.focus.focus({preventScroll:true});
    current.resolve(ok);
  }
  function ask(options) {
    // A topmost modal makes the initiating controls inert; repeated dismissal requests are ignored.
    if(question) return Promise.resolve(false);
    title.textContent=options.title||'Confirmer l’action';message.textContent=options.message||'';
    cancel.textContent=options.cancel||'Annuler';accept.textContent=options.accept||'Confirmer';
    accept.className=options.danger?'button danger-outline':'button primary';
    return new Promise(resolve=>{question={resolve,focus:document.activeElement};confirmDialog.showModal();cancel.focus({preventScroll:true});});
  }
  cancel.addEventListener('click',()=>settle(false));accept.addEventListener('click',()=>settle(true));
  confirmDialog.addEventListener('cancel',event=>{event.preventDefault();settle(false);});
  let promptQuestion=null;
  const promptDialog=document.createElement('dialog');promptDialog.id='input-dialog';promptDialog.className='dialog compact interaction-dialog';promptDialog.setAttribute('aria-labelledby','input-dialog-title');
  const promptTitle=document.createElement('h2');promptTitle.id='input-dialog-title';
  const promptMessage=document.createElement('p'),promptLabel=document.createElement('label'),input=document.createElement('input');input.type='text';promptLabel.append(document.createTextNode('Valeur'),input);
  const promptActions=document.createElement('div');promptActions.className='modal-actions';
  const promptCancel=document.createElement('button'),promptAccept=document.createElement('button');promptCancel.type=promptAccept.type='button';promptCancel.className='button subtle';promptAccept.className='button primary';promptCancel.textContent='Annuler';promptActions.append(promptCancel,promptAccept);promptDialog.append(promptTitle,promptMessage,promptLabel,promptActions);document.body.append(promptDialog);
  function finishPrompt(result) {
    if(!promptQuestion) return;const current=promptQuestion;promptQuestion=null;
    if(current.focus?.isConnected) current.focus.focus({preventScroll:true});current.resolve(result);
  }
  promptDialog.addEventListener('close',()=>finishPrompt(null));
  promptCancel.addEventListener('click',()=>requestClose(promptDialog));
  promptAccept.addEventListener('click',()=>{const result=input.value;complete(promptDialog);finishPrompt(result);});
  input.addEventListener('keydown',event=>{if(event.key==='Enter'){event.preventDefault();promptAccept.click();}});
  function askPrompt(options) {
    if(promptQuestion) return Promise.resolve(null);
    promptTitle.textContent=options.title||'Saisir une valeur';promptMessage.textContent=options.message||'';promptMessage.hidden=!options.message;
    promptLabel.firstChild.textContent=options.label||'Valeur';input.value=options.value||'';input.maxLength=options.maxLength||240;promptAccept.textContent=options.accept||'Enregistrer';
    return new Promise(resolve=>{promptQuestion={resolve,focus:document.activeElement};show(promptDialog);input.focus({preventScroll:true});input.select();});
  }
  async function requestClose(dialog) {
    if(!dialog?.open || dialog===confirmDialog || pending.has(dialog)) return false;
    if(dialog.getAttribute('aria-busy')==='true' || dialog.querySelector('[aria-busy="true"]')) {
      dialog.dispatchEvent(new CustomEvent('sillage:dialog-busy',{bubbles:true}));return false;
    }
    if(!changed(dialog)) {complete(dialog);return true;}
    pending.add(dialog);
    try {
      const discard=await ask({title:'Abandonner les modifications ?',message:'Vos modifications non enregistrées seront perdues.',cancel:'Continuer l’édition',accept:'Abandonner les modifications',danger:true});
      if(discard && dialog.open) {restore(dialog);complete(dialog);return true;}
      return false;
    } finally {pending.delete(dialog);}
  }
  document.querySelectorAll('dialog:not([data-dialog-utility])').forEach(observe);
  new MutationObserver(records=>{
    for(const record of records) {
      if(record.type==='attributes') {
        const dialog=record.target;
        if(!dialog.matches('dialog')) continue;
        if(dialog.open && !dialog.hasAttribute('data-dialog-utility') && !states.has(dialog)) commit(dialog);
      } else {
        for(const node of record.addedNodes) {
          if(node.nodeType!==1) continue;
          if(node.matches('dialog:not([data-dialog-utility])')) observe(node);
          node.querySelectorAll('dialog:not([data-dialog-utility])').forEach(observe);
        }
        const dialog=record.target.closest?.('dialog');const baseline=dialog && states.get(dialog);
        if(baseline) controls(dialog).forEach(el=>{if(!baseline.has(el)) baseline.set(el,value(el));});
      }
    }
  }).observe(document.body,{subtree:true,childList:true,attributes:true,attributeFilter:['open']});
  let backdropStart=null;
  const outside=(dialog,event)=>{const box=dialog.getBoundingClientRect();return event.clientX<box.left || event.clientX>box.right || event.clientY<box.top || event.clientY>box.bottom;};
  document.addEventListener('pointerdown',event=>{backdropStart=event.target.matches?.('dialog') && outside(event.target,event)?event.target:null;});
  document.addEventListener('click',event=>{
    const closer=event.target.closest('[data-close]');
    if(closer) {
      event.preventDefault();
      if(closer.dataset.open) {event.stopImmediatePropagation();requestClose(closer.closest('dialog')).then(closed=>{if(closed) show(closer.dataset.open);});}
      else requestClose(closer.closest('dialog'));
      return;
    }
    if(event.target===backdropStart && outside(event.target,event)) {if(event.target===confirmDialog) settle(false);else requestClose(event.target);}
    backdropStart=null;
  });
  const allowed=new WeakSet(),confirming=new WeakSet();
  document.addEventListener('submit',async event=>{
    const form=event.target,submitter=event.submitter;
    if(allowed.has(form)) {allowed.delete(form);return;}
    const message=submitter?.dataset.confirm || form.dataset.confirm;
    if(!message) return;
    event.preventDefault();event.stopImmediatePropagation();if(confirming.has(form)) return;
    confirming.add(form);
    try {if(await ask({message})) {allowed.add(form);form.requestSubmit(submitter||undefined);allowed.delete(form);}}
    finally {confirming.delete(form);}
  },true);
  document.addEventListener('click',async event=>{
    const trigger=event.target.closest('[data-confirm]');
    if(!trigger || trigger.form || allowed.has(trigger)) {if(trigger) allowed.delete(trigger);return;}
    event.preventDefault();event.stopImmediatePropagation();
    if(await ask({message:trigger.dataset.confirm})) {allowed.add(trigger);trigger.click();allowed.delete(trigger);}
  },true);
  document.addEventListener('click',async event=>{
    const link=event.target.closest('a[href]'),dialog=link?.closest('dialog[open]');
    if(!dialog || dialog===confirmDialog || link.target==='_blank' || link.hasAttribute('download') || link.hasAttribute('data-confirm') || event.defaultPrevented || event.button!==0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || !changed(dialog)) return;
    event.preventDefault();event.stopImmediatePropagation();
    if(await requestClose(dialog)) location.assign(link.href);
  },true);
  window.SillageDialogs={show,complete,commit,requestClose,changed,confirm:message=>ask(typeof message==='string'?{message}:message),prompt:askPrompt};
})();
