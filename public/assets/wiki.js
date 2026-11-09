// Wiki screens (app/Views/wiki.php): confirmations, local dates and the page editor.
// The editor is window.SillageWikiEditor (public/assets/vendor/milkdown, built from tools/wiki-editor).
// Without it, the Markdown textarea and the plain POST form keep working.
(() => {
  'use strict';
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const notify = (message, error = false) => (typeof window.toast === 'function' ? window.toast(message, error) : null);

  // Destructive forms ask through SillageDialogs, never window.confirm.
  document.addEventListener('submit', async (event) => {
    const form = event.target.closest('form[data-wiki-confirm]');
    if (!form || form.dataset.confirmed) return;
    event.preventDefault();
    const accepted = await window.SillageDialogs.confirm({ title: 'Confirmer', message: form.dataset.wikiConfirm, accept: 'Confirmer', danger: true });
    if (!accepted) return;
    form.dataset.confirmed = '1';
    form.requestSubmit(event.submitter || undefined);
  });

  // Dates are stored in UTC; show them in the browser's time zone.
  const formatter = new Intl.DateTimeFormat('fr-FR', { dateStyle: 'short', timeStyle: 'short' });
  $$('time[data-wiki-date]').forEach((time) => {
    const date = new Date(time.getAttribute('datetime'));
    if (!Number.isNaN(date.getTime())) { time.title = time.textContent; time.textContent = formatter.format(date); }
  });

  // Import: each file is previewed (converted, images stored), then imported one request at a time,
  // so the server upload limit applies per file. The preview HTML comes from WikiRenderer (safe mode).
  const importForm = $('form[data-wiki-import]');
  if (importForm) {
    const input = $('input[type=file]', importForm);
    const results = $('[data-wiki-import-results]');
    const footer = $('[data-wiki-import-footer]');
    const summary = $('[data-wiki-import-summary]');
    const run = $('[data-wiki-import-run]');
    const csrfImport = $('input[name="_csrf"]', importForm).value;
    let items = [];

    const element = (tag, attributes = {}, text = '') => {
      const node = document.createElement(tag);
      for (const [key, value] of Object.entries(attributes)) node.setAttribute(key, value);
      if (text) node.textContent = text;
      return node;
    };
    async function send(url, file, fields = {}) {
      const body = new FormData();
      body.set('_csrf', csrfImport);
      body.set('document', file, file.name);
      for (const [key, value] of Object.entries(fields)) body.set(key, value);
      const response = await fetch(url, { method: 'POST', headers: { Accept: 'application/json' }, body });
      let result = {};
      try { result = await response.json(); } catch { throw new Error(response.status === 413 ? 'Fichier trop volumineux pour le serveur.' : 'Réponse inattendue du serveur.'); }
      if (!response.ok || result.error) throw new Error(result.error || 'La conversion a échoué.');
      return result;
    }
    function refreshFooter() {
      const selected = items.filter((item) => item.ok && item.checkbox.checked && !item.done);
      footer.hidden = !items.some((item) => item.ok && !item.done);
      run.disabled = selected.length === 0;
      summary.textContent = selected.length + ' page' + (selected.length > 1 ? 's' : '') + ' à importer dans « ' + (importForm.elements.folder.selectedOptions[0]?.textContent || 'Wiki') + ' ».';
    }

    importForm.addEventListener('submit', async (event) => {
      event.preventDefault();
      const files = [...input.files];
      if (!files.length) return;
      results.replaceChildren();items = [];footer.hidden = true;
      const max = Number(importForm.dataset.max);
      for (const file of files) {
        const card = element('article', { class: 'panel wiki-import-item' });
        const head = element('header', { class: 'wiki-import-head' });
        head.append(element('strong', {}, file.name), element('span', { class: 'help-text' }, 'Conversion…'));
        card.append(head);
        results.append(card);
        const item = { file, card, ok: false, done: false };
        items.push(item);
        try {
          if (file.size > max) throw new Error('Fichier de plus de ' + Math.floor(max / 1048576) + ' Mo.');
          const preview = await send('/wiki/import/preview', file);
          item.ok = true;
          const formats = { confluence: 'Export Confluence', html: 'Page HTML', markdown: 'Markdown' };
          head.lastChild.textContent = (formats[preview.format] || preview.format) + ' · ' + preview.images + ' image' + (preview.images > 1 ? 's' : '');
          const include = element('label', { class: 'checkbox' });
          item.checkbox = element('input', { type: 'checkbox', checked: 'checked' });
          include.append(item.checkbox, document.createTextNode(' Importer cette page'));
          item.checkbox.addEventListener('change', refreshFooter);
          const titleLabel = element('label', {}, 'Titre de la page');
          item.title = element('input', { maxlength: '200', required: 'required' });
          item.title.value = preview.title;
          titleLabel.append(item.title);
          card.append(include, titleLabel);
          if (preview.warnings.length) {
            const list = element('ul', { class: 'wiki-import-warnings' });
            preview.warnings.forEach((warning) => list.append(element('li', {}, warning)));
            card.append(list);
          }
          const rendered = element('details', { open: 'open' });
          rendered.append(element('summary', {}, 'Aperçu'));
          const content = element('div', { class: 'wiki-content wiki-import-preview' });
          content.innerHTML = preview.html; // produced by WikiRenderer (Parsedown safe mode + DOM cleanup)
          rendered.append(content);
          const source = element('details');
          source.append(element('summary', {}, 'Markdown produit'), element('pre', { class: 'wiki-import-markdown' }, preview.markdown));
          card.append(rendered, source);
          item.status = element('p', { class: 'help-text', role: 'status' });
          card.append(item.status);
        } catch (error) {
          head.lastChild.textContent = error.message;
          card.classList.add('wiki-import-failed');
        }
        refreshFooter();
      }
    });
    importForm.elements.folder.addEventListener('change', refreshFooter);

    run.addEventListener('click', async () => {
      run.disabled = true;
      let created = 0;
      for (const item of items.filter((i) => i.ok && i.checkbox.checked && !i.done)) {
        item.status.textContent = 'Import…';
        try {
          const saved = await send('/wiki/import', item.file, { folder: importForm.elements.folder.value, title: item.title.value });
          item.done = true;created++;
          item.status.replaceChildren(document.createTextNode('Page créée : '), element('a', { href: saved.url }, saved.title));
          item.checkbox.disabled = true;item.title.disabled = true;
        } catch (error) {
          item.status.textContent = error.message;
        }
      }
      refreshFooter();
      if (created) {
        const folder = importForm.elements.folder.value;
        summary.replaceChildren(document.createTextNode(created + ' page' + (created > 1 ? 's' : '') + ' importée' + (created > 1 ? 's' : '') + '. '), element('a', { href: '/wiki' + (folder ? '?folder=' + encodeURIComponent(folder) : '') }, 'Ouvrir le dossier'));
        footer.hidden = false;
      }
    });
  }

  const form = $('form[data-wiki-editor]');
  if (!form) return;
  const source = $('[data-wiki-markdown]', form);
  const sourceLabel = $('[data-wiki-source]', form);
  const visual = $('[data-wiki-visual]', form);
  const status = $('[data-wiki-editor-status]', form);
  const conflict = $('[data-wiki-conflict]', form);
  const saveButton = $('[data-wiki-save]', form);
  const llmTarget = $('[data-wiki-llm-target]', form);
  let editor = null, mode = 'markdown', dirty = false, saving = false, force = false;

  const markDirty = () => { dirty = true; };
  source.addEventListener('input', markDirty);

  function content() { return mode === 'visual' && editor ? editor.getMarkdown() : source.value; }

  // Images: stored by the server as "_assets/<hash>.<ext>" (relative in the .md), displayed from /wiki/assets/.
  const csrf = $('input[name="_csrf"]', form).value;
  const imageUrl = (url) => (url.startsWith('_assets/') ? '/wiki/assets/' + url.slice(8) : url);
  async function uploadImage(file) {
    if (file.size > Number(form.dataset.imageMax)) throw new Error('L’image dépasse la taille autorisée.');
    const body = new FormData();
    body.set('_csrf', csrf);
    body.set('image', file, file.name || 'image');
    status.textContent = 'Envoi de l’image…';
    const response = await fetch(form.dataset.imageUrl, { method: 'POST', headers: { Accept: 'application/json' }, body });
    let result = {};
    try { result = await response.json(); } catch { result = {}; }
    if (!response.ok || !result.src) {
      const message = result.error || 'L’image n’a pas pu être envoyée.';
      status.textContent = message; notify(message, true);
      throw new Error(message);
    }
    status.textContent = 'Image ajoutée. Enregistrez la page pour la conserver.';
    markDirty();
    return result.src;
  }

  async function startVisual() {
    visual.hidden = false;
    sourceLabel.hidden = true;
    editor = await window.SillageWikiEditor.create({
      root: visual, markdown: source.value, onChange: (md) => { source.value = md; markDirty(); },
      uploadImage, imageUrl,
      onImageError: () => { status.textContent = 'Une image ne s’affiche pas : seules les images ajoutées au wiki sont visibles (les adresses externes sont bloquées).'; },
    });
    mode = 'visual';
  }

  // Markdown mode: a pasted or dropped image is uploaded and inserted at the cursor.
  async function insertFiles(files) {
    for (const file of [...files].filter((f) => f.type.startsWith('image/'))) {
      try {
        const src = await uploadImage(file);
        source.setRangeText('![](' + src + ')', source.selectionStart, source.selectionEnd, 'end');
        source.dispatchEvent(new Event('input', { bubbles: true }));
      } catch { /* message already shown */ }
    }
  }
  source.addEventListener('paste', (event) => { if ([...event.clipboardData.files].some((f) => f.type.startsWith('image/'))) { event.preventDefault(); insertFiles(event.clipboardData.files); } });
  source.addEventListener('drop', (event) => { if ([...event.dataTransfer.files].some((f) => f.type.startsWith('image/'))) { event.preventDefault(); insertFiles(event.dataTransfer.files); } });

  // Callouts ("> [!NOTE]" …) are inserted at the cursor, in both modes.
  const callout = $('[data-wiki-callout]', form);
  callout.addEventListener('change', () => {
    const type = callout.value;
    callout.value = '';
    if (!type) return;
    const block = '> [!' + type + ']\n> Votre texte ici.';
    if (mode === 'visual' && editor) { editor.replaceSelection(block); editor.focus(); }
    else { source.setRangeText('\n' + block + '\n', source.selectionStart, source.selectionEnd, 'end'); source.dispatchEvent(new Event('input', { bubbles: true })); source.focus(); }
    markDirty();
  });

  async function switchMode(next) {
    if (next === mode) return;
    if (next === 'markdown') {
      source.value = editor.getMarkdown();
      editor.destroy(); editor = null;
      visual.replaceChildren(); visual.hidden = true; sourceLabel.hidden = false; mode = 'markdown';
      source.focus();
    } else {
      await startVisual();
      editor.focus();
    }
    $$('[data-wiki-mode]', form).forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.wikiMode === mode)));
  }

  if (window.SillageWikiEditor) {
    $('[data-wiki-modes]', form).hidden = false;
    startVisual().catch((error) => {
      visual.hidden = true; sourceLabel.hidden = false; mode = 'markdown';
      status.textContent = 'L’éditeur visuel n’a pas pu démarrer ; le texte Markdown reste modifiable. (' + error.message + ')';
    });
    $$('[data-wiki-mode]', form).forEach((button) => button.addEventListener('click', () => switchMode(button.dataset.wikiMode)));
  }

  // Writing help: reuses the Sillage assistant (public/assets/llm.js) on the selection or the page.
  const assist = $('[data-wiki-assist]', form);
  if (assist && window.SillageAssistant && document.body.dataset.role !== 'viewer') {
    assist.hidden = false;
    let range = null;
    assist.addEventListener('click', () => {
      let text = '';
      if (mode === 'visual' && editor) text = editor.selectedText();
      else if (source.selectionStart !== source.selectionEnd) { range = [source.selectionStart, source.selectionEnd]; text = source.value.slice(range[0], range[1]); }
      else range = [source.selectionStart, source.selectionStart];
      if (!text) text = content();
      if (text.length > 6000) { notify('Sélectionnez un passage de moins de 6 000 caractères pour utiliser l’aide à la rédaction.', true); return; }
      llmTarget.value = text;
      window.SillageAssistant.open(llmTarget, { mode: 'rewrite', text });
    });
    llmTarget.addEventListener('input', () => {
      const proposal = llmTarget.value;
      if (mode === 'visual' && editor) editor.replaceSelection(proposal);
      else if (range) { source.setRangeText(proposal, range[0], range[1], 'end'); source.dispatchEvent(new Event('input', { bubbles: true })); }
      markDirty();
      status.textContent = 'Proposition insérée. Relisez-la avant d’enregistrer.';
    });
  }

  async function save() {
    if (saving) return;
    saving = true; saveButton.disabled = true; form.setAttribute('aria-busy', 'true');
    status.textContent = 'Enregistrement…';
    const body = new FormData(form);
    body.set('content', content());
    if (force) body.set('force', '1');
    try {
      const response = await fetch(form.action, { method: 'POST', headers: { Accept: 'application/json' }, body });
      let result = {};
      try { result = await response.json(); } catch { throw new Error(response.status === 403 ? 'Accès refusé ou session expirée. Copiez votre texte puis rechargez la page.' : 'Réponse inattendue du serveur.'); }
      if (response.status === 409 && result.conflict) {
        conflict.hidden = false;
        $('[data-wiki-conflict-message]', conflict).textContent = result.error + ' Votre texte n’a pas été enregistré.';
        $('[data-wiki-conflict-diff]', conflict).href = result.diff_url;
        status.textContent = '';
        return;
      }
      if (!response.ok || result.error) throw new Error(result.error || 'L’enregistrement a échoué.');
      dirty = false;
      window.location.assign(result.url);
    } catch (error) {
      status.textContent = error.message;
      notify(error.message, true);
    } finally {
      saving = false; saveButton.disabled = false; form.removeAttribute('aria-busy'); force = false;
    }
  }

  form.addEventListener('submit', (event) => { event.preventDefault(); save(); });
  $('[data-wiki-force]', form).addEventListener('click', () => { force = true; conflict.hidden = true; save(); });
  $('[data-wiki-copy]', form).addEventListener('click', async () => {
    try { await navigator.clipboard.writeText(content()); notify('Votre texte est copié.'); }
    catch { notify('Copie impossible : basculez en mode Markdown pour sélectionner le texte.', true); }
  });
  document.addEventListener('keydown', (event) => {
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') { event.preventDefault(); save(); }
  });
  $('[data-wiki-cancel]', form).addEventListener('click', async (event) => {
    if (!dirty) return;
    event.preventDefault();
    const href = event.currentTarget.href;
    const leave = await window.SillageDialogs.confirm({ title: 'Abandonner les modifications ?', message: 'Les modifications non enregistrées de cette page seront perdues.', accept: 'Abandonner les modifications', danger: true });
    if (leave) { dirty = false; window.location.assign(href || form.dataset.viewUrl); }
  });
  window.addEventListener('beforeunload', (event) => { if (dirty) { event.preventDefault(); event.returnValue = ''; } });
})();
