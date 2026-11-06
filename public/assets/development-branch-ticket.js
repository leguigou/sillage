(() => {
  'use strict';
  const dialog = document.getElementById('development-branch-ticket');
  if (!dialog) return;
  const form = dialog.querySelector('form');
  const search = form.querySelector('[data-branch-ticket-search]');
  const tickets = form.elements.ticket_id;
  const submit = form.querySelector('[type="submit"]');
  const status = form.querySelector('[data-branch-ticket-status]');
  let opener, request, timer;

  async function json(response) {
    if (response.redirected || !response.headers.get('content-type')?.includes('application/json')) {
      throw new Error('Impossible d’accéder à la liaison. Vérifiez votre session.');
    }
    const data = await response.json();
    if (!response.ok) throw new Error(data.error || 'La liaison a échoué.');
    return data;
  }

  function queueSearch(delay) {
    request?.abort();
    clearTimeout(timer);
    tickets.replaceChildren();
    submit.disabled = true;
    status.textContent = 'Recherche des tickets…';
    timer = setTimeout(loadTickets, delay);
  }

  async function loadTickets() {
    const current = new AbortController();
    request = current;
    const url = opener.dataset.branchTicketPicker + '?' + new URLSearchParams({q: search.value.trim()});
    try {
      const result = await json(await fetch(url, {
        headers: {Accept: 'application/json'}, signal: current.signal, cache: 'no-store'
      }));
      if (current.signal.aborted) return;
      tickets.replaceChildren(...result.tickets.map(ticket =>
        new Option(ticket.key + ' · ' + ticket.title, ticket.id)
      ));
      tickets.selectedIndex = -1;
      status.textContent = result.tickets.length
        ? 'Sélectionnez un ticket. Affichage limité aux 25 plus récemment modifiés.'
        : 'Aucun ticket ouvert sans branche ne correspond.';
    } catch (error) {
      if (!current.signal.aborted) status.textContent = error.message;
    }
  }

  document.addEventListener('click', event => {
    const button = event.target.closest('[data-branch-ticket-picker]');
    if (!button || dialog.open) return;
    opener = button;
    form.reset();
    tickets.replaceChildren();
    dialog.querySelector('[data-branch-ticket-title]').textContent = button.dataset.branchTitle;
    window.SillageDialogs.show(dialog);
    queueSearch(0);
  });
  search.addEventListener('input', () => queueSearch(250));
  tickets.addEventListener('change', () => { submit.disabled = !tickets.value; });
  dialog.addEventListener('close', () => {
    clearTimeout(timer);
    request?.abort();
  });

  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (submit.disabled || !tickets.value) return;
    const body = new FormData(form);
    form.setAttribute('aria-busy', 'true');
    search.disabled = tickets.disabled = submit.disabled = true;
    status.textContent = 'Liaison en cours…';
    try {
      const result = await json(await fetch(opener.dataset.branchTicketPicker, {
        method: 'POST', body, headers: {Accept: 'application/json'}
      }));
      const link = document.createElement('a');
      link.className = 'ticket-key';
      link.href = result.ticket.url;
      link.textContent = result.ticket.key;
      opener.replaceWith(link);
      window.SillageDialogs.complete(dialog);
      link.focus({preventScroll: true});
      toast('Branche liée au ticket ' + result.ticket.key + '.');
    } catch (error) {
      status.textContent = error.message;
    } finally {
      form.removeAttribute('aria-busy');
      search.disabled = tickets.disabled = false;
      submit.disabled = !tickets.value;
    }
  });
})();
