(() => {
  'use strict';
  const root=document.getElementById('application-history');
  if(!root) return;
  const search=root.querySelector('[data-history-search]'),category=root.querySelector('[data-history-category]');
  const reset=root.querySelector('[data-history-reset]'),count=root.querySelector('[data-history-count]'),empty=root.querySelector('[data-history-empty]');
  const normalize=value=>value.normalize('NFD').replace(/\p{M}/gu,'').toLocaleLowerCase('fr');
  const entries=[...root.querySelectorAll('[data-history-entry]')].map(node=>({node,text:normalize(node.textContent),kind:node.dataset.historyKind}));
  let expandedForSearch=false,originalOpen=[];
  function filter() {
    const query=normalize(search.value.trim()),terms=query.split(/\s+/).filter(Boolean);
    if(query && !expandedForSearch) {originalOpen=entries.map(entry=>entry.node.open);expandedForSearch=true;}
    let visible=0;
    entries.forEach((entry,index)=>{
      const match=(!category.value || entry.kind===category.value) && terms.every(term=>entry.text.includes(term));
      entry.node.hidden=!match;
      if(match) visible++;
      if(query) entry.node.open=match;
      else if(expandedForSearch) entry.node.open=originalOpen[index];
    });
    if(!query) expandedForSearch=false;
    count.textContent=visible+' mise'+(visible===1?'':'s')+' à jour sur '+entries.length;
    empty.hidden=visible!==0;reset.hidden=!query && !category.value;
  }
  search.addEventListener('input',filter);category.addEventListener('change',filter);
  reset.addEventListener('click',()=>{search.value='';category.value='';filter();search.focus({preventScroll:true});});
})();
