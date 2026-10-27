'use strict';
const $ = (s, root = document) => root.querySelector(s);
const $$ = (s, root = document) => [...root.querySelectorAll(s)];
const csrfToken = $('meta[name="csrf-token"]')?.content;
function syncSidebar() {
  const sidebar = $('.sidebar'),
    button = $('[data-mobile-menu]');
  if (!sidebar) return;
  const closed =
    matchMedia('(max-width:760px)').matches && !sidebar.classList.contains('mobile-open');
  sidebar.inert = closed;
  button?.setAttribute('aria-expanded', closed ? 'false' : 'true');
}
syncSidebar();
window.addEventListener('resize', syncSidebar);
if (document.body.dataset.role === 'viewer') {
  $$('[data-quick-field],[data-edit-field],[data-create-status],[data-schedule-ticket]').forEach(
    (el) => (el.disabled = true),
  );
}
function toast(message, error = false) {
  let stack = $('#toast-stack');
  if (!stack) {
    stack = document.createElement('div');
    stack.className = 'toast-stack';
    document.body.append(stack);
  }
  const el = document.createElement('div');
  el.className = 'toast' + (error ? ' error' : '');
  el.setAttribute('role', error ? 'alert' : 'status');
  el.textContent = message;
  stack.append(el);
  setTimeout(() => el.remove(), 6500);
}
function openDialog(id) {
  const dialog = document.getElementById(id);
  if (dialog) {
    $$('.dropdown[open]').forEach((d) => (d.open = false));
    dialog.showModal();
    setTimeout(
      () => $('input:not([type="hidden"]):not(:disabled),textarea,select', dialog)?.focus(),
      30,
    );
  }
}
document.addEventListener('click', (event) => {
  const opener = event.target.closest('[data-open]');
  if (opener) openDialog(opener.dataset.open);
  const closer = event.target.closest('[data-close]');
  if (closer) closer.closest('dialog')?.close();
  const confirm = event.target.closest('[data-confirm]');
  if (confirm && !window.confirm(confirm.dataset.confirm)) event.preventDefault();
  const copy = event.target.closest('[data-copy]');
  if (copy)
    navigator.clipboard
      .writeText(copy.dataset.copy)
      .then(() => toast('Copié. Le prochain pas est à vous.'))
      .catch(() => toast('Copie indisponible dans ce navigateur.', true));
  if (event.target.closest('[data-mobile-menu]')) {
    $('.sidebar')?.classList.toggle('mobile-open');
    syncSidebar();
  } else if (!event.target.closest('.sidebar') && $('.sidebar.mobile-open')) {
    $('.sidebar').classList.remove('mobile-open');
    syncSidebar();
  }
  if (event.target.closest('[data-show-password]')) {
    const button = event.target.closest('[data-show-password]');
    const input = $('input', button.parentElement);
    input.type = input.type === 'password' ? 'text' : 'password';
    button.textContent = input.type === 'password' ? 'Afficher' : 'Masquer';
  }
});
$$('dialog').forEach((d) =>
  d.addEventListener('click', (e) => {
    if (e.target === d) {
      const box = d.getBoundingClientRect();
      if (
        e.clientX < box.left ||
        e.clientX > box.right ||
        e.clientY < box.top ||
        e.clientY > box.bottom
      )
        d.close();
    }
  }),
);
function setTheme(theme) {
  document.documentElement.dataset.theme = theme;
  try {
    localStorage.setItem('sillage.theme', theme);
  } catch {}
  $$('[data-set-theme]').forEach((b) => {
    b.classList.toggle('selected', b.dataset.setTheme === theme);
    b.setAttribute('aria-pressed', b.dataset.setTheme === theme ? 'true' : 'false');
  });
}
setTheme(document.documentElement.dataset.theme || 'light');
$$('[data-set-theme]').forEach((b) =>
  b.addEventListener('click', () => setTheme(b.dataset.setTheme)),
);
$$('[data-theme-toggle]').forEach((b) =>
  b.addEventListener('click', () =>
    setTheme(document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark'),
  ),
);
const email = $('[data-company-email]');
if (email)
  email.addEventListener('input', () => {
    const name = $('#suggested-name');
    if (name && !name.dataset.edited)
      name.value = email.value
        .split('@')[0]
        .replace(/[._+\-]+/g, ' ')
        .trim()
        .replace(/(^|\s)\p{L}/gu, (c) => c.toLocaleUpperCase('fr'));
  });
$('#suggested-name')?.addEventListener('input', (e) => (e.target.dataset.edited = 'true'));
document.addEventListener('keydown', (e) => {
  if (
    e.ctrlKey ||
    e.metaKey ||
    e.altKey ||
    /INPUT|TEXTAREA|SELECT/.test(e.target.tagName) ||
    e.target.isContentEditable ||
    $('dialog[open]')
  )
    return;
  if (e.key === 'c' && document.body.dataset.role !== 'viewer' && $('#ticket-dialog')) {
    e.preventDefault();
    openDialog('ticket-dialog');
  }
  if (e.key === '/') {
    e.preventDefault();
    openDialog('search-dialog');
  }
  if (e.shiftKey && e.key.toLowerCase() === 'f') {
    e.preventDefault();
    openDialog('filters-dialog');
  }
});
$$('[data-filter-tab]').forEach((button) =>
  button.addEventListener('click', () => {
    $$('[data-filter-tab]').forEach((b) => b.classList.toggle('selected', b === button));
    $$('[data-filter-panel]').forEach(
      (p) => (p.hidden = p.dataset.filterPanel !== button.dataset.filterTab),
    );
  }),
);
$$('[data-dev-tab]').forEach((button) =>
  button.addEventListener('click', () => {
    $$('[data-dev-tab]').forEach((b) => b.classList.toggle('selected', b === button));
    $$('[data-dev-panel]').forEach(
      (p) => (p.hidden = p.dataset.devPanel !== button.dataset.devTab),
    );
  }),
);
$$('[data-create-status]').forEach((b) =>
  b.addEventListener('click', () => {
    $('#create-status').value = b.dataset.createStatus;
    openDialog('ticket-dialog');
  }),
);
$$('[data-collapse-column]').forEach((b) =>
  b.addEventListener('click', () => {
    const c = b.closest('.kanban-column');
    c.classList.toggle('collapsed');
    b.setAttribute('aria-expanded', c.classList.contains('collapsed') ? 'false' : 'true');
  }),
);
async function post(path, values) {
  const form = new FormData();
  Object.entries(values).forEach(([k, v]) => form.set(k, v ?? ''));
  form.set('_csrf', csrfToken);
  const response = await fetch(path, {
    method: 'POST',
    body: form,
    headers: { Accept: 'application/json' },
  });
  const type = response.headers.get('content-type') || '';
  if (response.redirected || !type.includes('application/json'))
    throw new Error(
      'Votre session a expiré ou le serveur n’est pas disponible. Rechargez la page.',
    );
  const data = await response.json();
  if (!response.ok) throw new Error(data.error || 'La modification n’a pas pu être enregistrée.');
  return data;
}
async function quick(row, field, value) {
  const previousVersion = Number(row.dataset.version);
  const data = await post(`/tickets/${row.dataset.ticketId}/quick`, {
    field,
    value,
    version: row.dataset.version,
  });
  $$(`[data-ticket-id="${row.dataset.ticketId}"]`).forEach(
    (r) => (r.dataset.version = data.ticket.version),
  );
  if (Number(data.ticket.version) > previousVersion + 1) {
    sessionStorage.setItem(
      'sillage.notice',
      'Modification enregistrée et automatisations appliquées.',
    );
    location.reload();
  }
  return data;
}
$$('[data-quick-field]').forEach((input) =>
  input.addEventListener('change', async () => {
    const row = input.closest('[data-ticket-id]');
    input.disabled = true;
    try {
      await quick(row, input.dataset.quickField, input.value);
      toast('Modification enregistrée.');
      if (input.dataset.quickField === 'status') {
        input.className = `status-select status-${input.value}`;
        row.dataset.status = input.value;
      }
      applyRules();
    } catch (error) {
      toast(error.message, true);
    } finally {
      input.disabled = false;
    }
  }),
);
$$('[data-edit-field]').forEach((button) =>
  button.addEventListener('click', async () => {
    const title = prompt('Nouveau résumé', button.dataset.value);
    if (!title || title === button.dataset.value) return;
    try {
      await quick(button.closest('[data-ticket-id]'), 'title', title);
      button.dataset.value = title;
      $('a', button.closest('td')).textContent = title;
      toast('Résumé mis à jour.');
    } catch (error) {
      toast(error.message, true);
    }
  }),
);
let dragged = null;
$$('.ticket-card[draggable="true"]').forEach((card) => {
  card.addEventListener('dragstart', (e) => {
    if (e.target.closest('a,button,summary')) {
      e.preventDefault();
      return;
    }
    dragged = card;
    card.classList.add('dragging');
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', card.dataset.ticketId);
  });
  card.addEventListener('dragend', () => {
    card.classList.remove('dragging');
    $$('.drop-hover').forEach((c) => c.classList.remove('drop-hover'));
    dragged = null;
  });
});
$$('[data-drop-status]').forEach((column) => {
  column.addEventListener('dragover', (e) => {
    if (dragged) {
      e.preventDefault();
      column.classList.add('drop-hover');
    }
  });
  column.addEventListener('dragleave', (e) => {
    if (!column.contains(e.relatedTarget)) column.classList.remove('drop-hover');
  });
  column.addEventListener('drop', async (e) => {
    e.preventDefault();
    column.classList.remove('drop-hover');
    const card = dragged;
    const targetCard = e.target.closest('.ticket-card');
    if (!card || targetCard === card) return;
    try {
      await post(`/tickets/${card.dataset.ticketId}/move`, {
        status: column.dataset.dropStatus,
        before_id: targetCard?.dataset.ticketId || '',
        version: card.dataset.version,
      });
      sessionStorage.setItem('sillage.notice', 'Ticket déplacé. Le projet avance.');
      location.reload();
    } catch (error) {
      toast(error.message, true);
    }
  });
});
function selectedTickets() {
  const n = $$('[data-select-ticket]:checked').length;
  const bulk = $('#bulk-form');
  if (bulk) bulk.hidden = n === 0;
  const counter = $('[data-selected-count]');
  if (counter) counter.textContent = n;
}
$$('[data-select-ticket]').forEach((input) => input.addEventListener('change', selectedTickets));
$('[data-select-all]')?.addEventListener('change', (e) => {
  $$('[data-select-ticket]').forEach((c) => (c.checked = e.target.checked));
  selectedTickets();
});
$$('[data-enable-bulk]').forEach((b) =>
  b.addEventListener('click', () => {
    const bulk = $('#bulk-form');
    if (bulk) {
      bulk.hidden = false;
      $$('.dropdown[open]').forEach((d) => (d.open = false));
      toast('Cochez les tickets à modifier, puis choisissez le champ et sa valeur.');
    } else toast('Ouvrez la vue Liste pour sélectionner plusieurs tickets.');
  }),
);
let preferences = {};
try {
  preferences = JSON.parse(localStorage.getItem('sillage.preferences') || '{}');
} catch {}
function applyPreferences() {
  document.body.classList.toggle(
    'board-fixed',
    (preferences['column-width'] || 'fixed') === 'fixed',
  );
  document.body.classList.toggle(
    'board-columns-scroll',
    (preferences['board-scroll'] || 'columns') === 'columns',
  );
  $$('[data-preference]').forEach(
    (el) => (el.value = preferences[el.dataset.preference] || el.options[0].value),
  );
  $$('[data-column-toggle]').forEach((el) => {
    el.checked = preferences[`column.${el.dataset.columnToggle}`] !== false;
    $$(`[data-column="${el.dataset.columnToggle}"]`).forEach((cell) => (cell.hidden = !el.checked));
  });
  const age = Number(preferences['done-age'] || 0);
  $$('.ticket-card').forEach((c) => {
    const completed = c.dataset.completed
      ? new Date(c.dataset.completed.replace(' ', 'T') + 'Z').getTime()
      : null;
    c.hidden = Boolean(
      age && completed && c.dataset.status === 'done' && Date.now() - completed > age * 86400000,
    );
  });
}
function savePreferences() {
  try {
    localStorage.setItem('sillage.preferences', JSON.stringify(preferences));
  } catch {}
  applyPreferences();
}
$$('[data-preference]').forEach((el) =>
  el.addEventListener('change', () => {
    preferences[el.dataset.preference] = el.value;
    savePreferences();
  }),
);
$$('[data-column-toggle]').forEach((el) =>
  el.addEventListener('change', () => {
    preferences[`column.${el.dataset.columnToggle}`] = el.checked;
    savePreferences();
  }),
);
applyPreferences();
try {
  const path = location.pathname;
  if (['/list', '/board', '/all'].includes(path)) {
    const key = `sillage.lastview.${path}`;
    const previous = localStorage.getItem(key);
    if (previous && previous !== location.search)
      sessionStorage.setItem(`sillage.previous.${path}`, previous);
    localStorage.setItem(key, location.search);
  }
} catch {}
$$('[data-restore-view]').forEach((b) =>
  b.addEventListener('click', () => {
    const previous = sessionStorage.getItem(`sillage.previous.${location.pathname}`);
    if (!previous) return toast('Aucune vue précédente enregistrée dans ce navigateur.');
    location.search = previous;
  }),
);
function applyRules() {
  const json = $('#formatting-rules');
  if (!json) return;
  let rules;
  try {
    rules = JSON.parse(json.textContent);
  } catch {
    return;
  }
  $$('[data-ticket-id]').forEach((row) => {
    row.classList.remove('format-match');
    row.style.removeProperty('--rule-color');
    for (const rule of rules)
      if (row.dataset[rule.field] === rule.match_value && /^#[a-f0-9]{6}$/i.test(rule.color)) {
        row.classList.add('format-match');
        row.style.setProperty('--rule-color', rule.color);
        break;
      }
  });
}
applyRules();
$$('[data-donut]').forEach((container) => {
  let rows;
  try {
    rows = JSON.parse(container.dataset.donut);
  } catch {
    return;
  }
  const total = rows.reduce((s, r) => s + Number(r.n), 0);
  if (!total) return;
  const colors = { todo: '#b4b5c7', progress: '#9c8bea', review: '#e7b778', done: '#7db7a4' };
  const circumference = 2 * Math.PI * 46;
  let offset = 0;
  rows.forEach((row) => {
    const length = (Number(row.n) / total) * circumference;
    const c = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
    Object.entries({
      cx: 60,
      cy: 60,
      r: 46,
      stroke: colors[row.label] || '#9686d9',
      'stroke-dasharray': `${Math.max(0, length - 3)} ${circumference}`,
      'stroke-dashoffset': -offset,
    }).forEach(([key, value]) => c.setAttribute(key, value));
    $('svg', container).append(c);
    offset += length;
  });
});
$$('[data-timeline-grid-days]').forEach((grid) =>
  grid.style.setProperty('--timeline-days', Number(grid.dataset.timelineGridDays)),
);
$$('[data-timeline-left]').forEach(
  (el) => (el.style.left = `${Math.max(0, Number(el.dataset.timelineLeft))}%`),
);
$$('[data-timeline-width]').forEach(
  (el) => (el.style.width = `${Math.max(0, Number(el.dataset.timelineWidth))}%`),
);
$$('[data-schedule-ticket]').forEach((b) =>
  b.addEventListener('click', () => {
    $('[name="ticket_id"]', $('#schedule-dialog')).value = b.dataset.scheduleTicket;
    openDialog('schedule-dialog');
  }),
);
$('[data-schedule-form]')?.addEventListener('submit', async (e) => {
  e.preventDefault();
  const data = new FormData(e.target);
  const row = $(`[data-ticket-id="${data.get('ticket_id')}"]`);
  try {
    await post(`/tickets/${row.dataset.ticketId}/schedule`, {
      start_date: data.get('start_date'),
      due_date: data.get('due_date'),
      version: row.dataset.version,
    });
    location.reload();
  } catch (error) {
    toast(error.message, true);
  }
});
function updateConditions() {
  $$('[data-condition-field]').forEach((el) => {
    const input = $(`[name="${el.dataset.conditionField}"]`);
    const visible = input?.value === el.dataset.conditionValue;
    el.hidden = !visible;
    $$('input,textarea,select', el).forEach((control) => {
      control.disabled = !visible;
    });
  });
}
$$('[name="priority"]').forEach((el) => el.addEventListener('change', updateConditions));
updateConditions();
try {
  const notice = sessionStorage.getItem('sillage.notice');
  if (notice) {
    sessionStorage.removeItem('sillage.notice');
    toast(notice);
  }
} catch {}
if (new URLSearchParams(location.search).get('create') === '1') openDialog('ticket-dialog');
// Les barres se déplacent ; leur poignée droite ajuste l’échéance.
$$('.timeline-bar').forEach((bar) => {
  const row = bar.closest('[data-ticket-id]'),
    track = bar.closest('.timeline-track');
  const handle = document.createElement('span');
  handle.className = 'timeline-handle';
  handle.setAttribute('aria-hidden', 'true');
  bar.append(handle);
  let moved = false;
  bar.addEventListener(
    'click',
    (e) => {
      if (moved) {
        e.preventDefault();
        e.stopPropagation();
      }
    },
    true,
  );
  bar.addEventListener('pointerdown', (event) => {
    if (event.button !== 0) return;
    moved = false;
    const initialX = event.clientX,
      resize = event.target === handle,
      originalLeft = bar.style.left,
      originalWidth = bar.style.width;
    const start = new Date(row.dataset.startDate + 'T00:00:00Z'),
      due = new Date((row.dataset.dueDate || row.dataset.startDate) + 'T00:00:00Z');
    let delta = 0;
    function move(e) {
      if (Math.abs(e.clientX - initialX) < 6 && !moved) return;
      moved = true;
      bar.classList.add('scheduling');
      delta = Math.round(
        ((e.clientX - initialX) / track.clientWidth) * Number(track.dataset.timelineDays),
      );
      const pct = (delta / Number(track.dataset.timelineDays)) * 100;
      if (resize) bar.style.width = `${Math.max(0.5, parseFloat(originalWidth) + pct)}%`;
      else bar.style.left = `${parseFloat(originalLeft) + pct}%`;
    }
    async function finish() {
      document.removeEventListener('pointermove', move);
      document.removeEventListener('pointerup', finish);
      bar.classList.remove('scheduling');
      if (!moved || !delta) return;
      const day = 86400000,
        newStart = new Date(start.getTime() + (resize ? 0 : delta * day)),
        newDue = new Date(due.getTime() + delta * day);
      if (newDue < newStart) {
        bar.style.left = originalLeft;
        bar.style.width = originalWidth;
        toast('L’échéance doit rester après la date de début.', true);
        return;
      }
      try {
        await post(`/tickets/${row.dataset.ticketId}/schedule`, {
          start_date: newStart.toISOString().slice(0, 10),
          due_date: newDue.toISOString().slice(0, 10),
          version: row.dataset.version,
        });
        sessionStorage.setItem('sillage.notice', 'Planification mise à jour.');
        location.reload();
      } catch (error) {
        bar.style.left = originalLeft;
        bar.style.width = originalWidth;
        toast(error.message, true);
      }
    }
    document.addEventListener('pointermove', move);
    document.addEventListener('pointerup', finish, { once: true });
  });
});
function drawDependencies() {
  const data = $('#timeline-dependencies'),
    panel = $('.timeline-grid');
  if (!data || !panel) return;
  $('.timeline-dependencies', panel)?.remove();
  let edges;
  try {
    edges = JSON.parse(data.textContent);
  } catch {
    return;
  }
  if (!edges.length) return;
  const ns = 'http://www.w3.org/2000/svg',
    svg = document.createElementNS(ns, 'svg');
  svg.classList.add('timeline-dependencies');
  svg.setAttribute('width', panel.scrollWidth);
  svg.setAttribute('height', panel.scrollHeight);
  svg.setAttribute('aria-label', 'Dépendances entre les tickets affichés');
  const defs = document.createElementNS(ns, 'defs'),
    marker = document.createElementNS(ns, 'marker'),
    arrow = document.createElementNS(ns, 'path');
  for (const [k, v] of Object.entries({
    id: 'dependency-arrow',
    markerWidth: 6,
    markerHeight: 6,
    refX: 5,
    refY: 3,
    orient: 'auto',
  }))
    marker.setAttribute(k, v);
  arrow.setAttribute('d', 'M0,0 L6,3 L0,6');
  arrow.setAttribute('fill', '#a69acb');
  marker.append(arrow);
  defs.append(marker);
  svg.append(defs);
  const bounds = panel.getBoundingClientRect();
  edges.forEach((edge) => {
    const source = $(`[data-ticket-id="${edge.source_id}"] .timeline-bar`, panel),
      target = $(`[data-ticket-id="${edge.target_id}"] .timeline-bar`, panel);
    if (!source || !target) return;
    const s = source.getBoundingClientRect(),
      t = target.getBoundingClientRect(),
      x = s.right - bounds.left + panel.scrollLeft,
      y = s.top + s.height / 2 - bounds.top + panel.scrollTop,
      tx = t.left - bounds.left + panel.scrollLeft,
      ty = t.top + t.height / 2 - bounds.top + panel.scrollTop;
    const path = document.createElementNS(ns, 'path');
    path.setAttribute('d', `M${x},${y} C${x + 25},${y} ${tx - 25},${ty} ${tx},${ty}`);
    path.setAttribute('stroke', '#a69acb');
    path.setAttribute('stroke-width', '1.5');
    path.setAttribute('fill', 'none');
    path.setAttribute('marker-end', 'url(#dependency-arrow)');
    svg.append(path);
  });
  panel.append(svg);
}
drawDependencies();
window.addEventListener('resize', drawDependencies);
const collapsedTickets = new Set();
$$('tr[data-depth]').forEach((row) => {
  const title = $('.title-cell', row);
  if (title && Number(row.dataset.depth))
    title.style.paddingLeft = `${12 + Math.min(8, Number(row.dataset.depth)) * 15}px`;
});
$$('[data-toggle-children]').forEach((button) =>
  button.addEventListener('click', () => {
    const id = button.dataset.toggleChildren;
    if (collapsedTickets.has(id)) collapsedTickets.delete(id);
    else collapsedTickets.add(id);
    button.setAttribute('aria-expanded', collapsedTickets.has(id) ? 'false' : 'true');
    button.textContent = collapsedTickets.has(id) ? '›' : '⌄';
    $$('tr[data-ticket-id]').forEach((row) => {
      let parent = row.dataset.parent,
        hidden = false;
      const seen = new Set();
      while (parent && parent !== '0' && !seen.has(parent)) {
        seen.add(parent);
        if (collapsedTickets.has(parent)) {
          hidden = true;
          break;
        }
        parent = $(`tr[data-ticket-id="${parent}"]`)?.dataset.parent || '0';
      }
      row.hidden = hidden;
    });
  }),
);

$$('[data-mcp-lifetime]').forEach((select) => {
  const update = () =>
    $$('[data-mcp-duration]', select.form).forEach((input) => {
      input.disabled = select.value === 'unlimited';
    });
  select.addEventListener('change', update);
  update();
});
