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
