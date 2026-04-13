document.addEventListener('DOMContentLoaded', function () {
  var body = document.body;

  function isMobileAdmin() {
    return window.innerWidth <= 900;
  }

  function toggleExpand(button) {
    var card = button.closest('[data-mobile-expand-card]');
    if (!card) return;
    var expanded = card.classList.contains('is-expanded');
    card.classList.toggle('is-expanded', !expanded);
    button.setAttribute('aria-expanded', expanded ? 'false' : 'true');
    var labels = button.getAttribute('data-toggle-labels');
    if (labels) {
      var parts = labels.split('|');
      if (parts.length === 2) {
        button.textContent = expanded ? parts[0] : parts[1];
      }
    }
  }

  document.querySelectorAll('[data-mobile-expand-trigger]').forEach(function (button) {
    button.addEventListener('click', function () {
      toggleExpand(button);
    });
  });

  function closeSheet(sheet) {
    if (!sheet) return;
    sheet.classList.remove('is-open');
    sheet.setAttribute('aria-hidden', 'true');
    body.classList.remove('mobile-sheet-open');
  }

  function openSheet(sheet) {
    if (!sheet) return;
    sheet.classList.add('is-open');
    sheet.setAttribute('aria-hidden', 'false');
    body.classList.add('mobile-sheet-open');
  }

  document.querySelectorAll('[data-mobile-sheet-open]').forEach(function (button) {
    button.addEventListener('click', function () {
      var targetId = button.getAttribute('data-mobile-sheet-open');
      openSheet(document.getElementById(targetId));
    });
  });

  document.querySelectorAll('[data-mobile-sheet-close]').forEach(function (button) {
    button.addEventListener('click', function () {
      closeSheet(button.closest('.mobile-sheet'));
    });
  });

  document.addEventListener('keydown', function (event) {
    if ((event.key === 'Escape' || event.keyCode === 27)) {
      document.querySelectorAll('.mobile-sheet.is-open').forEach(function (sheet) {
        closeSheet(sheet);
      });
    }
  });

  /* Mobile dashboard booking filters */
  var mobileSearch = document.getElementById('mobileBookingsSearch');
  var mobileRoom = document.getElementById('mobileBookingsRoom');
  var mobilePhase = document.getElementById('mobileBookingsPhase');
  var mobileSort = document.getElementById('mobileBookingsSort');
  var mobileList = document.getElementById('mobileBookingsList');
  var mobileCount = document.querySelector('[data-mobile-bookings-count]');
  var mobileSummary = document.querySelector('[data-mobile-bookings-summary]');
  var mobileEmpty = document.getElementById('mobileBookingsEmpty');
  var mobileApply = document.getElementById('mobileBookingsApply');
  var mobileReset = document.getElementById('mobileBookingsReset');
  var mobileFilterSheet = document.getElementById('mobileBookingsFilterSheet');

  if (mobileSearch && mobileRoom && mobilePhase && mobileSort && mobileList) {
    var bookingCards = Array.prototype.slice.call(mobileList.querySelectorAll('[data-mobile-booking-card]'));

    function parseMaybeNumber(value, fallbackHigh) {
      if (!value || !/^\d+$/.test(String(value))) {
        return fallbackHigh ? Number.POSITIVE_INFINITY : 0;
      }
      return Number(value);
    }

    function updateMobileCount(total) {
      if (mobileCount) {
        mobileCount.textContent = total;
      }
      if (mobileSummary) {
        var summary = [];
        if (mobileRoom.value) summary.push(mobileRoom.options[mobileRoom.selectedIndex].text);
        if (mobilePhase.value) summary.push(mobilePhase.options[mobilePhase.selectedIndex].text);
        summary.push(total === 1 ? '1 prenotazione' : total + ' prenotazioni');
        mobileSummary.textContent = summary.join(' · ');
      }
      if (mobileEmpty) {
        mobileEmpty.hidden = total !== 0;
      }
    }

    function sortBookingCards(cards, mode) {
      return cards.sort(function (a, b) {
        if (mode === 'name_asc') {
          return (a.getAttribute('data-name') || '').localeCompare((b.getAttribute('data-name') || ''), 'it', {sensitivity:'base'});
        }
        if (mode === 'checkin_asc') {
          return parseMaybeNumber(a.getAttribute('data-check-in-ts'), true) - parseMaybeNumber(b.getAttribute('data-check-in-ts'), true);
        }
        if (mode === 'checkin_desc') {
          return parseMaybeNumber(b.getAttribute('data-check-in-ts'), true) - parseMaybeNumber(a.getAttribute('data-check-in-ts'), true);
        }
        return parseMaybeNumber(b.getAttribute('data-created-ts'), false) - parseMaybeNumber(a.getAttribute('data-created-ts'), false);
      });
    }

    function applyMobileBookingFilters() {
      var query = mobileSearch.value.toLowerCase().trim();
      var room = mobileRoom.value.toLowerCase().trim();
      var phase = mobilePhase.value.toLowerCase().trim();
      var visible = [];

      bookingCards.forEach(function (card) {
        var matchesQuery = !query || (card.getAttribute('data-search-text') || '').toLowerCase().indexOf(query) !== -1;
        var matchesRoom = !room || (card.getAttribute('data-room-type') || '').toLowerCase() === room;
        var matchesPhase = !phase || (card.getAttribute('data-phase') || '').toLowerCase() === phase;
        var show = matchesQuery && matchesRoom && matchesPhase;
        card.hidden = !show;
        if (show) visible.push(card);
      });

      sortBookingCards(visible, mobileSort.value).forEach(function (card) {
        mobileList.appendChild(card);
      });

      updateMobileCount(visible.length);
    }

    mobileSearch.addEventListener('input', applyMobileBookingFilters);
    [mobileRoom, mobilePhase, mobileSort].forEach(function (field) {
      field.addEventListener('change', applyMobileBookingFilters);
    });

    if (mobileApply) {
      mobileApply.addEventListener('click', function () {
        applyMobileBookingFilters();
        closeSheet(mobileFilterSheet);
      });
    }

    if (mobileReset) {
      mobileReset.addEventListener('click', function () {
        mobileSearch.value = '';
        mobileRoom.value = '';
        mobilePhase.value = '';
        mobileSort.value = 'created_desc';
        applyMobileBookingFilters();
      });
    }

    applyMobileBookingFilters();
  }

  /* Cliente modal trigger on mobile reuses desktop modal */
  var clienteModal = document.getElementById('clienteCreateModal');
  if (clienteModal) {
    document.querySelectorAll('[data-mobile-open-cliente-modal]').forEach(function (button) {
      button.addEventListener('click', function () {
        clienteModal.classList.add('is-open');
        clienteModal.setAttribute('aria-hidden', 'false');
        body.classList.add('clienti-modal-open-lock');
        var first = clienteModal.querySelector('[data-cliente-modal-first-field]');
        if (first) {
          setTimeout(function(){ first.focus(); }, 120);
        }
      });
    });
  }

  if (!isMobileAdmin()) {
    document.querySelectorAll('.mobile-sheet').forEach(function (sheet) {
      closeSheet(sheet);
    });
  }
});
