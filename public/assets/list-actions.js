(() => {
  'use strict';
  const buttons=[...document.querySelectorAll('[data-ticket-menu-button]')];if(!buttons.length) return;
  let active=null;
  const items=menu=>[...menu.querySelectorAll('[role="menuitem"]')];
  function close(focus=false) {
    if(!active) return;const {menu,button}=active;active=null;
    if(typeof menu.hidePopover==='function' && menu.matches(':popover-open')) menu.hidePopover();
    menu.classList.remove('is-open');button.setAttribute('aria-expanded','false');
    if(focus) button.focus({preventScroll:true});
  }
  function open(button,last=false) {
    const menu=document.getElementById(button.getAttribute('aria-controls'));if(!menu) return;
    if(active?.menu===menu) {close(true);return;}
    close();active={menu,button};
    if(typeof menu.showPopover==='function') menu.showPopover();
    else {document.body.append(menu);menu.classList.add('is-open');}
    const box=button.getBoundingClientRect(),size=menu.getBoundingClientRect();
    menu.style.left=Math.max(8,Math.min(box.right-size.width,innerWidth-size.width-8))+'px';
    menu.style.top=Math.max(8,Math.min(box.bottom+6+size.height<=innerHeight-8?box.bottom+6:box.top-size.height-6,innerHeight-size.height-8))+'px';
    button.setAttribute('aria-expanded','true');const options=items(menu);options[last?options.length-1:0]?.focus({preventScroll:true});
  }
  buttons.forEach(button=>{
    const menu=document.getElementById(button.getAttribute('aria-controls'));
    button.addEventListener('click',event=>{event.preventDefault();open(button);});
    button.addEventListener('keydown',event=>{if(['ArrowDown','ArrowUp'].includes(event.key)){event.preventDefault();open(button,event.key==='ArrowUp');}});
    menu.addEventListener('toggle',event=>{if(event.newState==='closed'){button.setAttribute('aria-expanded','false');if(active?.menu===menu) active=null;}});
    menu.addEventListener('keydown',event=>{
      if(event.key==='Escape') {event.preventDefault();close(true);return;}
      if(event.key==='Tab') {close();return;}
      const options=items(menu),index=options.indexOf(document.activeElement);
      const next=event.key==='ArrowDown'?(index+1)%options.length:event.key==='ArrowUp'?(index-1+options.length)%options.length:event.key==='Home'?0:event.key==='End'?options.length-1:null;
      if(next!==null) {event.preventDefault();options[next].focus();}
    });
    menu.addEventListener('click',event=>{if(event.target.closest('[role="menuitem"]')) close(event.target.closest('button')!==null);});
  });
  document.addEventListener('click',event=>{if(active && !active.menu.contains(event.target) && !active.button.contains(event.target)) close();});
  document.addEventListener('scroll',event=>{if(active && !active.menu.contains(event.target)) close();},true);
  window.addEventListener('resize',()=>close());
})();
