document.addEventListener('DOMContentLoaded', function () {
  const body = document.body;
  const sidebarToggle = document.querySelector('[data-sidebar-toggle]');
  const sidebarClose = document.querySelector('[data-sidebar-close]');
  const sidebarBackdrop = document.querySelector('[data-sidebar-backdrop]');
  const adminSidebar = document.querySelector('.admin-sidebar');

  function openSidebar() {
    body.classList.add('sidebar-open');
  }

  function closeSidebar() {
    body.classList.remove('sidebar-open');
  }

  if (sidebarToggle) {
    sidebarToggle.addEventListener('click', function () {
      if (body.classList.contains('sidebar-open')) {
        closeSidebar();
      } else {
        openSidebar();
      }
    });
  }

  if (sidebarClose) {
    sidebarClose.addEventListener('click', closeSidebar);
  }

  if (sidebarBackdrop) {
    sidebarBackdrop.addEventListener('click', closeSidebar);
  }

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      closeSidebar();
    }
  });

  window.addEventListener('resize', function () {
    if (window.innerWidth > 1024) {
      closeSidebar();
    }
  });

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

  document.querySelectorAll('[data-flatpickr-range]').forEach(function (input) {
    if (typeof flatpickr === 'undefined') return;

    flatpickr(input, {
      mode: 'range',
      dateFormat: 'd/m/Y',
      allowInput: true,
      locale: 'it'
    });
  });

  document.querySelectorAll('[data-flatpickr-single]').forEach(function (input) {
    if (typeof flatpickr === 'undefined') return;

    flatpickr(input, {
      dateFormat: 'd/m/Y',
      allowInput: true,
      locale: 'it'
    });
  });

  document.querySelectorAll('[data-table-card]').forEach(function (tableWrap) {
    const table = tableWrap.querySelector('table');
    if (!table) return;

    const headers = Array.from(table.querySelectorAll('thead th')).map(function (th) {
      return th.textContent.trim();
    });

    table.querySelectorAll('tbody tr').forEach(function (tr) {
      Array.from(tr.children).forEach(function (td, index) {
        if (!td.getAttribute('data-label') && headers[index]) {
          td.setAttribute('data-label', headers[index]);
        }
      });
    });
  });

  if (adminSidebar) {
    const currentUrl = window.location.pathname.replace(/\/+$/, '');
    adminSidebar.querySelectorAll('a[href]').forEach(function (link) {
      try {
        const linkUrl = new URL(link.href, window.location.origin);
        const linkPath = linkUrl.pathname.replace(/\/+$/, '');
        if (linkPath === currentUrl) {
          link.classList.add('is-active');
        }
      } catch (err) {}
    });
  }
});