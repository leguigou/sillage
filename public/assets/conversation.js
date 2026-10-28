'use strict';

document.addEventListener('submit', async (event) => {
  const form = event.target;
  const row = form.closest('[data-attachment-id]');
  if (!row || !form.action.endsWith('/delete')) return;
  event.preventDefault();
  const button = $('button', form);
  if (button.disabled) return;
  button.disabled = true;
  try {
    const response = await fetch(form.action, { method: 'POST', body: new FormData(form), headers: { Accept: 'application/json' } });
    const result = await response.json();
    if (!response.ok || !result.ok) throw new Error(result.error || 'Suppression impossible.');
    $$(`img[src="/attachments/${row.dataset.attachmentId}/preview"]`).forEach((image) => image.remove());
    row.remove();
    toast('Pièce jointe supprimée.');
  } catch (error) { button.disabled = false; toast(error.message, true); }
});

// Shared upload response: every successful file is returned even in a partial failure.
async function uploadTicketFiles(url, files) {
  if (files.length > 20) throw new Error('20 fichiers maximum par envoi.');
  const outcome = { files: [], errors: [] };
  // One file per request keeps the batch below PHP's aggregate POST limit.
  for (const [index, file] of files.entries()) {
    const data = new FormData();
    data.append('_csrf', csrfToken);
    data.append('files[]', file, file.name);
    try {
      const response = await fetch(url, { method: 'POST', body: data, headers: { Accept: 'application/json' } });
      const result = await response.json();
      if (!Array.isArray(result.files)) throw new Error(result.error || 'Envoi impossible. Réessayez.');
      outcome.files.push(...result.files.map((saved) => ({ ...saved, index })));
      outcome.errors.push(...result.errors.map((error) => ({ ...error, index, message: `${file.name} : ${error.message}` })));
    } catch (error) { outcome.errors.push({ index, message: `${file.name} : ${error.message}` }); }
  }
  return outcome;
}

function appendAttachment(file) {
  const list = $('[data-attachment-list]');
  if (!list || $(`[data-attachment-id="${file.id}"]`, list)) return;
  const row = document.createElement('div');
  row.className = 'attachment-row';
  row.dataset.attachmentId = file.id;
  const link = document.createElement('a');
  link.className = 'attachment';
  link.href = `/attachments/${file.id}`;
  const name = document.createElement('span');
  name.textContent = file.original_name;
  const size = document.createElement('small');
  size.textContent = `${Math.ceil(file.size_bytes / 1024)} Ko`;
  name.append(size);
  link.append(name);
  const form = document.createElement('form');
  form.method = 'post';
  form.action = `/attachments/${file.id}/delete`;
  const csrf = document.createElement('input');
  csrf.type = 'hidden'; csrf.name = '_csrf'; csrf.value = csrfToken;
  const button = document.createElement('button');
  button.className = 'tiny-button danger';
  button.textContent = '×';
  button.setAttribute('aria-label', `Supprimer ${file.original_name}`);
  button.dataset.confirm = 'Supprimer définitivement cette pièce jointe ? Elle disparaîtra aussi des images de la conversation.';
  form.append(csrf, button);
  row.append(link, form);
  list.append(row);
}

$$('[data-file-queue]').forEach((form) => {
  const input = $('input[type="file"]', form), zone = $('[data-dropzone]', form);
  const list = $('[data-pending-files]', form), submit = $('[data-upload-submit]', form);
  const status = $('[data-upload-status]', form);
  let queue = [], busy = false;
  input.required = false;
  const render = () => {
    list.replaceChildren();
    queue.forEach((file, index) => {
      const item = document.createElement('li'), name = document.createElement('span');
      name.textContent = `${file.name} · ${Math.ceil(file.size / 1024)} Ko`;
      const remove = document.createElement('button');
      remove.type = 'button'; remove.className = 'tiny-button'; remove.textContent = '×';
      remove.setAttribute('aria-label', `Retirer ${file.name}`); remove.disabled = busy;
      remove.addEventListener('click', () => { queue.splice(index, 1); render(); });
      item.append(name, remove); list.append(item);
    });
    submit.hidden = queue.length === 0;
    submit.disabled = busy;
    submit.textContent = busy ? 'Envoi en cours…' : `Joindre ${queue.length === 1 ? 'le fichier' : `les ${queue.length} fichiers`}`;
    input.disabled = busy;
  };
  const add = (files) => {
    if (busy) return;
    if (queue.length + files.length > 20) { status.textContent = '20 fichiers maximum par envoi.'; return; }
    queue.push(...files); status.textContent = ''; render();
  };
  input.addEventListener('change', () => { add([...input.files]); input.value = ''; });
  ['dragenter', 'dragover'].forEach((name) => zone.addEventListener(name, (event) => {
    event.preventDefault(); zone.classList.add('drag-over');
  }));
  zone.addEventListener('dragleave', () => zone.classList.remove('drag-over'));
  zone.addEventListener('drop', (event) => {
    event.preventDefault(); zone.classList.remove('drag-over'); add([...event.dataTransfer.files]);
  });
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (busy || !queue.length) return;
    busy = true; render(); status.textContent = 'Envoi des pièces jointes…';
    try {
      const result = await uploadTicketFiles(form.action, queue);
      result.files.forEach(appendAttachment);
      const sent = new Set(result.files.map((file) => file.index));
      queue = queue.filter((_, index) => !sent.has(index));
      status.textContent = result.errors.length
        ? result.errors.map((error) => error.message).join(' ')
        : 'Pièces jointes ajoutées.';
    } catch (error) { status.textContent = error.message; }
    finally { busy = false; render(); }
  });
  render();
});

