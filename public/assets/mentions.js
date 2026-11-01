'use strict';
(() => {
  const catalog=$('#mention-members');if(!catalog)return;
  const members=JSON.parse(catalog.textContent),normalize=text=>text.normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase();
  $$('[data-composer]').forEach((composer,index)=>{
    const editor=$('.conversation-editor',composer),choices=document.createElement('div');
    choices.className='mention-suggestions';choices.id=`mention-choices-${index}`;choices.setAttribute('role','listbox');choices.setAttribute('aria-label','Collaborateurs à mentionner');choices.hidden=true;
    editor.after(choices);editor.setAttribute('aria-autocomplete','list');editor.setAttribute('aria-controls',choices.id);
    let range=null,matched=[],active=0;
    const hide=()=>{choices.hidden=true;editor.setAttribute('aria-expanded','false');editor.removeAttribute('aria-activedescendant');};
    const highlight=()=>{$$('[role="option"]',choices).forEach((option,i)=>option.setAttribute('aria-selected',String(i===active)));editor.setAttribute('aria-activedescendant',`${choices.id}-${active}`);};
    const choose=person=>{
      if(!range || !editor.contains(range.commonAncestorContainer)){hide();return;}
      const mention=document.createElement('span');mention.className='mention';mention.dataset.mentionId=String(person.id);mention.contentEditable='false';mention.textContent='@'+person.name;
      range.deleteContents();range.insertNode(mention);
      const space=document.createTextNode(' ');mention.after(space);range.setStartAfter(space);range.collapse(true);
      editor.focus();const selection=getSelection();selection.removeAllRanges();selection.addRange(range);hide();
      editor.dispatchEvent(new Event('input',{bubbles:true}));
    };
    const suggest=()=>{
      const selection=getSelection();if(!selection?.rangeCount || !selection.isCollapsed){hide();return;}
      const node=selection.anchorNode,offset=selection.anchorOffset;
      if(node?.nodeType!==Node.TEXT_NODE || !editor.contains(node) || node.parentElement.closest('[data-mention-id]')){hide();return;}
      const match=node.textContent.slice(0,offset).match(/(?:^|\s)@([^\s@]{0,40})$/);
      if(!match){hide();return;}
      range=selection.getRangeAt(0).cloneRange();range.setStart(node,offset-match[1].length-1);
      const query=normalize(match[1]);matched=members.filter(person=>normalize(person.name+' '+person.email).includes(query)).slice(0,8);active=0;
      choices.replaceChildren();
      if(!matched.length){hide();return;}
      matched.forEach((person,i)=>{
        const option=document.createElement('button');option.type='button';option.setAttribute('role','option');option.id=`${choices.id}-${i}`;
        const name=document.createElement('strong'),email=document.createElement('small');name.textContent=person.name;email.textContent=person.email;option.append(name,email);
        option.addEventListener('mousedown',event=>event.preventDefault());option.addEventListener('click',()=>choose(person));choices.append(option);
      });
      choices.hidden=false;editor.setAttribute('aria-expanded','true');highlight();
    };
    editor.addEventListener('input',suggest);
    editor.addEventListener('keydown',event=>{
      if(choices.hidden)return;
      if(['ArrowDown','ArrowUp'].includes(event.key)){event.preventDefault();active=(active+(event.key==='ArrowDown'?1:-1)+matched.length)%matched.length;highlight();}
      else if(['Enter','Tab'].includes(event.key)){event.preventDefault();choose(matched[active]);}
      else if(event.key==='Escape'){event.preventDefault();event.stopPropagation();hide();}
    });
    $('[data-insert-mention]',composer)?.addEventListener('click',()=>{
      editor.focus();const selection=getSelection();
      if(!selection.rangeCount || !editor.contains(selection.anchorNode)){const cursor=document.createRange();cursor.selectNodeContents(editor);cursor.collapse(false);selection.removeAllRanges();selection.addRange(cursor);}
      document.execCommand('insertText',false,' @');suggest();
    });
    document.addEventListener('pointerdown',event=>{if(!composer.contains(event.target))hide();});
    composer.closest('dialog')?.addEventListener('close',hide);
  });
})();
