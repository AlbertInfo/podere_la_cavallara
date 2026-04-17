document.addEventListener('DOMContentLoaded', () => {
  const formCard = document.getElementById('anagraficaFormCard');
  const form = document.getElementById('anagraficaForm');
  const closeButton = document.getElementById('closeAnagraficaForm');
  const openLinks = document.querySelectorAll('[data-anagrafica-open-link]');
  const baseUrl = formCard?.dataset.baseUrl || window.location.pathname;
  const forceOpen = formCard?.dataset.forceOpen === '1';
  const recordType = document.getElementById('recordType');
  const addButton = document.getElementById('addGuestButton');
  const repeater = document.getElementById('guestRepeater');
  const template = document.getElementById('guestTemplate');
  const expectedGuests = document.getElementById('expectedGuests');
  const arrivalField = form?.querySelector('[data-date-role="arrival"]') || null;
  const departureField = form?.querySelector('[data-date-role="departure"]') || null;
  let cloneIndex = repeater ? repeater.querySelectorAll('[data-guest-card]').length + 1 : 1;

  if (!formCard || !form) return;

  function setPanelState(isOpen) {
    formCard.hidden = !isOpen;
    formCard.classList.toggle('is-open', isOpen);
    if (isOpen) {
      requestAnimationFrame(() => {
        formCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
      });
    }
  }

  function openPanel() {
    setPanelState(true);
  }

  function closePanel() {
    setPanelState(false);
    if (window.history && typeof window.history.replaceState === 'function') {
      window.history.replaceState({}, document.title, baseUrl);
    }
  }

  function createDatePicker(field, extraOptions = {}) {
    if (typeof flatpickr === 'undefined' || !field || field._flatpickr) return null;

    const locale = flatpickr.l10ns.it || 'default';

    return flatpickr(field, {
      locale,
      dateFormat: 'd/m/Y',
      allowInput: true,
      disableMobile: true,
      ...extraOptions,
    });
  }

  function syncDateConstraints(scope = form) {
    const issueFields = scope.querySelectorAll('[data-date-role="document-issue"]');
    issueFields.forEach((issueField) => {
      const container = issueField.closest('[data-guest-card]') || scope;
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

  function updateGroupState() {
    const isGroup = recordType && recordType.value !== 'single';
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
  }

  function refreshGuestCounters() {
    if (!repeater) return;
    repeater.querySelectorAll('[data-guest-card]').forEach((card, index) => {
      const numberNode = card.querySelector('[data-guest-number]');
      if (numberNode) numberNode.textContent = String(index + 2);
    });
    if (expectedGuests && recordType?.value !== 'single') {
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
        'residence_state_label',
        'residence_place_label',
        'document_type_label',
        'document_number',
        'document_expiry_date',
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
    closePanel();
  });

  openLinks.forEach((link) => {
    link.addEventListener('click', (event) => {
      event.preventDefault();
      openPanel();
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

  const selectedDayCard = document.querySelector('[data-day-card].is-selected');
  if (selectedDayCard) {
    selectedDayCard.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
  }
});