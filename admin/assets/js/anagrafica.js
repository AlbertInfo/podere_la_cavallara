document.addEventListener('DOMContentLoaded', function () {
  'use strict';

  function $(selector, scope) {
    return (scope || document).querySelector(selector);
  }

  function $all(selector, scope) {
    return Array.prototype.slice.call((scope || document).querySelectorAll(selector));
  }

  function parseJsonScript(id, fallback) {
    var node = document.getElementById(id);
    if (!node) return fallback;
    try {
      return JSON.parse(node.textContent || node.innerText || '');
    } catch (err) {
      return fallback;
    }
  }

  function safeReplaceUrl(url) {
    if (window.history && typeof window.history.replaceState === 'function') {
      window.history.replaceState({}, document.title, url);
    }
  }

  function formatDateForFlatpickr(input, isoDate) {
    if (!input) return;
    if (input._flatpickr) {
      input._flatpickr.setDate(isoDate, true, 'Y-m-d');
    } else {
      input.value = isoDate || '';
    }
  }

  var provinceMap = parseJsonScript('anagraficaProvinceMap', {});
  var comuniByProvince = parseJsonScript('anagraficaComuniByProvince', {});
  var globalPlaceListId = 'place-options';
  var globalStateListId = 'state-options';
  var globalProvinceListId = 'province-options';

  function normalizeValue(value) {
    return String(value || '')
      .trim()
      .toLowerCase()
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '');
  }

  function italySelected(value) {
    return normalizeValue(value) === 'italia';
  }

  function resolveProvinceCode(value) {
    var normalized = normalizeValue(value);
    if (!normalized) return '';
    if (provinceMap[value]) return provinceMap[value];
    var keys = Object.keys(provinceMap);
    for (var i = 0; i < keys.length; i += 1) {
      if (normalizeValue(keys[i]) === normalized) {
        return provinceMap[keys[i]];
      }
    }
    return '';
  }

  function ensureDatalist(input, idPrefix, fallbackListId) {
    if (!input) return null;
    if (input.dataset.datalistId) {
      return document.getElementById(input.dataset.datalistId);
    }

    var listId = input.getAttribute('list');
    if (!listId) {
      listId = idPrefix + '-' + Math.random().toString(36).slice(2, 8);
      input.setAttribute('list', listId);
    }

    var datalist = document.getElementById(listId);
    if (!datalist) {
      datalist = document.createElement('datalist');
      datalist.id = listId;
      input.parentNode.appendChild(datalist);
    }

    input.dataset.datalistId = listId;
    if (fallbackListId) {
      input.dataset.fallbackListId = fallbackListId;
    }
    return datalist;
  }

  function fillDatalist(datalist, values) {
    if (!datalist) return;
    datalist.innerHTML = '';
    (values || []).forEach(function (value) {
      var option = document.createElement('option');
      option.value = value;
      datalist.appendChild(option);
    });
  }

  function configurePlaceField(field, values, placeholder, disabled) {
    if (!field) return;
    field.disabled = !!disabled;
    if (placeholder) field.placeholder = placeholder;
    var datalist = ensureDatalist(field, field.getAttribute('data-place-role') || 'place', field.dataset.fallbackListId || globalPlaceListId);
    fillDatalist(datalist, values);
  }

  function updateProvinceFilteredPlaces(scope) {
    $all('[data-guest-scope]', scope || document).forEach(function (card) {
      var birthState = $('[data-state-role="birth"]', card);
      var birthProvince = $('[data-province-role="birth"]', card);
      var birthPlace = $('[data-place-role="birth"]', card);

      var residenceState = $('[data-state-role="residence"]', card);
      var residenceProvince = $('[data-province-role="residence"]', card);
      var residencePlace = $('[data-place-role="residence"]', card);

      if (birthState && birthProvince && birthPlace) {
        var birthIsItaly = italySelected(birthState.value);
        birthProvince.disabled = !birthIsItaly;
        var birthCode = resolveProvinceCode(birthProvince.value);
        if (birthIsItaly && birthCode) {
          configurePlaceField(birthPlace, comuniByProvince[birthCode] || [], 'Seleziona il comune di nascita', false);
        } else if (birthIsItaly) {
          configurePlaceField(birthPlace, [], 'Seleziona prima la provincia', false);
        } else {
          configurePlaceField(birthPlace, [], 'Per estero basta lo stato di nascita', true);
          if (!birthPlace.dataset.keepValue) birthPlace.value = '';
        }
      }

      if (residenceState && residenceProvince && residencePlace) {
        var residenceIsItaly = italySelected(residenceState.value);
        residenceProvince.disabled = !residenceIsItaly;
        var residenceCode = resolveProvinceCode(residenceProvince.value);
        if (residenceIsItaly && residenceCode) {
          configurePlaceField(residencePlace, comuniByProvince[residenceCode] || [], 'Seleziona il comune di residenza', false);
        } else if (residenceIsItaly) {
          configurePlaceField(residencePlace, [], 'Seleziona prima la provincia', false);
        } else {
          residencePlace.disabled = false;
          residencePlace.setAttribute('list', globalPlaceListId);
          residencePlace.placeholder = 'Località estera o codice NUTS';
        }
      }
    });
  }

  function addInvalidState(field, message) {
    if (!field) return;
    var label = field.closest('.anagrafica-field');
    if (label) label.classList.add('is-invalid');
    field.setCustomValidity(message || 'Campo obbligatorio');
  }

  function clearInvalidState(field) {
    if (!field) return;
    var label = field.closest('.anagrafica-field');
    if (label) label.classList.remove('is-invalid');
    field.setCustomValidity('');
  }

  function installLiveValidation(scope) {
    $all('input, select, textarea', scope || document).forEach(function (field) {
      field.addEventListener('input', function () { clearInvalidState(field); });
      field.addEventListener('change', function () { clearInvalidState(field); });
    });
  }

  function initFormPanel() {
    var formCard = document.getElementById('anagraficaFormCard');
    var form = document.getElementById('anagraficaForm');
    if (!formCard || !form) return;

    var closeButton = document.getElementById('closeAnagraficaForm');
    var openLinks = $all('[data-anagrafica-open-link]');
    var baseUrl = formCard.getAttribute('data-base-url') || window.location.pathname;
    var recordType = document.getElementById('recordType');
    var addButton = document.getElementById('addGuestButton');
    var repeater = document.getElementById('guestRepeater');
    var template = document.getElementById('guestTemplate');
    var expectedGuests = document.getElementById('expectedGuests');
    var arrivalField = $('[data-date-role="arrival"]', form);
    var bookingReceivedField = $('[data-date-role="booking-received"]', form);
    var departureField = $('[data-date-role="departure"]', form);
    var cloneIndex = repeater ? $all('[data-guest-card]', repeater).length + 1 : 1;
    var forceOpen = (formCard.getAttribute('data-force-open') || '0') === '1';

    function setPanelState(isOpen) {
      if (isOpen) {
        formCard.hidden = false;
        formCard.classList.add('is-open');
        window.requestAnimationFrame(function () {
          try {
            formCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
          } catch (err) {
            formCard.scrollIntoView(true);
          }
        });
      } else {
        formCard.hidden = true;
        formCard.classList.remove('is-open');
      }
    }

    function currentSelectedDay() {
      return formCard.getAttribute('data-selected-day') || '';
    }

    function clearGuestRepeater() {
      if (!repeater) return;
      $all('[data-guest-card]', repeater).forEach(function (card) { card.remove(); });
      cloneIndex = 1;
    }

    function resetNewFormValues() {
      form.reset();
      var selectedDay = currentSelectedDay();
      if (selectedDay) {
        formatDateForFlatpickr(bookingReceivedField, selectedDay);
        formatDateForFlatpickr(arrivalField, selectedDay);
      }
      if (departureField) {
        departureField.value = '';
        if (departureField._flatpickr) departureField._flatpickr.clear();
      }
      clearGuestRepeater();
      refreshGuestCounters();
      updateGroupState();
      updateProvinceFilteredPlaces(form);
      clearClientValidation(form);
    }

    function openPanel() {
      resetNewFormValues();
      setPanelState(true);
    }

    function closePanel() {
      setPanelState(false);
      safeReplaceUrl(baseUrl);
    }

    function createDatePicker(field, extraOptions) {
      if (!field || typeof window.flatpickr === 'undefined' || field._flatpickr) return null;
      var role = field.getAttribute('data-date-role') || '';
      var options = Object.assign({
        locale: (window.flatpickr.l10ns && window.flatpickr.l10ns.it) ? window.flatpickr.l10ns.it : 'default',
        dateFormat: 'd/m/Y',
        allowInput: true,
        disableMobile: true
      }, extraOptions || {});
      if (role === 'birth') options.maxDate = 'today';
      return window.flatpickr(field, options);
    }

    function syncDateConstraints(scope) {
      scope = scope || form;

      $all('[data-date-role="document-issue"]', scope).forEach(function (issueField) {
        var container = issueField.closest('[data-guest-card]') || scope;
        var expiryField = $('[data-date-role="document-expiry"]', container);
        if (!expiryField || !issueField._flatpickr || !expiryField._flatpickr) return;

        var issueDate = issueField._flatpickr.selectedDates[0] || null;
        var expiryDate = expiryField._flatpickr.selectedDates[0] || null;
        issueField._flatpickr.set('maxDate', expiryDate || null);
        expiryField._flatpickr.set('minDate', issueDate || null);
      });

      if (arrivalField && departureField && arrivalField._flatpickr && departureField._flatpickr) {
        var arrivalDate = arrivalField._flatpickr.selectedDates[0] || null;
        var departureDate = departureField._flatpickr.selectedDates[0] || null;
        arrivalField._flatpickr.set('maxDate', departureDate || null);
        departureField._flatpickr.set('minDate', arrivalDate || null);
      }
    }

    function initDates(scope) {
      $all('.js-date', scope || form).forEach(function (field) {
        createDatePicker(field, {
          onReady: [function () { syncDateConstraints(scope || form); }],
          onChange: [function () { syncDateConstraints(scope || form); }]
        });
      });
      syncDateConstraints(scope || form);
    }

    function refreshGuestCounters() {
      if (!repeater) return;
      var cards = $all('[data-guest-card]', repeater);
      cards.forEach(function (card, index) {
        var numberNode = $('[data-guest-number]', card);
        if (numberNode) numberNode.textContent = String(index + 2);
      });
      if (expectedGuests) {
        expectedGuests.value = String(1 + cards.length);
      }
    }

    function bindRemoveButtons(scope) {
      $all('[data-remove-guest]', scope || repeater).forEach(function (button) {
        if (button.dataset.bound === '1') return;
        button.dataset.bound = '1';
        button.addEventListener('click', function () {
          var card = button.closest('[data-guest-card]');
          if (card) {
            card.remove();
            refreshGuestCounters();
          }
        });
      });
    }

    function updateGroupState() {
      var isGroup = recordType && recordType.value !== 'single';
      if (addButton) {
        addButton.disabled = !isGroup;
        addButton.classList.toggle('is-disabled', !isGroup);
      }
      if (repeater) {
        repeater.style.display = isGroup ? 'grid' : 'none';
      }
      if (!isGroup) {
        clearGuestRepeater();
      }
      refreshGuestCounters();
    }

    function wireCloneLists(card, index) {
      $all('[data-list-template]', card).forEach(function (field) {
        var kind = field.getAttribute('data-list-template');
        var listId = kind + '-place-options-' + index;
        var datalist = document.createElement('datalist');
        datalist.id = listId;
        field.setAttribute('list', listId);
        field.parentNode.appendChild(datalist);
      });
    }

    function addGuestCard() {
      if (!template || !repeater) return;
      var fragment = template.content.cloneNode(true);
      var card = $('[data-guest-card]', fragment);
      if (!card) return;

      $all('[data-name]', card).forEach(function (field) {
        var dataName = field.getAttribute('data-name');
        field.name = 'guests[' + cloneIndex + '][' + dataName + ']';
      });

      wireCloneLists(card, cloneIndex);
      repeater.appendChild(card);
      bindRemoveButtons(card);
      initDates(card);
      installLiveValidation(card);
      cloneIndex += 1;
      refreshGuestCounters();
      updateProvinceFilteredPlaces(form);
    }

    function clearClientValidation(scope) {
      $all('.anagrafica-field.is-invalid', scope || form).forEach(function (field) {
        field.classList.remove('is-invalid');
      });
      $all('input, select, textarea', scope || form).forEach(function (field) {
        field.setCustomValidity('');
      });
    }

    function validateFormBeforeSubmit() {
      clearClientValidation(form);
      updateGroupState();
      updateProvinceFilteredPlaces(form);

      var firstInvalid = null;
      $all('input, select, textarea', form).forEach(function (field) {
        if (field.disabled || field.type === 'hidden') return;
        if (!field.checkValidity()) {
          addInvalidState(field, field.validationMessage || 'Campo obbligatorio');
          if (!firstInvalid) firstInvalid = field;
        }
      });

      if (firstInvalid) {
        try {
          firstInvalid.focus({ preventScroll: true });
          firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
        } catch (err) {}
        return false;
      }
      return true;
    }

    openLinks.forEach(function (link) {
      link.addEventListener('click', function (event) {
        event.preventDefault();
        openPanel();
      });
    });

    if (closeButton) {
      closeButton.addEventListener('click', function (event) {
        event.preventDefault();
        closePanel();
      });
    }

    if (addButton) addButton.addEventListener('click', addGuestCard);
    if (recordType) recordType.addEventListener('change', updateGroupState);

    form.addEventListener('submit', function (event) {
      if (!validateFormBeforeSubmit()) {
        event.preventDefault();
      }
    });

    $all('[data-state-role], [data-province-role]', form).forEach(function (field) {
      field.addEventListener('change', function () { updateProvinceFilteredPlaces(form); });
      field.addEventListener('input', function () { updateProvinceFilteredPlaces(form); });
    });

    initDates(form);
    bindRemoveButtons();
    installLiveValidation(form);
    refreshGuestCounters();
    updateGroupState();
    updateProvinceFilteredPlaces(form);
    setPanelState(forceOpen);
  }

  function initEditableRows() {
    $all('[data-record-row]').forEach(function (row) {
      var editUrl = row.getAttribute('data-edit-url');
      if (!editUrl) return;

      function shouldIgnore(target) {
        return !!(target && target.closest('[data-row-ignore]'));
      }

      row.addEventListener('click', function (event) {
        if (shouldIgnore(event.target)) return;
        window.location.href = editUrl;
      });

      row.addEventListener('keydown', function (event) {
        if (event.key !== 'Enter' && event.key !== ' ') return;
        if (shouldIgnore(event.target)) return;
        event.preventDefault();
        window.location.href = editUrl;
      });
    });
  }

  function initConfirmations() {
    $all('[data-delete-form]').forEach(function (deleteForm) {
      deleteForm.addEventListener('submit', function (event) {
        if (!window.confirm('Eliminare definitivamente questa anagrafica?')) {
          event.preventDefault();
        }
      });
    });
  }


  function initAlloggiatiModal() {
    var modal = document.getElementById('alloggiatiConfirmModal');
    var body = document.getElementById('alloggiatiConfirmBody');
    var confirmButton = document.getElementById('alloggiatiConfirmSubmit');
    if (!modal || !body || !confirmButton) return;

    var activeAction = null;

    function closeModal() {
      modal.hidden = true;
      document.body.classList.remove('is-modal-open');
      body.innerHTML = '';
      activeAction = null;
    }

    function openModal(html, action) {
      activeAction = action || null;
      body.innerHTML = html || '<p class="muted">Nessun riepilogo disponibile.</p>';
      modal.hidden = false;
      document.body.classList.add('is-modal-open');
    }

    $all('[data-modal-close]', modal).forEach(function (button) {
      button.addEventListener('click', function () { closeModal(); });
    });

    modal.addEventListener('click', function (event) {
      if (event.target === modal) closeModal();
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && !modal.hidden) {
        closeModal();
      }
    });

    confirmButton.addEventListener('click', function () {
      var action = activeAction;
      closeModal();
      if (!action) return;
      if (action.type === 'form' && action.form) {
        HTMLFormElement.prototype.submit.call(action.form);
        return;
      }
      if (action.type === 'link' && action.href) {
        window.location.href = action.href;
      }
    });

    $all('.js-alloggiati-modal-trigger, .js-confirm-modal-trigger').forEach(function (button) {
      button.addEventListener('click', function (event) {
        if (button.disabled || button.classList.contains('is-disabled') || button.getAttribute('aria-disabled') === 'true') {
          event.preventDefault();
          return;
        }
        var templateId = button.getAttribute('data-modal-template-id');
        var template = templateId ? document.getElementById(templateId) : null;
        var html = template ? template.innerHTML : '';
        var form = button.form || button.closest('form');
        var href = button.getAttribute('href');
        var action = null;
        if (form) {
          action = { type: 'form', form: form };
        } else if (href && href !== '#') {
          action = { type: 'link', href: href };
        }
        if (!action) return;
        event.preventDefault();
        openModal(html, action);
      });
    });
  }

  function initCarousel() {
    var carousel = $('[data-day-carousel]');
    var viewport = $('[data-day-carousel-viewport]');
    var strip = $('[data-day-strip]');
    var prevButton = $('[data-day-carousel-prev]');
    var nextButton = $('[data-day-carousel-next]');
    if (!carousel || !viewport || !strip) return;

    var pointer = {
      down: false,
      dragged: false,
      startX: 0,
      scrollLeft: 0,
      pointerId: null
    };

    function firstCard() {
      return $('.ross-day-card', strip);
    }

    function getStep() {
      var card = firstCard();
      if (!card) return Math.max(220, Math.round(viewport.clientWidth * 0.75));
      var gap = parseFloat(window.getComputedStyle(strip).gap || '0') || 0;
      return Math.round(card.getBoundingClientRect().width + gap);
    }

    function updateControls() {
      var max = Math.max(0, viewport.scrollWidth - viewport.clientWidth);
      if (prevButton) prevButton.disabled = viewport.scrollLeft <= 2;
      if (nextButton) nextButton.disabled = viewport.scrollLeft >= (max - 2);
    }

    function scrollStep(direction) {
      viewport.scrollBy({ left: getStep() * direction, behavior: 'smooth' });
      window.setTimeout(updateControls, 250);
    }

    if (prevButton) {
      prevButton.addEventListener('click', function (event) {
        event.preventDefault();
        scrollStep(-1);
      });
    }

    if (nextButton) {
      nextButton.addEventListener('click', function (event) {
        event.preventDefault();
        scrollStep(1);
      });
    }

    viewport.addEventListener('wheel', function (event) {
      if (Math.abs(event.deltaY) <= Math.abs(event.deltaX)) return;
      event.preventDefault();
      viewport.scrollLeft += event.deltaY;
      updateControls();
    }, { passive: false });

    viewport.addEventListener('pointerdown', function (event) {
      if (event.pointerType === 'mouse' && event.button !== 0) return;
      pointer.down = true;
      pointer.dragged = false;
      pointer.startX = event.clientX;
      pointer.scrollLeft = viewport.scrollLeft;
      pointer.pointerId = event.pointerId;
      carousel.classList.add('is-drag-ready');
    });

    viewport.addEventListener('pointermove', function (event) {
      if (!pointer.down) return;
      var delta = event.clientX - pointer.startX;
      if (Math.abs(delta) > 6) {
        pointer.dragged = true;
        carousel.classList.add('is-dragging');
        viewport.scrollLeft = pointer.scrollLeft - delta;
        updateControls();
      }
    });

    function stopPointer() {
      pointer.down = false;
      window.setTimeout(function () {
        pointer.dragged = false;
      }, 0);
      carousel.classList.remove('is-dragging');
      carousel.classList.remove('is-drag-ready');
    }

    viewport.addEventListener('pointerup', stopPointer);
    viewport.addEventListener('pointercancel', stopPointer);
    viewport.addEventListener('mouseleave', function () {
      if (pointer.down) stopPointer();
    });

    $all('.ross-day-card', strip).forEach(function (card) {
      card.addEventListener('click', function (event) {
        if (pointer.dragged) {
          event.preventDefault();
          return;
        }
        event.preventDefault();
        var href = card.getAttribute('href');
        if (href) {
          window.location.href = href;
        }
      });
    });

    var selectedCard = $('.ross-day-card.is-selected', strip);
    if (selectedCard) {
      var left = selectedCard.offsetLeft - ((viewport.clientWidth - selectedCard.offsetWidth) / 2);
      viewport.scrollLeft = Math.max(0, left);
    }

    viewport.addEventListener('scroll', updateControls, { passive: true });
    window.addEventListener('resize', updateControls);
    updateControls();
  }

  try { initFormPanel(); } catch (err) { console.error('Form panel init failed', err); }
  try { initEditableRows(); } catch (err) { console.error('Editable rows init failed', err); }
  try { initConfirmations(); } catch (err) { console.error('Confirmations init failed', err); }
  try { initCarousel(); } catch (err) { console.error('Carousel init failed', err); }
  try { initAlloggiatiModal(); } catch (err) { console.error('Alloggiati modal init failed', err); }
});


