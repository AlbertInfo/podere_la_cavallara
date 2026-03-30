document.addEventListener('DOMContentLoaded', function () {
  const body = document.body;
  const sidebar = document.getElementById('adminSidebar');
  const sidebarToggle = document.getElementById('mobileMenuToggle');

  function openSidebar() {
    body.classList.add('sidebar-open');
    if (sidebarToggle) sidebarToggle.setAttribute('aria-expanded', 'true');
  }

  function closeSidebar() {
    body.classList.remove('sidebar-open');
    if (sidebarToggle) sidebarToggle.setAttribute('aria-expanded', 'false');
  }

  if (sidebarToggle && sidebar) {
    sidebarToggle.addEventListener('click', function () {
      body.classList.contains('sidebar-open') ? closeSidebar() : openSidebar();
    });

    document.addEventListener('click', function (e) {
      if (window.innerWidth > 1024) return;
      if (!body.classList.contains('sidebar-open')) return;
      if (sidebar.contains(e.target) || sidebarToggle.contains(e.target)) return;
      closeSidebar();
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') closeSidebar();
    });
  }

  document.querySelectorAll('form[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      const message = form.getAttribute('data-confirm') || 'Confermi questa operazione?';
      if (!window.confirm(message)) {
        e.preventDefault();
      }
    });
  });

  document.querySelectorAll('[data-table-filter]').forEach(function (input) {
    input.addEventListener('input', function () {
      const selector = input.getAttribute('data-table-filter');
      const table = document.querySelector(selector);
      if (!table) return;

      const query = input.value.trim().toLowerCase();
      const rows = table.querySelectorAll('tbody tr');

      rows.forEach(function (row) {
        const text = row.textContent.toLowerCase();
        row.style.display = text.indexOf(query) !== -1 ? '' : 'none';
      });
    });
  });

  if (typeof flatpickr !== 'undefined') {
    const locale = flatpickr.l10ns.it || 'it';

    document.querySelectorAll('.js-date-range, [data-flatpickr-range]').forEach(function (input) {
      flatpickr(input, {
        mode: 'range',
        dateFormat: 'd/m/Y',
        allowInput: true,
        locale: locale
      });
    });
  }
});
