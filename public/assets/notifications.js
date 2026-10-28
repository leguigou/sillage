(() => {
  const button=document.querySelector('[data-email-recommended]');
  if(!button)return;
  button.addEventListener('click',()=>{
    const defaults=JSON.parse(document.getElementById('email-recommended').textContent);
    document.querySelectorAll('[data-email-event]').forEach(input=>input.checked=(defaults[input.dataset.emailEvent]||[]).includes(input.dataset.emailRole));
  });
})();
