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
