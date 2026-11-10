try {
  const sidebarPreference = document.cookie.match(/(?:^|;\s*)sillage_sidebar_collapsed=(true|false)(?:;|$)/)?.[1];
  document.documentElement.dataset.sidebarCollapsed = (sidebarPreference || localStorage.getItem('sillage.sidebar.collapsed')) === 'true' ? 'true' : 'false';
  const theme =
    localStorage.getItem('sillage.theme') ||
    (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
  document.documentElement.dataset.theme = theme === 'dark' ? 'dark' : 'light';
} catch {
  document.documentElement.dataset.theme = 'light';
}
// The list table is rendered in the server's default column order, then app.js applies the layout
// saved in this browser. Keep the table invisible (its space reserved) until then so the columns
// do not visibly jump; the timeout shows it anyway if app.js never finishes.
document.documentElement.classList.add('list-layout-pending');
setTimeout(() => document.documentElement.classList.remove('list-layout-pending'), 1500);
// Project, type, priority and status marks carry their colour in data attributes (the CSP forbids
// style attributes). Colour them as soon as the parser inserts them, before the first paint, so a
// navigation never flashes the default violet while the deferred app.js is still loading.
function initAppearanceMarks(root = document) {
  const selector = '[data-project-color],[data-mark-color],[data-status-color]';
  const marks = [...root.querySelectorAll(selector)];
  if (root.matches?.(selector)) marks.unshift(root);
  marks.forEach(mark => {
    const color = mark.dataset.projectColor || mark.dataset.markColor || mark.dataset.statusColor;
    if (!/^#[a-f0-9]{6}$/i.test(color)) return;
    mark.style.setProperty(mark.dataset.projectColor ? '--project-color' : (mark.dataset.statusColor?'--status-color':'--mark-color'), color);
    const channels = [1,3,5].map(offset => parseInt(color.slice(offset,offset+2),16)/255)
      .map(value => value <= .04045 ? value/12.92 : ((value+.055)/1.055)**2.4);
    const luminance = channels[0]*.2126 + channels[1]*.7152 + channels[2]*.0722;
    mark.style.setProperty('--mark-ink', luminance > .179 ? '#000000' : '#ffffff');
  });
}
if (typeof MutationObserver === 'function') {
  const parsingMarks = new MutationObserver(records => records.forEach(record =>
    record.addedNodes.forEach(node => { if (node.nodeType === 1) initAppearanceMarks(node); })));
  parsingMarks.observe(document.documentElement, {childList: true, subtree: true});
  document.addEventListener('DOMContentLoaded', () => { parsingMarks.disconnect(); initAppearanceMarks(); }, {once: true});
}
