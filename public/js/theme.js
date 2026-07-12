/**
 * Wires up every [data-theme-toggle] button. The theme itself is already
 * applied before first paint by the inline snippet in each page's <head>;
 * this just keeps the toggle button's icon in sync and handles clicks.
 */
(function () {
  function currentTheme() {
    return document.documentElement.getAttribute('data-bs-theme') || 'dark';
  }

  function applyTheme(theme) {
    document.documentElement.setAttribute('data-bs-theme', theme);
    localStorage.setItem('theme', theme);
    document.querySelectorAll('[data-theme-toggle]').forEach(function (btn) {
      btn.textContent = theme === 'dark' ? '☀️' : '🌙';
      btn.setAttribute('aria-label', theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode');
      btn.title = btn.getAttribute('aria-label');
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    applyTheme(currentTheme());
    document.querySelectorAll('[data-theme-toggle]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        applyTheme(currentTheme() === 'dark' ? 'light' : 'dark');
      });
    });
  });
})();
