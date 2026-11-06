// Entry point bundled into public/assets/vendor/milkdown/wiki-editor.js.
// Exposes window.SillageWikiEditor.create(); Sillage code never touches Milkdown internals directly.
import { Crepe } from '@milkdown/crepe';
import { editorViewCtx } from '@milkdown/kit/core';
import { insert } from '@milkdown/kit/utils';
import '@milkdown/crepe/theme/common/prosemirror.css';
import '@milkdown/crepe/theme/common/reset.css';
import '@milkdown/crepe/theme/common/block-edit.css';
import '@milkdown/crepe/theme/common/cursor.css';
import '@milkdown/crepe/theme/common/image-block.css';
import '@milkdown/crepe/theme/common/link-tooltip.css';
import '@milkdown/crepe/theme/common/list-item.css';
import '@milkdown/crepe/theme/common/placeholder.css';
import '@milkdown/crepe/theme/common/toolbar.css';
import '@milkdown/crepe/theme/common/table.css';
import '@milkdown/crepe/theme/common/top-bar.css';

const F = Crepe.Feature;

/** French labels: Crepe ships English defaults and icon-only top bar buttons. */
const headingOptions = [
  { label: 'Paragraphe', level: null },
  { label: 'Titre 1', level: 1 },
  { label: 'Titre 2', level: 2 },
  { label: 'Titre 3', level: 3 },
  { label: 'Titre 4', level: 4 },
];
const blockEdit = {
  textGroup: {
    label: 'Texte',
    text: { label: 'Paragraphe' }, h1: { label: 'Titre 1' }, h2: { label: 'Titre 2' }, h3: { label: 'Titre 3' },
    h4: { label: 'Titre 4' }, h5: null, h6: null, quote: { label: 'Citation' }, divider: { label: 'Séparateur' },
  },
  listGroup: { label: 'Listes', bulletList: { label: 'Liste à puces' }, orderedList: { label: 'Liste numérotée' }, taskList: { label: 'Cases à cocher' } },
  advancedGroup: { label: 'Avancé', image: { label: 'Image' }, codeBlock: { label: 'Bloc de code' }, table: { label: 'Tableau' }, math: null },
};
/** Accessible names for the icon-only top bar buttons, in the order Crepe renders them. */
const topBarTitles = ['Gras', 'Italique', 'Barré', 'Code', 'Liste à puces', 'Liste numérotée', 'Cases à cocher', 'Lien', 'Image', 'Tableau', 'Bloc de code', 'Citation', 'Séparateur'];

function labelTopBar(root) {
  const buttons = [...root.querySelectorAll('.top-bar button, .milkdown-top-bar button')].filter((b) => !b.closest('[class*="selector"]'));
  buttons.forEach((button, index) => {
    const title = topBarTitles[index];
    if (title && !button.getAttribute('aria-label')) { button.setAttribute('aria-label', title); button.title = title; }
  });
}

/**
 * Creates an editor in `root` with the given Markdown.
 * Returns { getMarkdown, selectedText, replaceSelection, focus, destroy }.
 * onChange(markdown) is called after each edit (debounced by the caller if needed).
 * uploadImage(file) stores a pasted, dropped or chosen image and resolves to the Markdown address
 * to keep ("_assets/<name>"); imageUrl(address) maps it to the URL the browser displays.
 * Without uploadImage, images cannot be added.
 */
async function create({ root, markdown, onChange, placeholder, readonly = false, uploadImage = null, imageUrl = (url) => url, onImageError = null }) {
  const crepe = new Crepe({
    root,
    defaultValue: markdown,
    features: { [F.Latex]: false, [F.CodeMirror]: false, [F.AI]: false, [F.ImageBlock]: Boolean(uploadImage), [F.TopBar]: true },
    featureConfigs: {
      [F.TopBar]: { headingOptions },
      [F.BlockEdit]: blockEdit,
      [F.Placeholder]: { text: placeholder || 'Commencez à écrire, ou tapez / pour insérer un bloc…', mode: 'doc' },
      [F.Toolbar]: { boldLabel: 'Gras', italicLabel: 'Italique', strikethroughLabel: 'Barré', codeLabel: 'Code', linkLabel: 'Lien' },
      [F.LinkTooltip]: { inputPlaceholder: 'Adresse du lien (https://…)' },
      [F.ImageBlock]: {
        onUpload: uploadImage || undefined,
        proxyDomURL: imageUrl,
        onImageLoadError: onImageError || undefined,
        blockUploadButton: 'Choisir une image',
        blockUploadPlaceholderText: 'ou collez une image',
        blockCaptionPlaceholderText: 'Légende de l’image',
        blockConfirmButton: 'Valider',
        inlineUploadButton: 'Choisir',
        inlineUploadPlaceholderText: 'ou collez une image',
      },
    },
  });
  if (onChange) crepe.on((listener) => listener.markdownUpdated((_ctx, md) => onChange(md)));
  await crepe.create();
  crepe.setReadonly(readonly);
  labelTopBar(root);
  return {
    getMarkdown: () => crepe.getMarkdown(),
    /** Plain text of the current selection ('' when the selection is empty). */
    selectedText: () => crepe.editor.action((ctx) => {
      const { state } = ctx.get(editorViewCtx);
      return state.doc.textBetween(state.selection.from, state.selection.to, '\n\n');
    }),
    /** Replaces the selection (or inserts at the cursor) with parsed Markdown. */
    replaceSelection: (md) => crepe.editor.action(insert(md)),
    focus: () => crepe.editor.action((ctx) => ctx.get(editorViewCtx).focus()),
    setReadonly: (value) => crepe.setReadonly(value),
    destroy: () => crepe.destroy(),
  };
}

window.SillageWikiEditor = Object.freeze({ create, version: '7.22.3' });
