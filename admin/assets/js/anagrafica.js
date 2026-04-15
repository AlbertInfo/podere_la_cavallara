document.addEventListener('DOMContentLoaded', () => {
  const form = document.getElementById('anagraficaForm');
  if (!form) return;

  const panel = document.querySelector('[data-anagrafica-form-panel]');
  const openButton = document.querySelector('[data-anagrafica-toggle]');
  const closeButton = document.querySelector('[data-anagrafica-close]');
  const recordType = document.getElementById('recordType');
  const expectedGuests = document.getElementById('expectedGuests');
  const repeater = document.getElementById('guestRepeater');
  const addButton = document.getElementById('addGuestButton');
  const template = document.getElementById('guestTemplate');
  const arrivalField = form.querySelector('[data-date-role="arrival"]');
  const departureField = form.querySelector('[data-date-role="departure"]');

  let cloneIndex = 1;

  function setPanelState(isOpen) {
    if (!panel) return;
    panel.classList.toggle('is-open', isOpen);
    if (openButton) {
      openButton.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
      openButton.textContent = isOpen ? 'Modulo aperto' : 'Nuova anagrafica';
    }
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
      const container = issueField.closest('.anagrafica-grid, [data-guest-card], .anagrafica-guest-card') || scope;
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
    const fields = scope.querySelectorAll('.js-date');
    fields.forEach((field) => {
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
    const isGroup = recordType.value === 'group';
    addButton.disabled = !isGroup;
    addButton.classList.toggle('is-disabled', !isGroup);
    repeater.style.display = isGroup ? 'grid' : 'none';

    if (!isGroup) {
      repeater.innerHTML = '';
      cloneIndex = 1;
      expectedGuests.value = 1;
      return;
    }

    if (Number(expectedGuests.value || 0) < 2) {
      expectedGuests.value = 2;
    }
  }

  function addGuestCard() {
    const fragment = template.content.cloneNode(true);
    const card = fragment.querySelector('[data-guest-card]');

    card.querySelectorAll('[data-name]').forEach((field) => {
      field.name = `guests[${cloneIndex}][${field.dataset.name}]`;
      if ([
        'first_name',
        'last_name',
        'birth_date',
        'citizenship_label',
        'residence_province',
        'residence_place',
        'document_number',
        'document_expiry_date',
        'document_issue_place',
        'tourism_type',
        'transport_type',
      ].includes(field.dataset.name)) {
        field.required = true;
      }
    });

    const numberNode = card.querySelector('[data-guest-number]');
    if (numberNode) numberNode.textContent = String(cloneIndex + 1);

    const removeBtn = card.querySelector('[data-remove-guest]');
    removeBtn.addEventListener('click', () => {
      card.remove();
      expectedGuests.value = 1 + repeater.querySelectorAll('[data-guest-card]').length;
    });

    repeater.appendChild(card);
    initDates(card);
    cloneIndex += 1;
    expectedGuests.value = 1 + repeater.querySelectorAll('[data-guest-card]').length;
  }

  openButton?.addEventListener('click', () => {
    const nextState = !panel.classList.contains('is-open');
    setPanelState(nextState);
    if (nextState) {
      panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
  });

  closeButton?.addEventListener('click', () => setPanelState(false));

  initDates(form);
  recordType.addEventListener('change', updateGroupState);
  addButton.addEventListener('click', addGuestCard);
  updateGroupState();
  setPanelState(panel.classList.contains('is-open'));

  const highlightedRow = document.querySelector('[data-record-row].is-highlighted');
  if (highlightedRow) {
    highlightedRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }
});
