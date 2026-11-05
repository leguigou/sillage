(() => {
  'use strict';
  const boards=[...document.querySelectorAll('[data-board-preferences-key]')],dialog=document.getElementById('board-column-settings');
  if(!boards.length || !dialog) return;
  const storageKey='sillage.board-columns.'+boards[0].dataset.boardPreferencesKey,form=dialog.querySelector('form'),fields=form.elements;
  const defaults={custom_background:false,color:'#8b7cf8',opacity:12,width:'',density:'normal',collapsed:false};
  let saved={},active=null;
  try {const value=JSON.parse(localStorage.getItem(storageKey)||'{}');if(value && typeof value==='object' && !Array.isArray(value)) saved=value;}catch{}
  const columns=()=>boards.flatMap(board=>[...board.querySelectorAll('.kanban-column')]);
  function settings(value) {
    const v=value && typeof value==='object'?value:{};
    return {custom_background:v.custom_background===true,color:/^#[a-f0-9]{6}$/i.test(v.color)?v.color:defaults.color,opacity:Number.isFinite(v.opacity)?Math.max(0,Math.min(100,v.opacity)):12,width:['250','270','320','360','420'].includes(v.width)?v.width:'',density:v.density==='compact'?'compact':'normal',collapsed:v.collapsed===true};
  }
  function apply(state,value) {
    const v=settings(value);
    columns().filter(column=>column.dataset.dropStatus===state).forEach(column=>{
      column.classList.toggle('collapsed',v.collapsed);column.classList.toggle('column-compact',v.density==='compact');column.classList.toggle('has-column-width',v.width!=='');
      column.style.setProperty('--column-width',(v.width||270)+'px');
      if(v.custom_background) {const rgb=[1,3,5].map(index=>parseInt(v.color.slice(index,index+2),16));column.style.setProperty('--column-background','rgba('+rgb.join(',')+','+v.opacity/100+')');}
      else column.style.removeProperty('--column-background');
      const button=column.querySelector('[data-collapse-column]'),label=column.querySelector('h2').textContent;
      button.setAttribute('aria-expanded',String(!v.collapsed));button.setAttribute('aria-label',(v.collapsed?'Déplier ':'Replier ')+label);
    });
  }
  function persist(state,value) {
    const next={...saved,[state]:settings(value)};
    try{localStorage.setItem(storageKey,JSON.stringify(next));saved=next;return true;}catch{return false;}
  }
  function draft() {return {...settings(saved[active]),custom_background:fields.custom_background.checked,color:fields.color.value,opacity:Number(fields.opacity.value),width:fields.width.value,density:fields.density.value};}
  function preview() {const value=draft();dialog.querySelector('[data-column-opacity]').textContent=value.opacity+' %';fields.color.disabled=fields.opacity.disabled=!value.custom_background;apply(active,value);}
  function fill(value) {const v=settings(value);fields.custom_background.checked=v.custom_background;fields.color.value=v.color;fields.opacity.value=v.opacity;fields.width.value=v.width;fields.density.value=v.density;preview();}
  columns().forEach(column=>apply(column.dataset.dropStatus,saved[column.dataset.dropStatus]));
  document.addEventListener('click',event=>{
    const gear=event.target.closest('[data-column-settings]'),collapse=event.target.closest('[data-collapse-column]');
    if(gear) {const column=gear.closest('.kanban-column');active=column.dataset.dropStatus;dialog.querySelector('[data-column-settings-title]').textContent='Colonne « '+column.querySelector('h2').textContent+' »';dialog.querySelector('[data-column-settings-status]').textContent='';fill(saved[active]);window.SillageDialogs.show(dialog);}
    if(collapse) {const state=collapse.closest('.kanban-column').dataset.dropStatus,value=settings(saved[state]);value.collapsed=!value.collapsed;if(persist(state,value)) apply(state,value);else toast('Impossible de mémoriser le repli dans ce navigateur.',true);}
  });
  form.addEventListener('input',preview);form.addEventListener('change',preview);
  dialog.querySelector('[data-column-defaults]').addEventListener('click',()=>fill({...defaults,collapsed:settings(saved[active]).collapsed}));
  form.addEventListener('submit',event=>{event.preventDefault();if(persist(active,draft())) {apply(active,saved[active]);window.SillageDialogs.complete(dialog);}else dialog.querySelector('[data-column-settings-status]').textContent='Enregistrement impossible : vérifiez le stockage du navigateur.';});
  const restore=()=>{if(active!==null) apply(active,saved[active]);};dialog.addEventListener('sillage:dialog-discarded',restore);dialog.addEventListener('close',restore);
})();
