(() => {
  document.addEventListener('click',event=>{
    if(!event.target.closest('[data-email-recommended]'))return;
    const defaults=JSON.parse(document.getElementById('email-recommended').textContent);
    document.querySelectorAll('[data-email-event]').forEach(input=>input.checked=(defaults[input.dataset.emailEvent]||[]).includes(input.dataset.emailRole));
  });
})();