function initBookingSyncModal() {
  var modal = document.getElementById('bookingSyncModal');
  if (!modal) return;

  var form = document.getElementById('bookingSyncForm');
  var triggers = Array.prototype.slice.call(document.querySelectorAll('[data-booking-modal-trigger]'));
  var closeButtons = Array.prototype.slice.call(modal.querySelectorAll('[data-booking-modal-close]'));

  function qsa(selector, scope) {
    return Array.prototype.slice.call((scope || document).querySelectorAll(selector));
  }

  function setModalState(open) {
    modal.hidden = !open;
    modal.classList.toggle('is-open', !!open);
    document.body.classList.toggle('is-modal-open', !!open);
  }

  function fillField(name, value) {
    if (!form) return;
    var field = form.querySelector('[name="' + name + '"]');
    if (!field) return;
    if (field.type === 'checkbox') {
      field.checked = !!value;
      return;
    }
    field.value = value == null ? '' : String(value);
    if (field._flatpickr && value) {
      try { field._flatpickr.setDate(String(value), true, 'Y-m-d'); } catch (err) {}
    }
  }

  function updatePlaces(scope) {
    if (typeof updateProvinceFilteredPlaces === 'function') {
      try { updateProvinceFilteredPlaces(scope || modal); } catch (err) {}
    }
  }

  function clearClientErrors() {
    qsa('.anagrafica-field', modal).forEach(function (node) {
      node.classList.remove('is-invalid');
    });
    qsa('.anagrafica-field-error', modal).forEach(function (node) {
      if (!node.dataset.serverError) node.textContent = '';
    });
  }

  triggers.forEach(function (button) {
    button.addEventListener('click', function (event) {
      event.preventDefault();
      var raw = button.getAttribute('data-booking-payload');
      if (!raw) return;
      try {
        var payload = JSON.parse(raw);
        Object.keys(payload).forEach(function (key) { fillField(key, payload[key]); });
        clearClientErrors();
        updatePlaces(modal);
        setModalState(true);
      } catch (err) {
        console.error('Booking modal payload parse failed', err);
      }
    });
  });

  closeButtons.forEach(function (button) {
    button.addEventListener('click', function (event) {
      event.preventDefault();
      setModalState(false);
    });
  });

  modal.addEventListener('click', function (event) {
    if (event.target === modal || event.target.classList.contains('anagrafica-modal__backdrop')) {
      setModalState(false);
    }
  });

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && !modal.hidden) {
      setModalState(false);
    }
  });

  if (modal.classList.contains('is-open') || !modal.hidden) {
    updatePlaces(modal);
    setModalState(true);
  }
}

document.addEventListener('DOMContentLoaded', function () {
  try { initBookingSyncModal(); } catch (err) { console.error('Booking sync modal init failed', err); }
});
