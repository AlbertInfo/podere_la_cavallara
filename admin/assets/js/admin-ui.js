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


document.querySelectorAll('[data-mobile-expand-row]').forEach(function (row) {
  row.addEventListener('click', function (e) {
    if (window.innerWidth > 768) return;

    if (e.target.closest('a, button, form, input, select, textarea, label')) {
      return;
    }

    const detailRow = row.nextElementSibling;
    if (!detailRow || !detailRow.classList.contains('mobile-detail-row')) return;

    const isOpen = row.classList.contains('is-open');

    document.querySelectorAll('.mobile-summary-row.is-open').forEach(function (r) {
      r.classList.remove('is-open');
    });

    document.querySelectorAll('.mobile-detail-row.is-open').forEach(function (r) {
      r.classList.remove('is-open');
    });

    if (!isOpen) {
      row.classList.add('is-open');
      detailRow.classList.add('is-open');
    }
  });
});