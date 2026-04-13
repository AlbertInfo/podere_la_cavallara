document.addEventListener('DOMContentLoaded', function () {
  var body = document.body;
  var overlay = document.querySelector('[data-mobile-overlay]');
  var sidebar = document.getElementById('adminSidebar');
  var openButtons = document.querySelectorAll('[data-mobile-expand]');
  var filterToggles = document.querySelectorAll('[data-mobile-filter-toggle]');
  var mobileModal = document.getElementById('clienteCreateModalMobile');

  function closeSidebarMobile() {
    body.classList.remove('sidebar-open');
    if (overlay) overlay.hidden = true;
  }
  function syncOverlay() {
    if (!overlay) return;
    overlay.hidden = !body.classList.contains('sidebar-open');
  }
  syncOverlay();
  if (overlay) overlay.addEventListener('click', closeSidebarMobile);

  document.addEventListener('click', function (e) {
    var toggler = e.target.closest('#mobileMenuToggle,[data-toggle-sidebar]');
    if (toggler) {
      setTimeout(syncOverlay, 10);
    }
    if (body.classList.contains('sidebar-open') && e.target.closest('.sidebar-link')) {
      closeSidebarMobile();
    }
  }, true);

  openButtons.forEach(function (btn) {
    btn.addEventListener('click', function () {
      var targetId = btn.getAttribute('data-mobile-expand');
      var target = document.getElementById(targetId);
      if (!target) return;
      var isHidden = target.hasAttribute('hidden');
      if (isHidden) {
        target.removeAttribute('hidden');
        btn.classList.add('is-open');
        btn.textContent = 'Nascondi dettagli';
      } else {
        target.setAttribute('hidden', 'hidden');
        btn.classList.remove('is-open');
        btn.textContent = 'Dettagli';
      }
    });
  });

  filterToggles.forEach(function (btn) {
    btn.addEventListener('click', function () {
      var targetId = btn.getAttribute('data-mobile-filter-toggle');
      var target = document.getElementById(targetId);
      if (!target) return;
      var hidden = target.hasAttribute('hidden');
      if (hidden) {
        target.removeAttribute('hidden');
        btn.textContent = 'Chiudi filtri';
      } else {
        target.setAttribute('hidden', 'hidden');
        btn.textContent = 'Filtri';
      }
    });
  });

  var mobileBookingsList = document.getElementById('mobileBookingsList');
  if (mobileBookingsList) {
    var searchInput = document.getElementById('mobileBookingsSearch');
    var roomFilter = document.getElementById('mobileBookingsRoomFilter');
    var phaseFilter = document.getElementById('mobileBookingsPhaseFilter');
    var sortSelect = document.getElementById('mobileBookingsSort');
    var resetButton = document.getElementById('mobileBookingsReset');
    var cards = Array.prototype.slice.call(mobileBookingsList.querySelectorAll('.mobile-booking-card'));

    function parseIso(value) {
      if (!value || !/^\d{4}-\d{2}-\d{2}$/.test(value)) return Number.POSITIVE_INFINITY;
      return Date.parse(value + 'T00:00:00');
    }
    function parseDateTime(value) {
      if (!value) return 0;
      var t = Date.parse(String(value).replace(' ', 'T'));
      return isNaN(t) ? 0 : t;
    }
    cards.forEach(function (card, i) {
      card._sortData = {
        originalIndex: i,
        search: (card.getAttribute('data-mobile-search') || '').toLowerCase(),
        room: (card.getAttribute('data-room-type') || '').toLowerCase(),
        phase: (card.getAttribute('data-booking-phase') || '').toLowerCase(),
        checkin: parseIso(card.getAttribute('data-check-in') || ''),
        created: parseDateTime(card.getAttribute('data-created-at') || ''),
        name: (card.getAttribute('data-customer-name') || '').toLowerCase()
      };
    });

    function applyFilters() {
      var query = searchInput ? searchInput.value.trim().toLowerCase() : '';
      var room = roomFilter ? roomFilter.value.trim().toLowerCase() : '';
      var phase = phaseFilter ? phaseFilter.value.trim().toLowerCase() : '';
      var sort = sortSelect ? sortSelect.value : 'created_desc';
      var visible = cards.filter(function (card) {
        var d = card._sortData;
        return (!query || d.search.indexOf(query) !== -1) && (!room || d.room === room) && (!phase || d.phase === phase);
      });
      visible.sort(function (a,b){
        var da=a._sortData, db=b._sortData;
        if (sort==='checkin_asc') return da.checkin - db.checkin || da.originalIndex - db.originalIndex;
        if (sort==='checkin_desc') return db.checkin - da.checkin || da.originalIndex - db.originalIndex;
        if (sort==='name_asc') return da.name.localeCompare(db.name, 'it') || da.originalIndex - db.originalIndex;
        return db.created - da.created || da.originalIndex - db.originalIndex;
      });
      cards.forEach(function(card){card.style.display='none';});
      visible.forEach(function(card){card.style.display=''; mobileBookingsList.appendChild(card);});
    }
    [searchInput, roomFilter, phaseFilter, sortSelect].forEach(function (el) {
      if (el) el.addEventListener('input', applyFilters);
      if (el) el.addEventListener('change', applyFilters);
    });
    if (resetButton) resetButton.addEventListener('click', function(){
      if (searchInput) searchInput.value='';
      if (roomFilter) roomFilter.value='';
      if (phaseFilter) phaseFilter.value='';
      if (sortSelect) sortSelect.value='created_desc';
      applyFilters();
    });
  }

  function openModal() {
    if (!mobileModal) return;
    mobileModal.hidden = false;
    body.classList.add('clienti-modal-open-lock');
  }
  function closeModal() {
    if (!mobileModal) return;
    mobileModal.hidden = true;
    body.classList.remove('clienti-modal-open-lock');
  }
  document.querySelectorAll('[data-open-cliente-modal="mobile"]').forEach(function(btn){btn.addEventListener('click', openModal);});
  document.querySelectorAll('[data-close-cliente-modal="mobile"]').forEach(function(btn){btn.addEventListener('click', closeModal);});
  document.addEventListener('keydown', function(e){ if (e.key==='Escape') closeModal();});
});
