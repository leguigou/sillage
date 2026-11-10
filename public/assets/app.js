'use strict';
const $ = (s, root = document) => root.querySelector(s);
const $$ = (s, root = document) => [...root.querySelectorAll(s)];
const csrfToken = $('meta[name="csrf-token"]')?.content;
// initAppearanceMarks() is defined in theme.js so marks are coloured while the page is parsed.
function updateAppearancePicker(picker) {
  const selected = $('input[type="radio"]:checked',picker), color = $('input[type="color"]',picker)?.value;
  if (!selected || !/^#[a-f0-9]{6}$/i.test(color)) return;
  const source = selected.nextElementSibling.querySelector('svg');
  const targets = [$('[data-appearance-preview]',picker),$('summary .work-mark',picker.closest('[data-appearance-row]') || picker)].filter(Boolean);
  targets.forEach(target => {
    target.replaceChildren(source.cloneNode(true));
    target.dataset.markColor = color;
    target.dataset.markIcon = selected.value;
  });
  $('[data-appearance-name]',picker).textContent = selected.getAttribute('aria-label');
  $$('[data-appearance-color]',picker).forEach(button => button.setAttribute('aria-pressed',String(button.dataset.appearanceColor===color)));
  initAppearanceMarks(picker.closest('[data-appearance-row]') || picker);
}
function initAppearancePickers(root = document) { $$('[data-appearance-picker]',root).forEach(updateAppearancePicker); }
initAppearanceMarks();
initAppearancePickers();
document.addEventListener('sillage:dialog-discarded',event=>{initAppearancePickers(event.target);initAppearanceMarks(event.target);});
document.addEventListener('input',event => {
  const picker = event.target.closest('[data-appearance-picker]');
  if(picker) updateAppearancePicker(picker);
});
document.addEventListener('click',event => {
  const button = event.target.closest('[data-appearance-color]');
  if(!button) return;
  const picker = button.closest('[data-appearance-picker]');
  $('input[type="color"]',picker).value = button.dataset.appearanceColor;
  updateAppearancePicker(picker);
});
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
    window.SillageDialogs.show(dialog);
  }
}
document.addEventListener('click', (event) => {
  const opener = event.target.closest('[data-open]');
  if (opener) openDialog(opener.dataset.open);
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
function formatTicketHours(value) {
  if(value===null) return '—';
  let minutes=Math.ceil(Number((Number(value)*12).toFixed(9)))*5;
  if(!minutes) {if(Number(value)<=0) return '0 min';minutes=5;}
  const hours=Math.floor(minutes/60),rest=minutes%60;
  return (hours?hours.toLocaleString('fr-FR')+' h':'')+(hours&&rest?' ':'')+(rest?rest+' min':'');
}
function updateTicketProgress(row,ticket) {
  const meter = $('[data-progress-meter]', row);
  if (!meter) return;
  const percent = ticket.progress_percent + ' %';
  meter.value = ticket.progress_percent;
  meter.textContent = percent;
  $('[data-progress-percent]', row).textContent = percent;
  const estimate = $('[data-progress-estimate]', row);
  const duration = formatTicketHours(ticket.estimated_hours);
  estimate.textContent = ticket.estimated_hours === null ? 'Estimation manquante' : 'Estimé : ' + duration;
  const remaining = $('[data-progress-remaining]', row);
  const remainingDuration = formatTicketHours(ticket.remaining_hours);
  remaining.textContent = ticket.remaining_hours === null ? 'Estimation manquante' : 'Restant : ' + remainingDuration;
  estimate.title = ticket.remaining_hours === null ? '' : 'Reste estimé : ' + remainingDuration;
  remaining.title = ticket.estimated_hours === null ? '' : 'Estimé : ' + duration;
  $('[data-column="estimated_hours"] .ticket-estimate-value', row).textContent = duration;
  const title = $('.ticket-summary-link', row);
  if (title) title.title = ticket.title;
}
function ticketModifiedLabel(value,now=new Date()) {
  const date=new Date(value);if(Number.isNaN(date.getTime())) return {label:'—',title:''};
  const day=d=>Date.UTC(d.getFullYear(),d.getMonth(),d.getDate());
  const days=(day(now)-day(date))/86400000;
  return {label:days===0?'Aujourd’hui':days===1?'Hier':days>1&&days<7?'Il y a '+days+' jours':date.toLocaleDateString('fr-FR'),title:date.toLocaleString('fr-FR')};
}
function refreshTicketModified(root=document) {
  root.querySelectorAll('[data-ticket-modified]').forEach(time=>{const display=ticketModifiedLabel(time.dateTime);time.textContent=display.label;time.title=display.title;});
}
refreshTicketModified();
setInterval(()=>{if(!document.hidden) refreshTicketModified();},60000);
document.addEventListener('visibilitychange',()=>{if(!document.hidden) refreshTicketModified();});
// After an inline edit, a list row whose ticket left the current filters (e.g. set to "En pause") fades out;
// the counters follow and the refresh button is highlighted, since the page now shows fewer rows than it should.
function markListStale() {
  $$('[data-refresh-view]').forEach(button=>{button.classList.add('is-stale');button.title='Des tickets ont été retirés de la liste : rafraîchir pour la compléter';});
}
function removeUnmatchedRow(row,field) {
  let header=row.previousElementSibling;
  while(header && !header.classList.contains('table-group')) header=header.previousElementSibling;
  const select=$('[data-select-ticket]',row);if(select) select.checked=false;
  const counter=$('[data-result-count]');
  if(counter) {const n=Math.max(0,Number(counter.dataset.resultCount)-1);counter.dataset.resultCount=n;counter.textContent=n+' ticket'+(n>1?'s':'');}
  const groupCount=header && $('span',header);
  markListStale();
  let next=row.nextElementSibling;
  while(next && !next.matches('[data-ticket-id]')) next=next.nextElementSibling;
  const finish=()=>{
    if(!row.isConnected) return;
    // Keyboard users keep their place: focus moves to the same field of the next row, or to the refresh button.
    const hadFocus=row.contains(document.activeElement)||document.activeElement===document.body;
    row.remove();selectedTickets();
    if(groupCount) {const n=Number(groupCount.textContent)-1;if(n>0) groupCount.textContent=n;else header.remove();}
    if(hadFocus) ((next?.isConnected&&$(`[data-quick-field="${field}"]`,next))||$('[data-refresh-view]'))?.focus({preventScroll:true});
  };
  if(matchMedia('(prefers-reduced-motion: reduce)').matches) return finish();
  row.classList.add('is-leaving');row.addEventListener('transitionend',finish,{once:true});setTimeout(finish,400);
}
$$('[data-refresh-view]').forEach(button=>button.addEventListener('click',()=>{button.classList.add('is-loading');button.setAttribute('aria-busy','true');location.reload();}));
async function quick(row, field, value) {
  const previousVersion = Number(row.dataset.version);
  const table = row.closest('[data-match-filters]');
  const data = await post(`/tickets/${row.dataset.ticketId}/quick`, {
    field,
    value,
    version: row.dataset.version,
    ...(table ? { filters: table.dataset.matchFilters, filter_project: table.dataset.matchProject } : {}),
  });
  $$(`[data-ticket-id="${row.dataset.ticketId}"]`).forEach(
    (r) => {
      r.dataset.version = data.ticket.version;
      r.dataset.priority = data.ticket.priority;
      r.dataset.status = data.ticket.status;
      updateTicketProgress(r,data.ticket);
      const modified=r.querySelector('[data-ticket-modified]');
      if(modified && data.ticket.updated_at) {modified.dateTime=data.ticket.updated_at.replace(' ','T')+'Z';refreshTicketModified(r);}
      const statusSelect=$('[data-quick-field="status"]',r);
      if(statusSelect && data.ticket.status_options) {
        r.dataset.bulkStatuses=JSON.stringify(data.ticket.status_options);
        statusSelect.replaceChildren(...Object.entries(data.ticket.status_options).map(([value,label])=>new Option(label,value,false,value===data.ticket.status)));
        statusSelect.dataset.statusColor=data.ticket.status_color;
        statusSelect.title=data.ticket.status_label;
        statusSelect.className='status-select workflow-status';initAppearanceMarks(r);
      }
      const mark=$('.priority-mark',r), templates=$('#priority-mark-templates');
      const next=templates && $$('[data-mark-key]',templates.content).find(el=>el.dataset.markKey===data.ticket.priority);
      if(mark && next) { mark.replaceWith(next.cloneNode(true));initAppearanceMarks(r); }
      const assigneeSelect=$('[data-quick-field="assignee_id"]',r), avatarDisplay=$('.assignee-avatar-display',r);
      if(assigneeSelect) {
        assigneeSelect.value=data.ticket.assignee_id||'';assigneeSelect.title=data.ticket.assignee_name||'Non assigné';
        const template=$('#list-assignee-avatars')?.content.querySelector('[data-avatar-user="'+(Number(data.ticket.assignee_id)||0)+'"]');
        if(avatarDisplay && template) avatarDisplay.replaceChildren(...[...template.childNodes].map(node=>node.cloneNode(true)));
      }
      const prioritySelect=$('[data-quick-field="priority"]',r);
      if(prioritySelect && next) {prioritySelect.value=data.ticket.priority;prioritySelect.title=prioritySelect.selectedOptions[0]?.textContent||next.title;}
    },
  );
  updateBulkValues();
  if (Number(data.ticket.version) > previousVersion + 1) {
    sessionStorage.setItem(
      'sillage.notice',
      'Modification enregistrée et automatisations appliquées.',
    );
    location.reload();
  } else if (table && data.matches_filters === false) {
    removeUnmatchedRow(row, field);
    data.removed = true;
  }
  return data;
}
const savedMessage = (data, message) =>
  data.removed ? message + ' Le ticket ne correspond plus aux filtres : il est retiré de la liste.' : message;
let projectEditorLoading=false;
document.addEventListener('click',async event=>{
  const button=event.target.closest('[data-edit-project]');
  if(!button || event.defaultPrevented) return;
  event.preventDefault();
  const host=$('[data-project-settings-host]');
  if(!host || projectEditorLoading) return;
  projectEditorLoading=true;button.disabled=true;button.setAttribute('aria-busy','true');
  try {
    const response=await fetch('/projects/'+encodeURIComponent(button.dataset.editProject)+'/settings',{headers:{Accept:'text/html'},cache:'no-store'});
    if(response.redirected) throw new Error('Votre session a expiré. Reconnectez-vous.');
    if(!response.ok) throw new Error(response.status===403?'Cette action est réservée à un administrateur.':'Les paramètres de ce projet ne sont plus disponibles.');
    const page=new DOMParser().parseFromString(await response.text(),'text/html');
    const dialog=page.getElementById('project-settings-dialog');
    if(!dialog) throw new Error('Impossible d’ouvrir les paramètres du projet.');
    host.replaceChildren(dialog);
    initAppearanceMarks(dialog);initAppearancePickers(dialog);
    openDialog(dialog.id);
  } catch(error) {toast(error.message||'Connexion interrompue. Réessayez.',true);}
  finally {projectEditorLoading=false;button.disabled=false;button.removeAttribute('aria-busy');}
});
document.addEventListener('submit',async event=>{
  const form=event.target;
  if(!form.matches('#project-settings-dialog form') || event.defaultPrevented) return;
  event.preventDefault();
  const button=$('button[type="submit"],button.primary',form);
  if(form.getAttribute('aria-busy')==='true') return;
  form.setAttribute('aria-busy','true');button.disabled=true;
  try {
    const response=await fetch(form.action,{method:'POST',body:new FormData(form),headers:{Accept:'application/json'}});
    if(response.redirected || !(response.headers.get('content-type')||'').includes('application/json')) throw new Error('Votre session a expiré. Rechargez la page.');
    const data=await response.json();
    if(!response.ok) throw new Error(data.error || 'Enregistrement impossible.');
    const project=data.project, selected=$$('input[type="radio"]',form).find(input=>input.value===project.icon);
    const svg=selected?.nextElementSibling.querySelector('svg');
    $$('[data-project-id]').filter(mark=>mark.dataset.projectId===String(project.id)).forEach(mark=>{
      mark.dataset.projectColor=project.color;mark.dataset.markIcon=project.icon;
      if(svg) mark.replaceChildren(svg.cloneNode(true));
      const link=mark.closest('a');
      const name=link && $('.project-name,strong',link);
      if(name) name.textContent=project.name;
      if(link?.classList.contains('settings-project')) $('small',link).textContent=project.description;
      if(link?.classList.contains('project-link')) {
        const label=project.name+' ('+project.project_key+')';
        link.title=label;link.setAttribute('aria-label',label);
      }
      const crumb=mark.closest('.project-crumb');
      if(crumb) crumb.replaceChildren(mark,document.createTextNode(project.name));
    });
    $$('[data-edit-project]').filter(opener=>opener.dataset.editProject===String(project.id)).forEach(opener=>{
      if(opener.closest('.settings-project-row')) opener.setAttribute('aria-label','Modifier le projet '+project.name);
    });
    initAppearanceMarks();window.SillageDialogs.complete(form.closest('dialog'));toast('Projet enregistré.');
  } catch(error) { toast(error.message || 'Connexion interrompue. Vérifiez l’état avant de réessayer.',true); }
  finally { form.removeAttribute('aria-busy');button.disabled=false; }
});
$$('[data-quick-field]').forEach((input) =>
  input.addEventListener('change', async () => {
    const row = input.closest('[data-ticket-id]');
    input.disabled = true;
    try {
      const data = await quick(row, input.dataset.quickField, input.value);
      toast(savedMessage(data, 'Modification enregistrée.'));
      if (input.dataset.quickField === 'status') {
        input.className = 'status-select workflow-status';
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
    const title = await window.SillageDialogs.prompt({title:'Modifier le résumé',label:'Résumé',value:button.dataset.value,maxLength:240,accept:'Enregistrer'});
    if (!title || title === button.dataset.value) return;
    try {
      const data = await quick(button.closest('[data-ticket-id]'), 'title', title);
      button.dataset.value = title;
      $('a', button.closest('td')).textContent = title;
      toast(savedMessage(data, 'Résumé mis à jour.'));
    } catch (error) {
      toast(error.message, true);
    }
  }),
);
function selectedTickets() {
  const n = $$('[data-select-ticket]:checked').length;
  const bulk = $('#bulk-form');
  if (bulk) bulk.hidden = n === 0;
  const counter = $('[data-selected-count]');
  if (counter) counter.textContent = n;
  updateBulkValues();
}
function updateBulkValues() {
  const form=$('#bulk-form'),source=$('#bulk-value-options');if(!form || !source) return;
  const field=form.elements.field.value,select=form.elements.value,previous=select.value;
  const all=JSON.parse(source.textContent);let options=all[field]||{};
  if(field==='status') {
    const selected=$$('[data-select-ticket]:checked').map(input=>JSON.parse(input.closest('[data-ticket-id]').dataset.bulkStatuses));
    if(selected.length) options=Object.fromEntries(Object.entries(selected[0]).filter(([key])=>selected.every(values=>Object.hasOwn(values,key))));
  }
  const entries=Object.entries(options);if(field==='assignee_id'||field==='team_id') entries.sort(([a],[b])=>a==='none'?-1:b==='none'?1:0);
  select.replaceChildren(new Option(entries.length?'Choisir une valeur…':'Aucune valeur commune autorisée',''),...entries.map(([value,label])=>new Option(label,value)));
  if(Object.hasOwn(options,previous)) select.value=previous;
  $('button.primary',form).disabled=!Object.keys(options).length;
}
$('#bulk-form [name="field"]')?.addEventListener('change',()=>{ $('#bulk-form').elements.value.value='';updateBulkValues(); });
updateBulkValues();
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
let reportLayout=null;
const columnLayoutKey=$('.ticket-table')?.dataset.columnLayoutKey;
const reportOrderSave=$('[data-report-order-save]');
try {reportLayout=JSON.parse($('.ticket-table[data-report-layout]')?.dataset.reportLayout||'null');} catch {}
try {
  const stored=JSON.parse(localStorage.getItem('sillage.preferences') || '{}');
  if(stored && typeof stored==='object' && !Array.isArray(stored)) preferences=stored;
} catch {}
function listPriorityMode() {
  const mode = preferences['column-priority.'+columnLayoutKey];
  if (['label','icon','code'].includes(mode)) return mode;
  return preferences['column-label.'+columnLayoutKey+'.priority'] === false ? 'icon' : 'label';
}
function applyPreferences() {
  applyColumnOrder();
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
    const key=el.dataset.columnToggle;
    const saved=columnLayoutKey?preferences['column-visibility.'+columnLayoutKey]?.[key]:undefined;
    const defaultVisible = reportLayout?.columns
      ? reportLayout.columns.includes(key)
      : preferences['column.'+key] ?? el.defaultChecked;
    el.checked = key==='title' || (typeof saved==='boolean' ? saved : defaultVisible);
    $$(`[data-column="${el.dataset.columnToggle}"]`).forEach((cell) => (cell.hidden = !el.checked));
  });
  const table=$('.ticket-table');
  if(table) {
    const mode = preferences['column-progress.'+columnLayoutKey] ?? 'both';
    const showBar = ['both', 'bar', 'bar-remaining'].includes(mode);
    const showEstimate = ['both', 'estimate'].includes(mode);
    const showRemaining = ['bar-remaining', 'remaining'].includes(mode);
    $$('[data-progress-bar]', table).forEach(bar => bar.hidden = !showBar);
    $$('[data-progress-estimate]', table).forEach(estimate => estimate.hidden = !showEstimate);
    $$('[data-progress-remaining]', table).forEach(remaining => remaining.hidden = !showRemaining);
    const typeLabel=preferences['column-label.'+columnLayoutKey+'.type']===true;
    const priorityMode = listPriorityMode();
    const assigneeAvatar = preferences['column-label.'+columnLayoutKey+'.assignee'] === false;
    $('[data-list-label-visible]').checked = typeLabel;
    $('[data-list-priority-mode]').value = priorityMode;
    $('[data-list-assignee-mode]').value = assigneeAvatar ? 'avatar' : 'name';
    $('[data-list-progress-mode]').value = mode;
    table.classList.toggle('priority-compact', priorityMode !== 'label');
    table.classList.toggle('priority-code-only', priorityMode === 'code');
    table.classList.toggle('assignee-avatar-only', assigneeAvatar);
    $$('[data-quick-field="assignee_id"]',table).forEach(select=>select.title=select.selectedOptions[0]?.textContent||'Non assigné');
    $$('[data-type-label]',table).forEach(label=>label.hidden=!typeLabel);
    $$('[data-column="priority"] select', table).forEach(select => {
      for (const option of select.options) {
        const {priorityCode, priorityLabel} = option.dataset;
        option.textContent = priorityMode === 'code' ? priorityCode + ' · ' + priorityLabel : priorityLabel;
      }
      select.title = select.selectedOptions[0]?.textContent || 'Priorité';
    });
  }
  if (reportOrderSave) {
    reportOrderSave.hidden = String(reportColumnOrder()) === String(reportLayout.savedOrder);
  }
  $$('.table-group td').forEach(cell=>cell.colSpan=$$('.ticket-table thead th:not([hidden])').length);
  const age = Number(preferences['done-age'] || 0);
  $$('.ticket-card').forEach((c) => {
    const completed = c.dataset.completed
      ? new Date(c.dataset.completed.replace(' ', 'T') + 'Z').getTime()
      : null;
    c.hidden = Boolean(
      age && completed && c.dataset.statusCategory === 'done' && Date.now() - completed > age * 86400000,
    );
  });
}
function applyColumnOrder() {
  const table=$('.ticket-table');if(!table) return;
  const headers=$$('thead th[data-column]',table),keys=headers.map(header=>header.dataset.column);
  const saved=preferences['column-order.'+table.dataset.columnLayoutKey];
  const chosen=Array.isArray(saved)?[...new Set(saved.filter(key=>keys.includes(key)))]:[];
  if (chosen.includes('title') && !chosen.includes('progress_percent')) {
    chosen.splice(chosen.indexOf('title')+1, 0, 'progress_percent');
  }
  const order=[...chosen,...keys.filter(key=>!chosen.includes(key))];
  $$('thead tr,tbody tr[data-ticket-id]',table).forEach(row=>{
    const cells=new Map([...row.children].filter(cell=>cell.dataset.column).map(cell=>[cell.dataset.column,cell]));
    const end=row.lastElementChild;order.forEach(key=>{const cell=cells.get(key);if(cell) row.insertBefore(cell,end);});
  });
  const picker=$('[data-list-column-options]');if(!picker) return;
  order.forEach(key=>{const row=[...picker.children].find(node=>node.dataset.listColumn===key);if(row) picker.append(row);});
  [...picker.children].forEach((row,index)=>{const up=$('[data-column-move="up"]',row),down=$('[data-column-move="down"]',row);if(up) up.disabled=index===0;if(down) down.disabled=index===picker.children.length-1;});
}
function columnKeys(table) {
  return $$('thead th[data-column]', table).map(header => header.dataset.column);
}
function reportColumnOrder() {
  const savedOrder = reportLayout.savedOrder;
  return columnKeys($('.ticket-table')).filter(key => savedOrder.includes(key));
}
reportOrderSave?.addEventListener('click', async () => {
  const form = $('#save-view-dialog [data-personal-report]');
  const body = new URLSearchParams({
    _csrf: csrfToken,
    mode: 'column_order',
    project_id: form.elements.project_id.value,
    view_id: form.elements.view_id.value,
    revision: form.elements.revision.value,
    column_order: JSON.stringify(reportColumnOrder()),
  });
  reportOrderSave.disabled = true;
  try {
    const response = await fetch('/views', {method:'POST', body, headers:{Accept:'application/json'}});
    if (response.redirected || !response.headers.get('content-type')?.includes('application/json')) {
      throw new Error('Votre session a expiré. Rechargez la page.');
    }
    const result = await response.json();
    if (!response.ok) throw new Error(result.error || 'Enregistrement impossible.');
    reportOrderSave.hidden = true;
    try { sessionStorage.setItem('sillage.notice', 'Ordre des colonnes enregistré.'); } catch {}
    location.assign(result.url);
  } catch (error) {
    toast(error.message, true);
  } finally {
    reportOrderSave.disabled = false;
  }
});
function saveColumnOrder(table, keys) {
  preferences['column-order.'+table.dataset.columnLayoutKey] = keys;
  savePreferences();
}
document.addEventListener('click', event => {
  const button = event.target.closest('[data-column-move]');
  if (!button || button.disabled) return;
  const table = $('.ticket-table');
  const row = button.closest('[data-list-column]');
  if (!table || !row) return;
  const keys = columnKeys(table);
  const index = keys.indexOf(row.dataset.listColumn);
  const next = index + (button.dataset.columnMove === 'up' ? -1 : 1);
  if (index < 0 || next < 0 || next >= keys.length) return;
  [keys[index], keys[next]] = [keys[next], keys[index]];
  saveColumnOrder(table, keys);
  button.focus({preventScroll:true});
});
const listHeader = $('.ticket-table thead');
if (listHeader) {
  const table = listHeader.closest('table');
  let source = null;
  let target = null;
  let position = null;

  function clearDropMarker() {
    target?.removeAttribute('data-column-drop');
    target = null;
  }
  function finishColumnDrag() {
    clearDropMarker();
    source?.classList.remove('column-drag-source');
    source = null;
  }

  listHeader.addEventListener('dragstart', event => {
    if (!event.target.closest('[data-list-column-drag]')) return;
    source = event.target.closest('th[data-column]');
    source.classList.add('column-drag-source');
    event.dataTransfer.clearData();
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData('text/plain', source.dataset.column);
  });

  listHeader.addEventListener('dragover', event => {
    if (!source) return;
    const header = event.target.closest('th[data-column]');
    if (!header || header === source) {
      clearDropMarker();
      return;
    }
    event.preventDefault();
    event.dataTransfer.dropEffect = 'move';
    const rect = header.getBoundingClientRect();
    position = event.clientX < rect.left + rect.width / 2 ? 'before' : 'after';
    if (target !== header) clearDropMarker();
    target = header;
    target.dataset.columnDrop = position;
  });

  listHeader.addEventListener('dragleave', event => {
    if (target && !target.contains(event.relatedTarget)) clearDropMarker();
  });

  listHeader.addEventListener('drop', event => {
    if (!source || !target || !target.contains(event.target)) return;
    event.preventDefault();
    const key = source.dataset.column;
    const keys = columnKeys(table).filter(column => column !== key);
    keys.splice(keys.indexOf(target.dataset.column) + (position === 'after' ? 1 : 0), 0, key);
    finishColumnDrag();
    saveColumnOrder(table, keys);
  });

  listHeader.addEventListener('dragend', finishColumnDrag);
}
$('[data-list-label-visible]')?.addEventListener('change', event => {
  preferences['column-label.'+columnLayoutKey+'.type'] = event.target.checked;
  savePreferences();
});
$('[data-list-assignee-mode]')?.addEventListener('change', event => {
  preferences['column-label.'+columnLayoutKey+'.assignee'] = event.target.value === 'name';
  savePreferences();
});
$('[data-list-priority-mode]')?.addEventListener('change', event => {
  preferences['column-priority.'+columnLayoutKey] = event.target.value;
  savePreferences();
});
$('[data-list-progress-mode]')?.addEventListener('change', event => {
  preferences['column-progress.'+columnLayoutKey] = event.target.value;
  savePreferences();
});
function savePreferences() {
  try {
    localStorage.setItem('sillage.preferences', JSON.stringify(preferences));
  } catch {}
  applyPreferences();
  const dialog=$('#view-options-dialog');if(dialog?.open) window.SillageDialogs.commit(dialog);
}
$$('[data-preference]').forEach((el) =>
  el.addEventListener('change', () => {
    preferences[el.dataset.preference] = el.value;
    savePreferences();
  }),
);
$$('[data-column-toggle]').forEach((el) =>
  el.addEventListener('change', () => {
    const key=el.dataset.columnToggle;
    if(columnLayoutKey) {
      const name='column-visibility.'+columnLayoutKey,stored=preferences[name];
      const values=stored && typeof stored==='object' && !Array.isArray(stored)?stored:{};
      preferences[name]={...values,[key]:key==='title'?true:el.checked};
    } else preferences[`column.${key}`]=el.checked;
    savePreferences();
  }),
);
try { applyPreferences(); } finally { document.documentElement.classList.remove('list-layout-pending'); }
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
  const circumference = 2 * Math.PI * 46;
  let offset = 0;
  rows.forEach((row) => {
    const length = (Number(row.n) / total) * circumference;
    const c = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
    Object.entries({
      cx: 60,
      cy: 60,
      r: 46,
      stroke: /^#[a-f0-9]{6}$/i.test(row.color || '') ? row.color : '#7166eb',
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
  dialog.addEventListener('sillage:dialog-discarded',()=>{lastProvider=provider.value;resetDiscovery();});
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
  const button = $('button:not([type="button"])', form), input = $('[name="token"]', form), status = $('[data-update-status]', form);
  if (button.disabled) return;
  button.disabled = true; input.disabled = true; status.classList.remove('danger');
  form.setAttribute('aria-busy','true');
  status.textContent = input.value.trim() ? 'Vérification du nouveau jeton…' : 'Conservation du jeton enregistré…';
  try {
    const result = await post(form.action, { token: input.value.trim() });
    input.value = '';
    status.textContent = result.preserved ? 'Le jeton enregistré a été conservé.' : 'Le nouveau jeton a été vérifié et enregistré.';
    window.SillageDialogs.commit(form);
  } catch (error) { status.textContent = error.message; status.classList.add('danger'); }
  finally { button.disabled = false; input.disabled = false;form.removeAttribute('aria-busy'); }
}));
