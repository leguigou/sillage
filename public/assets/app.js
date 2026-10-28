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
function syncSidebarToggle() {
  const button = $('[data-sidebar-toggle]');
  if (!button) return;
  const collapsed = document.documentElement.dataset.sidebarCollapsed === 'true';
  button.setAttribute('aria-expanded', String(!collapsed));
  button.setAttribute('aria-label', collapsed ? 'Déplier le menu' : 'Replier le menu');
  button.title = collapsed ? 'Déplier le menu' : 'Replier le menu';
}
$('[data-sidebar-toggle]')?.addEventListener('click', () => {
  const collapsed = document.documentElement.dataset.sidebarCollapsed !== 'true';
  document.documentElement.dataset.sidebarCollapsed = String(collapsed);
  document.cookie = `sillage_sidebar_collapsed=${collapsed}; Path=/; Max-Age=31536000; SameSite=Lax${location.protocol === 'https:' ? '; Secure' : ''}`;
  try { localStorage.setItem('sillage.sidebar.collapsed', String(collapsed)); } catch {}
  syncSidebarToggle();
  drawDependencies();
});
syncSidebar();
syncSidebarToggle();
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
async function copyValue(value) {
  if (typeof value !== 'string') throw new Error('Valeur indisponible');
  if (navigator.clipboard?.writeText) return navigator.clipboard.writeText(value);
  const input = document.createElement('textarea'), focused = document.activeElement;
  input.value = value; input.className = 'clipboard-fallback'; document.body.append(input);
  try { input.select(); if (!document.execCommand('copy')) throw new Error('Copie refusée'); }
  finally { input.remove(); focused?.focus({preventScroll:true}); }
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
  const copy = event.target.closest('[data-copy],[data-copy-target]');
  if (copy) {
    const value = copy.dataset.copyTarget ? document.getElementById(copy.dataset.copyTarget)?.value : copy.dataset.copy;
    copyValue(value).then(() => {
      toast('Valeur copiée.');
      const label = $('span', copy);
      if (label) { label.textContent = 'Copié'; setTimeout(() => { if (label.isConnected) label.textContent = 'Copier'; }, 1800); }
    }).catch(() => toast('Copie indisponible. Sélectionnez la valeur pour la copier.', true));
  }
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
// Capture the gesture before the browser can start dragging the ticket link.
$$('.timeline-bar').forEach((bar) => {
  if (document.body.dataset.role === 'viewer') return;
  const row = bar.closest('[data-ticket-id]'), track = bar.closest('.timeline-track');
  const handle = document.createElement('span');
  handle.className = 'timeline-handle';
  handle.title = 'Glisser pour ajuster l’échéance';
  handle.setAttribute('aria-hidden', 'true');
  bar.append(handle);
  bar.draggable = false;
  bar.addEventListener('dragstart', (event) => event.preventDefault());
  let suppressClick = false, saving = false, gesture = null;
  bar.addEventListener('click', (event) => {
    if (event.target === handle || (event.detail !== 0 && (suppressClick || saving))) {
      event.preventDefault();
      event.stopImmediatePropagation();
    }
  }, true);
  bar.addEventListener('pointerdown', (event) => {
    if (event.button !== 0 || !event.isPrimary || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
    if (saving || gesture) { event.preventDefault(); return; }
    event.preventDefault();
    bar.focus({ preventScroll: true });
    suppressClick = event.target === handle;
    const initialX = event.clientX, initialY = event.clientY,
      resize = event.target === handle,
      originalLeft = bar.style.left, originalWidth = bar.style.width,
      start = new Date(row.dataset.startDate + 'T00:00:00Z'),
      due = new Date((row.dataset.dueDate || row.dataset.startDate) + 'T00:00:00Z'),
      pointerId = event.pointerId;
    let delta = 0, moved = false;
    gesture = pointerId;
    const restore = () => { bar.style.left = originalLeft; bar.style.width = originalWidth; };
    function move(e) {
      if (e.pointerId !== pointerId) return;
      if (!moved && Math.hypot(e.clientX - initialX, e.clientY - initialY) < 6) return;
      moved = true;
      suppressClick = true;
      bar.classList.add('scheduling');
      delta = Math.round(((e.clientX - initialX) / track.clientWidth) * Number(track.dataset.timelineDays));
      const pct = delta / Number(track.dataset.timelineDays) * 100;
      if (resize) bar.style.width = `${Math.max(0.5, parseFloat(originalWidth) + pct)}%`;
      else bar.style.left = `${parseFloat(originalLeft) + pct}%`;
    }
    function cleanup() {
      bar.removeEventListener('pointermove', move);
      bar.removeEventListener('pointerup', finish);
      bar.removeEventListener('pointercancel', cancel);
      bar.removeEventListener('lostpointercapture', cancel);
      document.removeEventListener('keydown', escape);
      window.removeEventListener('blur', cancel);
      if (bar.hasPointerCapture(pointerId)) bar.releasePointerCapture(pointerId);
      bar.classList.remove('scheduling');
      gesture = null;
    }
    function cancel(e) {
      if (e?.pointerId !== undefined && e.pointerId !== pointerId) return;
      suppressClick = true;
      cleanup();
      restore();
      drawDependencies();
    }
    function escape(e) { if (e.key === 'Escape') { e.preventDefault(); cancel(); } }
    async function finish(e) {
      if (e.pointerId !== pointerId) return;
      cleanup();
      if (!moved || !delta) { restore(); return; }
      const day = 86400000,
        newStart = new Date(start.getTime() + (resize ? 0 : delta * day)),
        newDue = new Date(due.getTime() + delta * day);
      if (newDue < newStart) {
        restore();
        toast('L’échéance doit rester après la date de début.', true);
        return;
      }
      saving = true;
      bar.setAttribute('aria-busy', 'true');
      try {
        await post(`/tickets/${row.dataset.ticketId}/schedule`, {
          start_date: newStart.toISOString().slice(0, 10),
          due_date: newDue.toISOString().slice(0, 10),
          version: row.dataset.version,
        });
        try { sessionStorage.setItem('sillage.notice', 'Planification mise à jour.'); } catch {}
        location.reload();
      } catch (error) {
        restore();
        toast(error.message, true);
        saving = false;
        bar.removeAttribute('aria-busy');
      }
    }
    bar.addEventListener('pointermove', move);
    bar.addEventListener('pointerup', finish);
    bar.addEventListener('pointercancel', cancel);
    bar.addEventListener('lostpointercapture', cancel);
    document.addEventListener('keydown', escape);
    window.addEventListener('blur', cancel);
    bar.setPointerCapture(pointerId);
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

function initSettingsPreviews(root = document) {
$$('[data-mcp-lifetime]', root).forEach((select) => {
  const update = () =>
    $$('[data-mcp-duration]', select.form).forEach((input) => {
      input.disabled = select.value === 'unlimited';
    });
  select.addEventListener('change', update);
  update();
});

$$('[data-company-form]', root).forEach((form) => {
  const preview = $('.company-preview', form.closest('section'));
  const name = $('[name="company_name"]', form), symbol = $('[name="company_icon"]', form);
  const file = $('[name="company_logo"]', form), remove = $('[name="remove_logo"]', form);
  const status = $('[data-company-preview-status]', form);
  const savedLogo = $('img', preview)?.getAttribute('src');
  let generation = 0;
  const showImage = (src) => {
    const image = document.createElement('img');
    image.className = 'company-logo'; image.alt = 'Aperçu du logo'; image.src = src;
    preview.replaceChildren(image);
  };
  const update = () => {
    const current = ++generation, selected = file.files[0];
    status.textContent = ''; file.setCustomValidity('');
    if (selected) {
      if (selected.size > 2 * 1024 * 1024 || !['image/png','image/jpeg','image/webp'].includes(selected.type)) {
        status.textContent = 'Choisissez une image PNG, JPEG ou WebP de 2 Mo maximum.';
        file.setCustomValidity(status.textContent); return;
      }
      const reader = new FileReader();
      reader.onload = () => { if (generation === current) showImage(reader.result); };
      reader.onerror = () => { if (generation === current) status.textContent = 'Aperçu indisponible : sélectionnez à nouveau le fichier.'; };
      reader.readAsDataURL(selected);
    } else if (savedLogo && !remove?.checked) showImage(savedLogo);
    else preview.textContent = symbol.value.trim() || Array.from(name.value.trim())[0]?.toLocaleUpperCase() || '?';
  };
  name.addEventListener('input', update); symbol.addEventListener('input', update);
  file.addEventListener('change', update); remove?.addEventListener('change', update);
});

$$('[data-avatar-form]', root).forEach((form) => {
  const preview = $('.panel-header .avatar', form.closest('section'));
  const source = preview.cloneNode(true), input = $('[name="avatar"]', form);
  const remove = $('[name="remove_avatar"]', form), name = $('[name="name"]', form);
  const status = $('[data-avatar-status]', form);
  let generation = 0;
  const update = () => {
    const current = ++generation, file = input.files[0];
    status.textContent = ''; input.setCustomValidity('');
    if (file) {
      if (file.size > 2 * 1024 * 1024 || !['image/png','image/jpeg','image/webp'].includes(file.type)) {
        status.textContent = 'Choisissez une image PNG, JPEG ou WebP de 2 Mo maximum.';
        input.setCustomValidity(status.textContent); return;
      }
      const reader = new FileReader();
      reader.onload = () => {
        if (generation !== current) return;
        const image = document.createElement('img'); image.className = 'avatar-image';
        image.src = reader.result; image.alt = 'Aperçu de votre photo'; preview.replaceChildren(image);
      };
      reader.onerror = () => { if (generation === current) status.textContent = 'Aperçu indisponible. Sélectionnez à nouveau le fichier.'; };
      reader.readAsDataURL(file);
    } else if (remove?.checked || !source.querySelector('img')) {
      const parts = name.value.trim().split(/\s+/);
      preview.textContent = (Array.from(parts[0] || '')[0] || '') + (parts.length>1 ? Array.from(parts.at(-1))[0] : '');
    } else preview.replaceChildren(...[...source.childNodes].map((node)=>node.cloneNode(true)));
  };
  input.addEventListener('change',update); remove?.addEventListener('change',update); name.addEventListener('input',update);
});

}
initSettingsPreviews();

const connectionProvider = $('[data-connection-provider-select]');
if (connectionProvider) {
  const updateProviderGuide = (changed = false) => {
    const provider = connectionProvider.value;
    $$('[data-provider-guide]').forEach((guide) => { guide.hidden = guide.dataset.providerGuide !== provider; });
    connectionProvider.setAttribute('aria-describedby', `provider-help-${provider}`);
    const repository = $('[name="repository"]', connectionProvider.form);
    repository.placeholder = provider === 'github' ? 'proprietaire/nom-du-depot' : 'workspace/nom-du-depot';
    if (changed) $('[name="token"]', connectionProvider.form).value = '';
    connectionProvider.dispatchEvent(new Event('provider-selected'));
  };
  connectionProvider.addEventListener('change', () => updateProviderGuide(true));
  $$('[data-connection-provider]').forEach((button) => button.addEventListener('click', () => {
    const changed = connectionProvider.value !== button.dataset.connectionProvider;
    connectionProvider.value = button.dataset.connectionProvider;
    updateProviderGuide(changed);
  }));
  updateProviderGuide();
}

$$('[data-connect-repositories]').forEach((form) => {
  const status = $('[data-connection-status]', form), results = $('[data-connection-results]', form);
  const submit = $('[data-connection-submit]', form), review = $('[data-connection-review]', form);
  const repository = $('[name="repository"]', form), provider = $('[name="provider"]', form);
  const token = $('[name="token"]', form), dialog = form.closest('dialog'), workspace = $('[name="workspace"]', form);
  const discover = $('[data-discover-repositories]', form), more = $('[data-load-repositories]', form);
  const discoveryStatus = $('[data-discovery-status]', form), picker = $('[data-repository-picker]', form);
  const choices = $('[data-repository-choices]', form), counter = $('[data-repository-count]', form);
  const search = $('[data-repository-search]', form), selected = new Set(), loaded = new Set();
  const stop = document.createElement('button');
  stop.type = 'button'; stop.className = 'button subtle'; stop.textContent = 'Arrêter après le dépôt en cours'; stop.hidden = true;
  submit.before(stop);
  let busy = false, stopped = false, listing = false, page = 0, generation = 0, lastProvider = provider.value;
  const selectedCount = () => { counter.textContent = `${loaded.size} dépôt(s) chargé(s) · ${selected.size} sélectionné(s) sur 20 maximum`; };
  const resetDiscovery = () => {
    generation++; selected.clear(); loaded.clear(); page = 0;
    choices.replaceChildren(); picker.hidden = true; more.hidden = true;
    discoveryStatus.textContent = ''; selectedCount();
    $('[data-bitbucket-workspace]', form).hidden = provider.value !== 'bitbucket';
  };
  token.addEventListener('input', resetDiscovery); workspace.addEventListener('input', resetDiscovery);
  const providerChanged = () => { if (provider.value !== lastProvider) { lastProvider = provider.value; resetDiscovery(); } };
  provider.addEventListener('change', providerChanged); provider.addEventListener('provider-selected', providerChanged);
  $('[data-bitbucket-workspace]', form).hidden = provider.value !== 'bitbucket';
  search.addEventListener('input', () => {
    const q = search.value.trim().toLocaleLowerCase();
    $$('[data-repository-option]', choices).forEach((row) => { row.hidden = !row.dataset.repositoryOption.toLocaleLowerCase().includes(q); });
  });
  async function loadRepositories(nextPage) {
    if (busy || listing) return;
    if (!token.value.trim() || !token.reportValidity()) { discoveryStatus.textContent = 'Collez votre jeton d’accès avant de charger les dépôts.'; return; }
    if (nextPage === 1) { resetDiscovery(); search.value = ''; }
    const currentGeneration = generation;
    listing = true; discoveryStatus.classList.remove('danger'); discoveryStatus.textContent = 'Vérification du jeton et chargement des dépôts…';
    [discover, more, submit, provider, token, workspace].forEach((control) => { control.disabled = true; });
    try {
      const result = await post('/connections/repositories', { project_id: $('[name="project_id"]', form).value, provider: provider.value, token: token.value.trim(), workspace: workspace.value.trim(), page: nextPage });
      if (generation !== currentGeneration) return;
      if (!Array.isArray(result.repositories)) throw new Error('La liste de dépôts reçue est invalide.');
      page = result.page; picker.hidden = false;
      for (const item of result.repositories) {
        if (loaded.has(item.repository)) continue;
        loaded.add(item.repository);
        const label = document.createElement('label'), checkbox = document.createElement('input'), text = document.createElement('span');
        label.className = 'repository-option'; label.dataset.repositoryOption = item.repository;
        checkbox.type = 'checkbox'; checkbox.value = item.repository; checkbox.disabled = item.connected === true;
        checkbox.setAttribute('aria-label', item.repository); checkbox.dataset.repositoryChoice = '';
        text.textContent = item.repository;
        checkbox.addEventListener('change', () => {
          if (checkbox.checked && selected.size >= 20) { checkbox.checked = false; discoveryStatus.textContent = 'Vous pouvez sélectionner 20 dépôts par envoi.'; return; }
          if (checkbox.checked) selected.add(item.repository); else selected.delete(item.repository);
          selectedCount();
        });
        label.append(checkbox, text);
        if (item.connected === true || item.private === true) {
          const badge = document.createElement('small'); badge.textContent = item.connected === true ? 'Déjà connecté' : 'Privé'; label.append(badge);
        }
        label.hidden = !item.repository.toLocaleLowerCase().includes(search.value.trim().toLocaleLowerCase());
        choices.append(label);
      }
      more.hidden = result.has_more !== true; selectedCount();
      discoveryStatus.textContent = loaded.size ? 'Jeton accepté pour la lecture de la liste. Cochez les dépôts à connecter.' : 'Jeton accepté, mais aucun dépôt renvoyé. Vérifiez les dépôts autorisés et les éventuelles approbations de votre organisation.';
    } catch (error) { discoveryStatus.textContent = error.message; discoveryStatus.classList.add('danger'); }
    finally { listing = false; [discover, more, submit, provider, token, workspace].forEach((control) => { control.disabled = false; }); }
  }
  discover.addEventListener('click', () => loadRepositories(1));
  more.addEventListener('click', () => loadRepositories(page + 1));
  stop.addEventListener('click', () => { stopped = true; stop.disabled = true; stop.textContent = 'Arrêt après la vérification en cours…'; });
  form.addEventListener('invalid', (event) => {
    status.textContent = event.target === token ? 'Collez votre jeton d’accès complet avant de vérifier.' : 'Vérifiez les champs du formulaire.';
    status.classList.add('danger');
  }, true);
  dialog.addEventListener('cancel', (event) => { if (busy) { event.preventDefault(); stop.click(); } });
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (busy || listing) return;
    const repositories = [...new Set([...selected, ...repository.value.split(/[\r\n,;]+/).map((value) => value.trim()).filter(Boolean)])];
    status.classList.remove('danger'); results.replaceChildren();
    if (!repositories.length || repositories.length > 20) {
      status.textContent = 'Cochez entre 1 et 20 dépôts dans la liste, ou renseignez-les manuellement.'; status.classList.add('danger'); return;
    }
    const key = token.value.trim(), selectedProvider = provider.value;
    const controls = [submit, provider, repository, token, workspace, discover, more, search, $('[data-close]', form), ...$$('[data-repository-choice]:not(:disabled)', choices)];
    busy = true; stopped = false; stop.hidden = false; stop.disabled = false; stop.textContent = 'Arrêter après le dépôt en cours';
    form.setAttribute('aria-busy', 'true'); controls.forEach((control) => { if (control) control.disabled = true; });
    const failures = []; let connected = 0;
    try {
      for (const [index, repo] of repositories.entries()) {
        if (stopped) { failures.push(...repositories.slice(index)); break; }
        status.textContent = `Vérification du dépôt ${index + 1} sur ${repositories.length}…`; submit.textContent = 'Vérification en cours…';
        const item = document.createElement('li'); results.append(item);
        try {
          const result = await post(form.action, { project_id: $('[name="project_id"]', form).value, provider: selectedProvider, repository: repo, token: key });
          item.textContent = `${result.repository} : ${result.shared ? 'connexion partagée liée au projet' : result.existing ? 'déjà connecté' : 'connexion enregistrée'}.`;
          connected++; review.hidden = false;
        } catch (error) { failures.push(repo); item.textContent = `${repo} : ${error.message}`; item.classList.add('danger'); }
      }
      if (!failures.length) { location.assign(review.href); return; }
      selected.clear(); $$('[data-repository-choice]', choices).forEach((checkbox) => { checkbox.checked = false; }); selectedCount();
      repository.value = failures.join('\n'); $('[data-manual-repositories]', form).open = true;
      status.textContent = `${stopped ? 'Vérification interrompue. ' : ''}${connected ? connected + ' dépôt(s) connecté(s). ' : ''}${failures.length} dépôt(s) ${stopped ? 'à reprendre' : 'à corriger'}. Les détails sont affichés ci-dessous.`;
      status.classList.add('danger');
    } finally {
      busy = false; form.removeAttribute('aria-busy'); controls.forEach((control) => { if (control) control.disabled = false; }); stop.hidden = true;
      submit.textContent = 'Vérifier et connecter la sélection';
    }
  });
});

$$('[data-connection-update]').forEach((form) => form.addEventListener('submit', async (event) => {
  event.preventDefault();
  const button = $('button', form), input = $('[name="token"]', form), status = $('[data-update-status]', form);
  if (button.disabled) return;
  button.disabled = true; input.disabled = true; status.classList.remove('danger');
  status.textContent = input.value.trim() ? 'Vérification du nouveau jeton…' : 'Conservation du jeton enregistré…';
  try {
    const result = await post(form.action, { token: input.value.trim() });
    input.value = '';
    status.textContent = result.preserved ? 'Le jeton enregistré a été conservé.' : 'Le nouveau jeton a été vérifié et enregistré.';
  } catch (error) { status.textContent = error.message; status.classList.add('danger'); }
  finally { button.disabled = false; input.disabled = false; }
}));

$$('[data-development-open]').forEach((button) => button.addEventListener('click', () => {
  const details = document.getElementById(button.dataset.developmentOpen);
  if (details) { details.open = true; details.scrollIntoView({block:'nearest',behavior:matchMedia('(prefers-reduced-motion: reduce)').matches?'auto':'smooth'}); }
}));
