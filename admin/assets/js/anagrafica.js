document.addEventListener('DOMContentLoaded', () => {
  if (typeof flatpickr === 'function') {
    document.querySelectorAll('.js-date').forEach((el) => {
      flatpickr(el, { dateFormat: 'd/m/Y', locale: 'it', allowInput: true });
    });
  }

  const form = document.getElementById('anagraficaForm');
  if (!form) return;

  const recordType = document.getElementById('recordType');
  const expectedGuests = document.getElementById('expectedGuests');
  const repeater = document.getElementById('guestRepeater');
  const addButton = document.getElementById('addGuestButton');
  const template = document.getElementById('guestTemplate');

  let cloneIndex = 1;

  function initDates(scope) {
    if (typeof flatpickr !== 'function') return;
    scope.querySelectorAll('.js-date').forEach((el) => {
      if (el._flatpickr) return;
      flatpickr(el, { dateFormat: 'd/m/Y', locale: 'it', allowInput: true });
    });
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
    } else if (Number(expectedGuests.value || 0) < 2) {
      expectedGuests.value = 2;
    }
  }

  function addGuestCard() {
    const fragment = template.content.cloneNode(true);
    const card = fragment.querySelector('[data-guest-card]');
    card.querySelectorAll('[data-name]').forEach((field) => {
      field.name = `guests[${cloneIndex}][${field.dataset.name}]`;
      if (['first_name', 'last_name', 'birth_date', 'citizenship_label', 'residence_province', 'residence_place', 'document_number', 'document_expiry_date', 'document_issue_place', 'tourism_type', 'transport_type'].includes(field.dataset.name)) {
        field.required = true;
      }
    });
    const numberNode = card.querySelector('[data-guest-number]');
    if (numberNode) numberNode.textContent = String(cloneIndex + 1);
    const removeBtn = card.querySelector('[data-remove-guest]');
    removeBtn.addEventListener('click', () => {
      card.remove();
      const count = 1 + repeater.querySelectorAll('[data-guest-card]').length;
      expectedGuests.value = count;
    });
    repeater.appendChild(card);
    initDates(card);
    cloneIndex += 1;
    expectedGuests.value = 1 + repeater.querySelectorAll('[data-guest-card]').length;
  }

  recordType.addEventListener('change', updateGroupState);
  addButton.addEventListener('click', addGuestCard);
  updateGroupState();
});