$$('[data-composer]').forEach((composer) => {
  const editor = $('.conversation-editor', composer), source = $('[data-comment-source]', composer);
  const form = composer.closest('form'), status = $('.upload-status', composer);
  let uploading = false, pasteRange = null;
  composer.classList.add('enhanced');
  source.hidden = true; source.required = false;
  $('input[name="body_format"]', composer).value = 'html';
  $$('[data-command]', composer).forEach((button) => {
    button.addEventListener('mousedown', (event) => event.preventDefault());
    button.addEventListener('click', () => {
      editor.focus();
      let value = null;
      if (button.dataset.command === 'createLink') {
        value = prompt('Adresse du lien (https://…)');
        if (!value) return;
        try { if (!['http:', 'https:'].includes(new URL(value).protocol)) throw new Error(); }
        catch { status.textContent = 'Utilisez une adresse http:// ou https:// valide.'; return; }
      }
      document.execCommand(button.dataset.command, false, value);
    });
  });
  editor.addEventListener('paste', async (event) => {
    const files = [...(event.clipboardData?.items || [])]
      .filter((item) => item.kind === 'file' && item.type.startsWith('image/'))
      .map((item) => item.getAsFile()).filter(Boolean);
    event.preventDefault();
    if (!files.length) {
      document.execCommand('insertText', false, event.clipboardData?.getData('text/plain') || '');
      return;
    }
    if (uploading) { status.textContent = 'Attendez la fin de l’envoi précédent.'; return; }
    const selection = window.getSelection();
    pasteRange = selection.rangeCount && editor.contains(selection.anchorNode) ? selection.getRangeAt(0).cloneRange() : null;
    uploading = true; status.textContent = 'Ajout des images aux pièces jointes…';
    const submit = $('button:not([type="button"])', form);
    if (submit) submit.disabled = true;
    try {
      const result = await uploadTicketFiles(composer.dataset.uploadUrl, files);
      result.files.forEach((file) => {
        appendAttachment(file);
        const image = document.createElement('img');
        image.src = `/attachments/${file.id}/preview`; image.alt = file.original_name;
        if (pasteRange && editor.contains(pasteRange.commonAncestorContainer)) {
          pasteRange.deleteContents(); pasteRange.insertNode(image); pasteRange.setStartAfter(image); pasteRange.collapse(true);
        } else editor.append(image);
      });
      status.textContent = result.errors.length ? result.errors.map((error) => error.message).join(' ') : 'Image ajoutée. Envoyez votre commentaire pour la publier dans la conversation.';
    } catch (error) { status.textContent = error.message; }
    finally { uploading = false; if (submit) submit.disabled = false; }
  });
  // Prevent browsers from inserting foreign drag-and-drop HTML or data URLs.
  editor.addEventListener('drop', (event) => { event.preventDefault(); status.textContent = 'Déposez vos fichiers dans la zone Pièces jointes.'; });
  form.addEventListener('submit', (event) => {
    source.value = editor.innerHTML;
    if (uploading || ((!editor.textContent.trim() && !$('img', editor)) || editor.textContent.length > 10000)) {
      event.preventDefault();
      status.textContent = uploading ? 'Attendez la fin de l’envoi.' : 'Ajoutez du texte (10 000 caractères maximum) ou une image.';
      editor.focus();
    }
  });
});
