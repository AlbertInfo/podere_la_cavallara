document.addEventListener('DOMContentLoaded', () => {
  const panel = document.querySelector('[data-anagrafica-form-panel]');
  const form = document.getElementById('anagraficaForm');
  if (!panel || !form) return;

  const closeButton = document.querySelector('[data-anagrafica-close]');
  const recordType = document.getElementById('recordType');
  const expectedGuests = document.getElementById('expectedGuests');
  const repeater = document.getElementById('guestRepeater');
  const addButton = document.getElementById('addGuestButton');
  const template = document.getElementById('guestTemplate');
  const arrivalField = form.querySelector('[data-date-role="arrival"]');
  const departureField = form.querySelector('[data-date-role="departure"]');
  const baseUrl = panel.dataset.baseUrl || window.location.pathname;
  const leaderTypeField = form.querySelector('[data-leader-type]');

  let cloneIndex = repeater ? repeater.querySelectorAll('[data-guest-card]').length + 1 : 1;

  const openLinks = document.querySelectorAll('[data-anagrafica-open-link]');
  const forceOpen = panel.dataset.forceOpen === '1';

  function setPanelState(isOpen) {
    panel.classList.toggle('is-open', isOpen);
    panel.hidden = !isOpen;
  }

  function resetCreateMode() {
    if (form.dataset.mode !== 'create') return;
    form.reset();
    repeater?.querySelectorAll('[data-guest-card]').forEach((card) => card.remove());
    cloneIndex = 1;
    refreshGuestCounters();
    updateGroupState();
    syncDateConstraints(form);
  }

  function createDatePicker(element, options = {}) {
    if (typeof flatpickr !== 'function' || !element || element._flatpickr) return null;

    return flatpickr(element, {
      dateFormat: 'd/m/Y',
      altInput: true,
      altFormat: 'd/m/Y',
      allowInput: true,
      locale: 'it',
      disableMobile: true,
      monthSelectorType: 'static',
      prevArrow: '<span aria-hidden="true">‹</span>',
      nextArrow: '<span aria-hidden="true">›</span>',
      ...options,
    });
  }

  function syncDateConstraints(scope = form) {
    const issueFields = scope.querySelectorAll('[data-date-role="document-issue"]');

    issueFields.forEach((issueField) => {
      const container = issueField.closest('[data-guest-card], .anagrafica-guest-card') || scope;
      const expiryField = container.querySelector('[data-date-role="document-expiry"]');
      if (!expiryField || !issueField._flatpickr || !expiryField._flatpickr) return;

      const selectedIssue = issueField._flatpickr.selectedDates[0] || null;
      const selectedExpiry = expiryField._flatpickr.selectedDates[0] || null;
      expiryField._flatpickr.config.minDate = selectedIssue;
      issueField._flatpickr.config.maxDate = selectedExpiry || null;
    });

    if (arrivalField?._flatpickr && departureField?._flatpickr) {
      const arrivalDate = arrivalField._flatpickr.selectedDates[0] || null;
      const departureDate = departureField._flatpickr.selectedDates[0] || null;
      departureField._flatpickr.config.minDate = arrivalDate;
      arrivalField._flatpickr.config.maxDate = departureDate || null;
    }
  }

  function initDates(scope = document) {
    scope.querySelectorAll('.js-date').forEach((field) => {
      const role = field.dataset.dateRole || '';
      const options = {};

      if (role === 'birth') {
        options.maxDate = 'today';
      }

      createDatePicker(field, {
        ...options,
        onReady: [() => syncDateConstraints(scope)],
        onChange: [() => syncDateConstraints(scope)],
      });
    });

    syncDateConstraints(scope);
  }

  function syncLeaderTypeDefault() {
    if (!leaderTypeField || form.dataset.mode !== 'create') return;
    leaderTypeField.value = recordType?.value === 'group' ? '18' : '16';
  }

  function updateGroupState() {
    const isGroup = recordType && recordType.value === 'group';
    if (addButton) {
      addButton.disabled = !isGroup;
      addButton.classList.toggle('is-disabled', !isGroup);
    }
    if (repeater) {
      repeater.style.display = isGroup ? 'grid' : 'none';
      if (!isGroup) {
        repeater.querySelectorAll('[data-guest-card]').forEach((card) => card.remove());
        cloneIndex = 1;
        if (expectedGuests) expectedGuests.value = '1';
      } else if (expectedGuests && Number(expectedGuests.value || 0) < 2) {
        expectedGuests.value = String(Math.max(2, 1 + repeater.querySelectorAll('[data-guest-card]').length));
      }
    }
    syncLeaderTypeDefault();
  }

  function refreshGuestCounters() {
    if (!repeater) return;
    repeater.querySelectorAll('[data-guest-card]').forEach((card, index) => {
      const numberNode = card.querySelector('[data-guest-number]');
      if (numberNode) numberNode.textContent = String(index + 2);
    });
    if (expectedGuests && recordType?.value === 'group') {
      expectedGuests.value = String(1 + repeater.querySelectorAll('[data-guest-card]').length);
    }
  }

  function bindGuestRemoveButtons(scope = repeater) {
    if (!scope) return;
    scope.querySelectorAll('[data-remove-guest]').forEach((button) => {
      if (button.dataset.bound === '1') return;
      button.dataset.bound = '1';
      button.addEventListener('click', () => {
        const card = button.closest('[data-guest-card]');
        if (card) {
          card.remove();
          refreshGuestCounters();
        }
      });
    });
  }

  function addGuestCard() {
    if (!template || !repeater) return;

    const fragment = template.content.cloneNode(true);
    const card = fragment.querySelector('[data-guest-card]');
    if (!card) return;

    card.querySelectorAll('[data-name]').forEach((field) => {
      field.name = `guests[${cloneIndex}][${field.dataset.name}]`;
      if ([
        'first_name',
        'last_name',
        'birth_date',
        'citizenship_label',
        'document_number',
        'document_expiry_date',
        'document_issue_place',
        'tipoalloggiato_code',
        'citizenship_code',
        'residence_state_code',
        'residence_place_code',
        'birth_state_code',
        'tourism_type',
        'transport_type',
      ].includes(field.dataset.name)) {
        field.required = true;
      }
    });

    repeater.appendChild(card);
    bindGuestRemoveButtons(card);
    initDates(card);
    cloneIndex += 1;
    refreshGuestCounters();
  }

  document.querySelectorAll('[data-record-row]').forEach((row) => {
    const editUrl = row.dataset.editUrl;
    if (!editUrl) return;

    row.addEventListener('click', (event) => {
      if (event.target.closest('[data-row-ignore]')) return;
      window.location.href = editUrl;
    });

    row.addEventListener('keydown', (event) => {
      if (event.key !== 'Enter' && event.key !== ' ') return;
      if (event.target.closest('[data-row-ignore]')) return;
      event.preventDefault();
      window.location.href = editUrl;
    });
  });

  document.querySelectorAll('[data-delete-form]').forEach((deleteForm) => {
    deleteForm.addEventListener('submit', (event) => {
      const ok = window.confirm('Eliminare definitivamente questa anagrafica?');
      if (!ok) event.preventDefault();
    });
  });

  closeButton?.addEventListener('click', (event) => {
    event.preventDefault();
    setPanelState(false);
    if (window.history && typeof window.history.replaceState === 'function') {
      window.history.replaceState({}, document.title, baseUrl);
    }
  });

  openLinks.forEach((link) => {
    link.addEventListener('click', (event) => {
      event.preventDefault();
      setPanelState(true);
      if (window.history && typeof window.history.replaceState === 'function') {
        window.history.replaceState({}, document.title, link.getAttribute('href') || baseUrl);
      }
    });
  });

  addButton?.addEventListener('click', addGuestCard);
  recordType?.addEventListener('change', updateGroupState);

  initDates(form);
  bindGuestRemoveButtons();
  refreshGuestCounters();
  updateGroupState();
  setPanelState(forceOpen);

  const highlightedRow = document.querySelector('[data-record-row].is-highlighted');
  if (highlightedRow) {
    highlightedRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }
});
