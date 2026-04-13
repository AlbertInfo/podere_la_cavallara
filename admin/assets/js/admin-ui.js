document.addEventListener('DOMContentLoaded', function () {
  var body = document.body;
  var sidebar = document.getElementById('adminSidebar');
  var sidebarToggle = document.getElementById('mobileMenuToggle');
  var secondaryToggle = document.querySelector('[data-toggle-sidebar]');

  function setSidebarExpanded(expanded) {
    if (sidebarToggle) sidebarToggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    if (secondaryToggle) secondaryToggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
  }

  function openSidebar() {
    body.classList.add('sidebar-open');
    setSidebarExpanded(true);
  }

  function closeSidebar() {
    body.classList.remove('sidebar-open');
    setSidebarExpanded(false);
  }

  function toggleSidebar() {
    if (body.classList.contains('sidebar-open')) {
      closeSidebar();
    } else {
      openSidebar();
    }
  }

  if (sidebar && (sidebarToggle || secondaryToggle)) {
    if (sidebarToggle) {
      sidebarToggle.addEventListener('click', toggleSidebar);
    }

    if (secondaryToggle) {
      secondaryToggle.addEventListener('click', toggleSidebar);
    }

    document.addEventListener('click', function (e) {
      if (window.innerWidth > 1024) return;
      if (!body.classList.contains('sidebar-open')) return;
      var clickedToggle = sidebarToggle && sidebarToggle.contains(e.target);
      var clickedSecondaryToggle = secondaryToggle && secondaryToggle.contains(e.target);
      if (sidebar.contains(e.target) || clickedToggle || clickedSecondaryToggle) return;
      closeSidebar();
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' || e.keyCode === 27) {
        closeSidebar();
      }
    });
  }

  function updateMobileBottomNavActive() {
    var navItems = document.querySelectorAll('[data-mobile-nav-item]');
    if (!navItems.length) return;

    var key = body.getAttribute('data-mobile-nav-key') || 'none';
    var path = body.getAttribute('data-current-path') || '';
    var hash = window.location.hash || '';

    if (path === 'index.php') {
      if (hash === '#registered-bookings' || hash === '#booking-requests' || hash === '#contact-requests') {
        key = 'prenotazioni';
      } else {
        key = 'home';
      }
    }

    for (var i = 0; i < navItems.length; i++) {
      var itemKey = navItems[i].getAttribute('data-mobile-nav-item');
      if (itemKey === 'menu') {
        navItems[i].classList.toggle('is-open', body.classList.contains('sidebar-open'));
      } else {
        navItems[i].classList.toggle('is-active', itemKey === key);
      }
    }
  }

  updateMobileBottomNavActive();
  window.addEventListener('hashchange', updateMobileBottomNavActive);
  window.addEventListener('resize', function () {
    if (window.innerWidth > 1024) {
      closeSidebar();
    }
    updateMobileBottomNavActive();
  });

  document.querySelectorAll('form[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      var message = form.getAttribute('data-confirm') || 'Confermi questa operazione?';
      if (!window.confirm(message)) {
        e.preventDefault();
      }
    });
  });

  document.querySelectorAll('[data-table-filter]').forEach(function (input) {
    input.addEventListener('input', function () {
      var selector = input.getAttribute('data-table-filter');
      var table = document.querySelector(selector);
      if (!table) return;

      var query = input.value.trim().toLowerCase();
      var rows = table.querySelectorAll('tbody tr');

      rows.forEach(function (row) {
        var text = row.textContent.toLowerCase();
        row.style.display = text.indexOf(query) !== -1 ? '' : 'none';
      });
    });
  });

  if (typeof flatpickr !== 'undefined') {
    var locale = flatpickr.l10ns.it || 'it';

    document.querySelectorAll('.js-date-range, [data-flatpickr-range]').forEach(function (input) {
      flatpickr(input, {
        mode: 'range',
        dateFormat: 'd/m/Y',
        rangeSeparator: ' - ',
        allowInput: true,
        locale: locale,
        defaultDate: input.value && input.value.indexOf(' - ') !== -1 ? input.value.split(' - ') : null
      });
    });
  }

  document.querySelectorAll('[data-mobile-expand-row]').forEach(function (row) {
    row.addEventListener('click', function (e) {
      if (window.innerWidth > 768) return;
      if (e.target.closest('a, button, form, input, select, textarea, label')) return;

      var detailRow = row.nextElementSibling;
      if (!detailRow || !detailRow.classList.contains('mobile-detail-row')) return;

      var isOpen = row.classList.contains('is-open');

      document.querySelectorAll('.mobile-summary-row.is-open').forEach(function (summary) {
        summary.classList.remove('is-open');
      });

      document.querySelectorAll('.mobile-detail-row.is-open').forEach(function (detail) {
        detail.classList.remove('is-open');
      });

      if (!isOpen) {
        row.classList.add('is-open');
        detailRow.classList.add('is-open');
      }
    });
  });

  document.querySelectorAll('[data-password-toggle]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var wrapper = btn.closest('.password-field');
      var input = wrapper ? wrapper.querySelector('input[type="password"], input[type="text"]') : null;
      if (!input) return;
      var showing = input.type === 'text';
      input.type = showing ? 'password' : 'text';
      btn.classList.toggle('is-active', !showing);
      btn.setAttribute('aria-label', showing ? 'Mostra password' : 'Nascondi password');
    });
  });

  document.querySelectorAll('.js-date-range').forEach(function (input) {
    if (typeof flatpickr === 'undefined') return;
    var existing = (input.value || '').trim();
    var defaultDate = undefined;
    if (existing.indexOf(' - ') !== -1) {
      var parts = existing.split(' - ').map(function (s) { return s.trim(); }).filter(Boolean);
      if (parts.length === 2) defaultDate = parts;
    }
    flatpickr(input, {
      mode: 'range',
      dateFormat: 'd/m/Y',
      locale: 'it',
      defaultDate: defaultDate
    });
  });
});
