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
