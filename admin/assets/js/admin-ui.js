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
        rangeSeparator: ' - ',
        allowInput: true,
        locale: locale,
        defaultDate: input.value && input.value.indexOf(' - ') !== -1 ? input.value.split(' - ') : null
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

document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('[data-password-toggle]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      const wrapper = btn.closest('.password-field');
      const input = wrapper ? wrapper.querySelector('input[type="password"], input[type="text"]') : null;
      if (!input) return;
      const showing = input.type === 'text';
      input.type = showing ? 'password' : 'text';
      btn.classList.toggle('is-active', !showing);
      btn.setAttribute('aria-label', showing ? 'Mostra password' : 'Nascondi password');
    });
  });

  document.querySelectorAll('.js-date-range').forEach(function (input) {
    if (typeof flatpickr === 'undefined') return;
    const existing = (input.value || '').trim();
    let defaultDate = undefined;
    if (existing.includes(' - ')) {
      const parts = existing.split(' - ').map(function (s) { return s.trim(); }).filter(Boolean);
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


document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('[data-open-sidebar]').forEach(function (trigger) {
    trigger.addEventListener('click', function () {
      document.body.classList.add('sidebar-open');
      var toggle = document.getElementById('mobileMenuToggle');
      if (toggle) toggle.setAttribute('aria-expanded', 'true');
    });
  });

  document.querySelectorAll('[data-mobile-expand]').forEach(function (button) {
    button.addEventListener('click', function () {
      var target = document.querySelector(button.getAttribute('data-target') || '');
      if (!target) return;
      var hidden = target.hasAttribute('hidden');
      if (hidden) {
        target.removeAttribute('hidden');
        button.textContent = 'Hide details';
      } else {
        target.setAttribute('hidden', 'hidden');
        button.textContent = 'View more';
      }
    });
  });

  document.querySelectorAll('[data-mobile-filter-toggle]').forEach(function (button) {
    button.addEventListener('click', function () {
      var target = document.querySelector(button.getAttribute('data-target') || '');
      if (!target) return;
      if (target.hasAttribute('hidden')) {
        target.removeAttribute('hidden');
      } else {
        target.setAttribute('hidden', 'hidden');
      }
    });
  });

  var mobileSearch = document.getElementById('mobileRegisteredBookingsSearch');
  var mobileRoom = document.getElementById('mobileRegisteredBookingsRoomFilter');
  var mobilePhase = document.getElementById('mobileRegisteredBookingsPhaseFilter');
  var mobileSort = document.getElementById('mobileRegisteredBookingsSort');
  var mobileReset = document.getElementById('mobileRegisteredBookingsReset');
  var mobileList = document.getElementById('mobileRegisteredBookingsList');
  var mobileEmpty = document.getElementById('mobileRegisteredBookingsEmpty');

  function mobileParseIso(value) {
    if (!value || !/^\d{4}-\d{2}-\d{2}$/.test(value)) return Number.POSITIVE_INFINITY;
    var parts = value.split('-').map(Number);
    return new Date(parts[0], parts[1] - 1, parts[2]).getTime();
  }

  function mobileParseDateTime(value) {
    if (!value) return 0;
    var normalized = String(value).replace(' ', 'T');
    var timestamp = Date.parse(normalized);
    return Number.isNaN(timestamp) ? 0 : timestamp;
  }

  function applyMobileBookingFilters() {
    if (!mobileList || !mobileSearch || !mobileRoom || !mobilePhase || !mobileSort) return;
    var cards = Array.prototype.slice.call(mobileList.querySelectorAll('.mobile-booking-card'));
    var query = mobileSearch.value.trim().toLowerCase();
    var room = mobileRoom.value.trim().toLowerCase();
    var phase = mobilePhase.value.trim().toLowerCase();
    var sort = mobileSort.value;

    cards.sort(function (a, b) {
      if (sort === 'checkin_asc') return mobileParseIso(a.dataset.checkin || '') - mobileParseIso(b.dataset.checkin || '');
      if (sort === 'checkin_desc') return mobileParseIso(b.dataset.checkin || '') - mobileParseIso(a.dataset.checkin || '');
      if (sort === 'name_asc') return String(a.dataset.cardSearch || '').localeCompare(String(b.dataset.cardSearch || ''));
      return mobileParseDateTime(b.dataset.created || '') - mobileParseDateTime(a.dataset.created || '');
    });

    var visibleCount = 0;
    cards.forEach(function (card) {
      var matchesQuery = query === '' || String(card.dataset.cardSearch || '').indexOf(query) !== -1;
      var matchesRoom = room === '' || String(card.dataset.roomType || '') === room;
      var matchesPhase = phase === '' || String(card.dataset.phase || '') === phase;
      var isVisible = matchesQuery && matchesRoom && matchesPhase;
      card.hidden = !isVisible;
      if (isVisible) visibleCount += 1;
      mobileList.appendChild(card);
    });

    if (mobileEmpty) mobileEmpty.hidden = visibleCount !== 0;
  }

  [mobileSearch, mobileRoom, mobilePhase, mobileSort].forEach(function (el) {
    if (!el) return;
    el.addEventListener('input', applyMobileBookingFilters);
    el.addEventListener('change', applyMobileBookingFilters);
  });

  if (mobileReset) {
    mobileReset.addEventListener('click', function () {
      if (mobileSearch) mobileSearch.value = '';
      if (mobileRoom) mobileRoom.value = '';
      if (mobilePhase) mobilePhase.value = '';
      if (mobileSort) mobileSort.value = 'created_desc';
      applyMobileBookingFilters();
    });
  }

  applyMobileBookingFilters();
});
