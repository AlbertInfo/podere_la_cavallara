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

  function triggerPendingDownload() {
    var node = document.getElementById('pendingBrowserDownload');
    if (!node) return;

    var base64 = node.getAttribute('data-content') || '';
    var filename = node.getAttribute('data-filename') || 'download.txt';
    var mime = node.getAttribute('data-mime') || 'application/octet-stream';
    if (!base64) return;

    try {
      var binary = window.atob(base64);
      var length = binary.length;
      var bytes = new Uint8Array(length);
      for (var i = 0; i < length; i += 1) {
        bytes[i] = binary.charCodeAt(i);
      }
      var blob = new Blob([bytes], { type: mime });
      var url = window.URL.createObjectURL(blob);
      var link = document.createElement('a');
      link.href = url;
      link.download = filename;
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
      window.setTimeout(function () {
        window.URL.revokeObjectURL(url);
      }, 1500);
    } catch (err) {
      console.error('Download automatico non riuscito', err);
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
    var normalized = normalizeValue(value);
    return normalized === 'italia' || String(value || '').trim() === '100000100';
  }

  function resolveProvinceCode(value) {
    var normalized = normalizeValue(value);
    if (!normalized) return '';
    if (provinceMap[value]) return provinceMap[value];
    var keys = Object.keys(provinceMap);
    for (var i = 0; i < keys.length; i += 1) {
      if (normalizeValue(keys[i]) === normalized || normalizeValue(provinceMap[keys[i]]) === normalized) {
        return provinceMap[keys[i]];
      }
    }
    return '';
  }

  function resolveSelectOptionValue(field, value) {
    if (!field) return value == null ? '' : String(value);
    var raw = value == null ? '' : String(value).trim();
    if (!raw) return '';
    var normalized = normalizeValue(raw);
    var options = Array.prototype.slice.call(field.options || []);
    for (var i = 0; i < options.length; i += 1) {
      var option = options[i];
      if (String(option.value) === raw) return option.value;
      if (normalizeValue(option.value) === normalized || normalizeValue(option.textContent) === normalized) {
        return option.value;
      }
    }
    return raw;
  }

  function setFieldValueSmart(field, value) {
    if (!field) return;
    if (field.tagName === 'SELECT') {
      field.value = resolveSelectOptionValue(field, value);
      return;
    }
    field.value = value == null ? '' : String(value);
  }

  function ensureDatalist(input, idPrefix, fallbackListId) {
    if (!input) return null;
    var listId = input.getAttribute('list') || input.dataset.datalistId || '';
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
    if (fallbackListId) input.dataset.fallbackListId = fallbackListId;
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

  function setFieldVisibility(field, visible) {
    if (!field) return;
    var label = field.closest('.anagrafica-field');
    if (label) {
      label.classList.toggle('is-hidden', !visible);
    }
  }

  function configurePlaceField(field, values, placeholder, options) {
    if (!field) return;
    options = options || {};
    field.disabled = !!options.disabled;
    field.readOnly = !!options.readOnly;
    if (placeholder) field.placeholder = placeholder;
    if (options.listId === null) {
      field.removeAttribute('list');
    } else {
      var datalist = ensureDatalist(field, field.getAttribute('data-place-role') || 'place', options.fallbackListId || field.dataset.fallbackListId || globalPlaceListId);
      if (options.listId) {
        field.setAttribute('list', options.listId);
      } else if (datalist) {
        field.setAttribute('list', datalist.id);
      }
      fillDatalist(datalist, values);
    }
  }

  function updateProvinceFilteredPlaces(scope) {
    $all('[data-guest-scope]', scope || document).forEach(function (card) {
      var birthState = $('[data-state-role="birth"]', card);
      var birthProvince = $('[data-province-role="birth"]', card);
      var birthPlace = $('[data-place-role="birth"]', card);
      var residenceState = $('[data-state-role="residence"]', card);
      var residenceProvince = $('[data-province-role="residence"]', card);
      var residencePlace = $('[data-place-role="residence"]', card);
      var residenceLabel = $('[data-residence-place-label]', card);

      if (birthState && birthProvince && birthPlace) {
        var birthIsItaly = italySelected(birthState.value);
        var birthCode = resolveProvinceCode(birthProvince.value);
        birthProvince.disabled = !birthIsItaly;
        setFieldVisibility(birthProvince, birthIsItaly);
        setFieldVisibility(birthPlace, birthIsItaly);
        if (birthIsItaly && birthCode) {
          configurePlaceField(birthPlace, comuniByProvince[birthCode] || [], 'Seleziona il comune di nascita', { readOnly: false });
        } else if (birthIsItaly) {
          configurePlaceField(birthPlace, [], 'Seleziona prima la provincia', { readOnly: true });
        } else {
          birthProvince.value = '';
          birthPlace.value = '';
          configurePlaceField(birthPlace, [], 'Per estero basta lo stato di nascita', { readOnly: true, listId: null });
        }
      }

      if (residenceState && residenceProvince && residencePlace) {
        var residenceIsItaly = italySelected(residenceState.value);
        var residenceCode = resolveProvinceCode(residenceProvince.value);
        residenceProvince.disabled = !residenceIsItaly;
        setFieldVisibility(residenceProvince, residenceIsItaly);
        if (residenceLabel) {
          residenceLabel.textContent = residenceIsItaly ? 'Comune residenza' : 'Comune / località residenza';
        }
        if (residenceIsItaly && residenceCode) {
          configurePlaceField(residencePlace, comuniByProvince[residenceCode] || [], 'Seleziona il comune di residenza', { readOnly: false });
        } else if (residenceIsItaly) {
          configurePlaceField(residencePlace, [], 'Seleziona prima la provincia', { readOnly: true });
        } else {
          residenceProvince.value = '';
          configurePlaceField(residencePlace, [], 'Località estera o codice NUTS', { readOnly: false, fallbackListId: globalPlaceListId, listId: globalPlaceListId });
        }
      }
    });
  }

  function ensureErrorNode(field) {
    var label = field ? field.closest('.anagrafica-field') : null;
    if (!label) return null;
    var node = label.querySelector('.anagrafica-field-error');
    if (!node) {
      node = document.createElement('small');
      node.className = 'anagrafica-field-error';
      label.appendChild(node);
    }
    return node;
  }

  function addInvalidState(field, message) {
    if (!field) return;
    var label = field.closest('.anagrafica-field');
    if (!label) return;
    label.classList.add('is-invalid');
    label.classList.remove('is-valid');
    var errorNode = ensureErrorNode(field);
    if (errorNode) errorNode.textContent = message || 'Campo obbligatorio';
    field.setCustomValidity(message || 'Campo obbligatorio');
  }

  function addValidState(field) {
    if (!field) return;
    var label = field.closest('.anagrafica-field');
    if (!label) return;
    label.classList.remove('is-invalid');
    label.classList.add('is-valid');
    var errorNode = label.querySelector('.anagrafica-field-error');
    if (errorNode && !errorNode.dataset.serverError) errorNode.textContent = '';
    field.setCustomValidity('');
  }

  function clearInvalidState(field) {
    if (!field) return;
    var label = field.closest('.anagrafica-field');
    if (!label) return;
    label.classList.remove('is-invalid');
    if (!String(field.value || '').trim()) {
      label.classList.remove('is-valid');
    }
    var errorNode = label.querySelector('.anagrafica-field-error');
    if (errorNode && !errorNode.dataset.serverError) errorNode.textContent = '';
    field.setCustomValidity('');
  }

  function fieldIsVisible(field) {
    if (!field) return false;
    var label = field.closest('.anagrafica-field');
    if (label && label.classList.contains('is-hidden')) return false;
    return !field.disabled && field.type !== 'hidden';
  }

  function hasMatchingDatalistValue(field) {
    var listId = field && field.getAttribute('list');
    if (!listId) return true;
    var datalist = document.getElementById(listId);
    if (!datalist) return true;
    var value = normalizeValue(field.value);
    if (!value) return false;
    var options = Array.prototype.slice.call(datalist.options || []);
    if (!options.length) return true;
    return options.some(function (option) { return normalizeValue(option.value) === value; });
  }

  function normalizeStepKey(key) {
    if (key === 'birth-residence') return 'identity';
    return key || 'identity';
  }

  function fieldValidationMessage(field) {
    if (!field || !fieldIsVisible(field) || field.readOnly) return '';
    var value = String(field.value || '').trim();
    var guestScope = field.closest('[data-guest-scope]') || field.closest('form');
    var role = field.getAttribute('data-place-role') || '';
    var provinceRole = field.getAttribute('data-province-role') || '';
    var stateRole = field.getAttribute('data-state-role') || '';
    if (provinceRole === 'birth') {
      var birthState = $('[data-state-role="birth"]', guestScope);
      if (birthState && italySelected(birthState.value) && !value) return 'Seleziona la provincia di nascita.';
      return '';
    }
    if (role === 'birth') {
      var birthStateField = $('[data-state-role="birth"]', guestScope);
      if (birthStateField && italySelected(birthStateField.value)) {
        if (!value) return 'Seleziona il comune di nascita.';
        if (!hasMatchingDatalistValue(field)) return 'Seleziona un comune di nascita valido.';
      }
      return '';
    }
    if (provinceRole === 'residence') {
      var residenceState = $('[data-state-role="residence"]', guestScope);
      if (residenceState && italySelected(residenceState.value) && !value) return 'Seleziona la provincia di residenza.';
      return '';
    }
    if (role === 'residence') {
      var residenceStateField = $('[data-state-role="residence"]', guestScope);
      var residenceProvince = $('[data-province-role="residence"]', guestScope);
      if (residenceStateField && italySelected(residenceStateField.value)) {
        if (!String(residenceProvince && residenceProvince.value || '').trim()) return 'Seleziona prima la provincia di residenza.';
        if (!value) return 'Seleziona il comune di residenza.';
        if (!hasMatchingDatalistValue(field)) return 'Seleziona un comune di residenza valido.';
      } else if (!value) {
        return 'Indica la località o il codice NUTS di residenza.';
      }
      return '';
    }
    if (stateRole && !value) {
      return 'Seleziona uno stato.';
    }
    if (field.hasAttribute('required') && !value) {
      if ((field.getAttribute('data-date-role') || '') !== '') return 'Inserisci una data valida.';
      if (field.tagName === 'SELECT') return 'Seleziona un valore.';
      return 'Campo obbligatorio.';
    }
    if ((field.getAttribute('data-date-role') || '') !== '' && value && !/^\d{2}\/\d{2}\/\d{4}$/.test(value)) {
      return 'Usa il formato gg/mm/aaaa.';
    }
    return '';
  }

  function validateField(field, force) {
    if (!field || field.type === 'hidden' || field.readOnly) return true;
    if (!fieldIsVisible(field)) {
      clearInvalidState(field);
      return true;
    }
    var value = String(field.value || '').trim();
    var touched = force || field.dataset.touched === '1' || value !== '';
    if (!touched) {
      clearInvalidState(field);
      return false;
    }
    var message = fieldValidationMessage(field);
    if (message) {
      addInvalidState(field, message);
      return false;
    }
    if (value !== '') addValidState(field); else clearInvalidState(field);
    return value !== '' || !field.hasAttribute('required');
  }

  function updateFormProgress(form) {
    if (!form) return;
    var progress = $('[data-form-progress]', form.parentNode || form);
    if (!progress) return;
    var fields = $all('input, select, textarea', form).filter(function (field) {
      if (field.type === 'hidden' || field.readOnly || !fieldIsVisible(field)) return false;
      return field.hasAttribute('required') || field.hasAttribute('data-place-role') || field.hasAttribute('data-province-role') || field.hasAttribute('data-state-role');
    });
    var total = fields.length;
    var valid = 0;
    var stepStates = {};
    fields.forEach(function (field) {
      var label = field.closest('.anagrafica-field');
      var stepSection = field.closest('[data-step-section]');
      var stepKey = normalizeStepKey(stepSection ? stepSection.getAttribute('data-step-section') : 'identity');
      if (!stepStates[stepKey]) stepStates[stepKey] = { total: 0, valid: 0 };
      stepStates[stepKey].total += 1;
      if (label && label.classList.contains('is-valid')) {
        valid += 1;
        stepStates[stepKey].valid += 1;
      }
    });
    var percent = total ? Math.round((valid / total) * 100) : 0;
    var bar = $('[data-progress-bar]', progress);
    if (bar) bar.style.width = percent + '%';
    var textNode = $('[data-progress-text]', progress);
    if (textNode) {
      textNode.textContent = total ? (valid + ' campi validi su ' + total + ' · modulo completato al ' + percent + '%') : 'Compila i campi del modulo in sequenza.';
    }
    $all('[data-step-key]', progress).forEach(function (stepNode) {
      var key = stepNode.getAttribute('data-step-key') || '';
      var state = stepStates[key] || { total: 0, valid: 0 };
      stepNode.classList.toggle('is-complete', state.total > 0 && state.valid === state.total);
      stepNode.classList.toggle('is-active', state.valid > 0 && state.valid < state.total);
    });
  }

  function focusNextField(field) {
    var form = field && field.form;
    if (!form) return;
    var candidates = $all('input, select, textarea, button', form).filter(function (node) {
      return fieldIsVisible(node) && !node.readOnly && node.tabIndex !== -1 && node.type !== 'hidden' && !node.disabled;
    });
    var index = candidates.indexOf(field);
    if (index === -1) return;
    for (var i = index + 1; i < candidates.length; i += 1) {
      var next = candidates[i];
      if (next && next !== field) {
        try { next.focus({ preventScroll: true }); } catch (err) { next.focus(); }
        break;
      }
    }
  }

  function installLiveValidation(scope) {
    var form = scope && scope.tagName === 'FORM' ? scope : (scope ? scope.closest('form') : null);
    if (!form || form.dataset.guidedBound === '1') return;
    form.dataset.guidedBound = '1';

    ['input', 'change', 'focusout'].forEach(function (eventName) {
      form.addEventListener(eventName, function (event) {
        var field = event.target;
        if (!field || !/^(INPUT|SELECT|TEXTAREA)$/.test(field.tagName)) return;
        if (eventName !== 'input') field.dataset.touched = '1';
        if (field.matches('[data-state-role], [data-province-role]')) {
          updateProvinceFilteredPlaces(form);
        }
        validateField(field, eventName === 'focusout');
        updateFormProgress(form);
        if ((eventName === 'change' || eventName === 'focusout') && field.dataset.autoAdvance === '1' && validateField(field, true)) {
          focusNextField(field);
        }
      });
    });

    form.addEventListener('submit', function (event) {
      updateProvinceFilteredPlaces(form);
      var invalid = null;
      $all('input, select, textarea', form).forEach(function (field) {
        field.dataset.touched = '1';
        if (!validateField(field, true) && !invalid && fieldIsVisible(field)) {
          invalid = field;
        }
      });
      updateFormProgress(form);
      if (invalid) {
        event.preventDefault();
        try {
          invalid.focus({ preventScroll: true });
          invalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
        } catch (err) {}
      }
    });

    updateProvinceFilteredPlaces(form);
    $all('input, select, textarea', form).forEach(function (field) {
      if (String(field.value || '').trim() !== '') {
        validateField(field, false);
      }
    });
    updateFormProgress(form);
  }

  function roleLabelsForRecordType(recordTypeValue) {
    if (recordTypeValue === 'family') {
      return {
        leaderSectionTitle: 'Capofamiglia / primo ospite',
        leaderSectionDescription: 'La prima anagrafica sarà il capofamiglia.',
        repeaterSectionTitle: 'Familiari',
        repeaterSectionDescription: 'I componenti aggiunti saranno salvati come familiari.',
        addButtonLabel: 'Aggiungi familiare',
        memberCardLabel: 'Familiare'
      };
    }
    if (recordTypeValue === 'group') {
      return {
        leaderSectionTitle: 'Capogruppo / primo ospite',
        leaderSectionDescription: 'La prima anagrafica sarà il capogruppo.',
        repeaterSectionTitle: 'Membri gruppo',
        repeaterSectionDescription: 'I componenti aggiunti saranno salvati come membri gruppo.',
        addButtonLabel: 'Aggiungi membro',
        memberCardLabel: 'Membro gruppo'
      };
    }
    return {
      leaderSectionTitle: 'Ospite principale',
      leaderSectionDescription: 'Compila i dati dell\'ospite.',
      repeaterSectionTitle: 'Componenti aggiuntivi',
      repeaterSectionDescription: 'Per questa composizione non sono previsti componenti aggiuntivi.',
      addButtonLabel: 'Aggiungi componente',
      memberCardLabel: 'Componente'
    };
  }

  function alloggiatiTypeDisplayValue(recordTypeValue, guestIndex) {
    var index = parseInt(guestIndex, 10);
    var isLeader = isNaN(index) || index === 0;
    if (recordTypeValue === 'family') {
      return isLeader ? 'Capofamiglia' : 'Familiare';
    }
    if (recordTypeValue === 'group') {
      return isLeader ? 'Capogruppo' : 'Membro gruppo';
    }
    return 'Ospite singolo';
  }

  function refreshDerivedAlloggiatiTypes(scope, recordTypeValue) {
    $all('[data-alloggiati-type-display]', scope || document).forEach(function (field) {
      var guestIndex = field.getAttribute('data-guest-index') || '0';
      field.value = alloggiatiTypeDisplayValue(recordTypeValue || 'single', guestIndex);
    });
  }

  function bindProvincePlaceDependencies(scope, rootForm) {
    updateProvinceFilteredPlaces(rootForm || scope || document);
  }

  function refreshStructureLabels(scope, recordTypeValue) {
    var labels = roleLabelsForRecordType(recordTypeValue || 'single');
    $all('[data-leader-section-title]', scope || document).forEach(function (node) {
      node.textContent = labels.leaderSectionTitle;
    });
    $all('[data-leader-section-description]', scope || document).forEach(function (node) {
      node.textContent = labels.leaderSectionDescription;
    });
    $all('[data-repeater-section-title]', scope || document).forEach(function (node) {
      node.textContent = labels.repeaterSectionTitle;
    });
    $all('[data-repeater-section-description]', scope || document).forEach(function (node) {
      node.textContent = labels.repeaterSectionDescription;
    });
    $all('[data-add-guest-label]', scope || document).forEach(function (node) {
      node.textContent = labels.addButtonLabel;
    });
    $all('[data-guest-role-label]', scope || document).forEach(function (node) {
      node.textContent = labels.memberCardLabel;
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
      refreshDerivedAlloggiatiTypes(form, recordType ? recordType.value : 'single');
      refreshStructureLabels(form, recordType ? recordType.value : 'single');
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
      refreshDerivedAlloggiatiTypes(form, recordType ? recordType.value : 'single');
      refreshStructureLabels(form, recordType ? recordType.value : 'single');
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
      $all('[data-alloggiati-type-display]', card).forEach(function (field) {
        field.setAttribute('data-guest-index', String(cloneIndex));
      });

      wireCloneLists(card, cloneIndex);
      repeater.appendChild(card);
      bindRemoveButtons(card);
      initDates(card);
      installLiveValidation(card);
      bindProvincePlaceDependencies(card, form);
      cloneIndex += 1;
      refreshGuestCounters();
      refreshDerivedAlloggiatiTypes(form, recordType ? recordType.value : 'single');
      refreshStructureLabels(form, recordType ? recordType.value : 'single');
      updateProvinceFilteredPlaces(form);
    }

    function clearClientValidation(scope) {
      $all('.anagrafica-field.is-invalid, .anagrafica-field.is-valid', scope || form).forEach(function (field) {
        field.classList.remove('is-invalid');
        field.classList.remove('is-valid');
      });
      $all('input, select, textarea', scope || form).forEach(function (field) {
        field.setCustomValidity('');
      });
    }

    function validateFormBeforeSubmit() {
      updateGroupState();
      updateProvinceFilteredPlaces(form);

      var firstInvalid = null;
      $all('input, select, textarea', form).forEach(function (field) {
        field.dataset.touched = '1';
        if (!validateField(field, true) && !firstInvalid && fieldIsVisible(field)) {
          firstInvalid = field;
        }
      });
      updateFormProgress(form);

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

    bindProvincePlaceDependencies(form, form);

    initDates(form);
    bindRemoveButtons();
    installLiveValidation(form);
    refreshGuestCounters();
    updateGroupState();
    refreshDerivedAlloggiatiTypes(form, recordType ? recordType.value : 'single');
    refreshStructureLabels(form, recordType ? recordType.value : 'single');
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

    function submitConfirmedForm(action) {
      if (!action || !action.form) return;
      var form = action.form;
      var submitter = action.submitter || null;

      if (submitter && typeof form.requestSubmit === 'function') {
        form.requestSubmit(submitter);
        return;
      }

      var originalAction = form.getAttribute('action');
      var originalMethod = form.getAttribute('method');
      var originalEnctype = form.getAttribute('enctype');
      var originalTarget = form.getAttribute('target');
      var cleanupNodes = [];

      if (submitter) {
        var overrideAction = submitter.getAttribute('formaction');
        var overrideMethod = submitter.getAttribute('formmethod');
        var overrideEnctype = submitter.getAttribute('formenctype');
        var overrideTarget = submitter.getAttribute('formtarget');
        if (overrideAction) form.setAttribute('action', overrideAction);
        if (overrideMethod) form.setAttribute('method', overrideMethod);
        if (overrideEnctype) form.setAttribute('enctype', overrideEnctype);
        if (overrideTarget) form.setAttribute('target', overrideTarget);

        var submitterName = submitter.getAttribute('name');
        if (submitterName) {
          var hidden = document.createElement('input');
          hidden.type = 'hidden';
          hidden.name = submitterName;
          hidden.value = submitter.value || '';
          form.appendChild(hidden);
          cleanupNodes.push(hidden);
        }
      }

      HTMLFormElement.prototype.submit.call(form);

      cleanupNodes.forEach(function (node) {
        if (node.parentNode) node.parentNode.removeChild(node);
      });

      if (originalAction !== null) form.setAttribute('action', originalAction); else form.removeAttribute('action');
      if (originalMethod !== null) form.setAttribute('method', originalMethod); else form.removeAttribute('method');
      if (originalEnctype !== null) form.setAttribute('enctype', originalEnctype); else form.removeAttribute('enctype');
      if (originalTarget !== null) form.setAttribute('target', originalTarget); else form.removeAttribute('target');
    }

    confirmButton.addEventListener('click', function () {
      var action = activeAction;
      closeModal();
      if (!action) return;
      if (action.type === 'form' && action.form) {
        submitConfirmedForm(action);
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
          action = { type: 'form', form: form, submitter: button };
        } else if (href && href !== '#') {
          action = { type: 'link', href: href };
        }
        if (!action) return;
        event.preventDefault();
        openModal(html, action);
      });
    });
  }


function initDialogModals() {
  var activeModal = null;

  function setModalState(modal, open) {
    if (!modal) return;
    modal.hidden = !open;
    modal.classList.toggle('is-open', !!open);
    document.body.classList.toggle('is-modal-open', !!open);
    activeModal = open ? modal : null;
  }

  $all('[data-dialog-open]').forEach(function (trigger) {
    trigger.addEventListener('click', function (event) {
      event.preventDefault();
      var id = trigger.getAttribute('data-dialog-open');
      var modal = id ? document.getElementById(id) : null;
      if (!modal) return;
      setModalState(modal, true);
    });
  });

  $all('[data-dialog-close]').forEach(function (trigger) {
    trigger.addEventListener('click', function (event) {
      event.preventDefault();
      var modal = trigger.closest('.anagrafica-modal');
      if (modal) setModalState(modal, false);
    });
  });

  $all('.anagrafica-modal').forEach(function (modal) {
    modal.addEventListener('click', function (event) {
      if (event.target === modal || event.target.classList.contains('anagrafica-modal__backdrop')) {
        setModalState(modal, false);
      }
    });
  });

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && activeModal) {
      setModalState(activeModal, false);
    }
  });
}

function initMonthPickerAutoSubmit() {
  var monthInput = $('[data-month-picker-input]');
  if (!monthInput || !monthInput.form) return;
  monthInput.addEventListener('change', function () {
    monthInput.form.submit();
  });
}

function initMonthRangeConfigurator() {
  var modal = document.getElementById('rossMonthSettingsModal');
  if (!modal) return;

  var list = $('[data-month-ranges]', modal);
  var addButton = $('[data-add-month-range]', modal);
  var template = document.getElementById('rossMonthRangeTemplate');
  if (!list || !addButton || !template) return;

  function bindRow(row) {
    var removeButton = $('[data-remove-month-range]', row);
    if (removeButton) {
      removeButton.addEventListener('click', function () {
        row.parentNode.removeChild(row);
      });
    }
  }

  function addRow(defaultState) {
    var fragment = template.content.cloneNode(true);
    var row = $('[data-month-range-row]', fragment);
    if (!row) return;
    var state = row.querySelector('select[name="range_state[]"]');
    var fromSelect = row.querySelector('select[name="range_from[]"]');
    var toSelect = row.querySelector('select[name="range_to[]"]');
    if (state && defaultState) state.value = defaultState;
    if (fromSelect && toSelect) toSelect.value = fromSelect.value;
    list.appendChild(row);
    bindRow(row);
  }

  addButton.addEventListener('click', function () {
    addRow('closed');
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


  function initBookingSyncModal() {
    var modal = document.getElementById('bookingSyncModal');
    var form = document.getElementById('bookingSyncForm');
    if (!modal || !form) return;

    var triggers = $all('[data-booking-modal-trigger]');
    var closeButtons = $all('[data-booking-modal-close]', modal);
    var recordType = document.getElementById('bookingRecordType');
    var addButton = document.getElementById('bookingAddGuestButton');
    var repeater = document.getElementById('bookingGuestRepeater');
    var template = document.getElementById('bookingGuestTemplate');
    var hiddenPrenotazioneId = form.querySelector('input[name="prenotazione_id"]');
    var hiddenRecordId = form.querySelector('input[name="linked_record_id"]');
    var arrivalField = $('[data-date-role="arrival"]', form);
    var departureField = $('[data-date-role="departure"]', form);
    var bookingReceivedField = $('[data-date-role="booking-received"]', form);
    var cloneIndex = repeater ? $all('[data-guest-card]', repeater).length + 1 : 1;

    function setModalState(open) {
      modal.hidden = !open;
      modal.classList.toggle('is-open', !!open);
      document.body.classList.toggle('is-modal-open', !!open);
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

    function refreshGuestCounters() {
      if (!repeater) return;
      var cards = $all('[data-guest-card]', repeater);
      cards.forEach(function (card, index) {
        var numberNode = $('[data-guest-number]', card);
        if (numberNode) numberNode.textContent = String(index + 2);
      });
    }

    function clearRepeater() {
      if (!repeater) return;
      $all('[data-guest-card]', repeater).forEach(function (card) { card.remove(); });
      cloneIndex = 1;
    }

    function wireCloneLists(card, index) {
      $all('[data-list-template]', card).forEach(function (field) {
        var kind = field.getAttribute('data-list-template');
        var listId = kind + '-booking-place-options-' + index + '-' + Math.random().toString(36).slice(2, 8);
        var datalist = document.createElement('datalist');
        datalist.id = listId;
        field.setAttribute('list', listId);
        field.parentNode.appendChild(datalist);
      });
    }

    function setGuestValues(card, guest) {
      guest = guest || {};
      $all('[data-name]', card).forEach(function (field) {
        var key = field.getAttribute('data-name');
        if (!key) return;
        var value = guest[key];
        if ((field.getAttribute('data-date-role') || '') !== '') {
          if (field._flatpickr && value) {
            try { field._flatpickr.setDate(String(value), true, 'Y-m-d'); } catch (err) { field.value = value || ''; }
          } else {
            field.value = value || '';
          }
        } else {
          setFieldValueSmart(field, value);
        }
      });
      updateProvinceFilteredPlaces(card);
    }

    function addGuestCard(guest) {
      if (!template || !repeater) return;
      var fragment = template.content.cloneNode(true);
      var card = $('[data-guest-card]', fragment);
      if (!card) return;

      $all('[data-name]', card).forEach(function (field) {
        var dataName = field.getAttribute('data-name');
        field.name = 'guests[' + cloneIndex + '][' + dataName + ']';
      });
      $all('[data-alloggiati-type-display]', card).forEach(function (field) {
        field.setAttribute('data-guest-index', String(cloneIndex));
      });

      wireCloneLists(card, cloneIndex);
      repeater.appendChild(card);
      initDates(card);
      installLiveValidation(card);
      bindRemoveButtons(card);
      setGuestValues(card, guest || {});
      cloneIndex += 1;
      refreshGuestCounters();
      refreshDerivedAlloggiatiTypes(form, recordType ? recordType.value : 'single');
      refreshStructureLabels(form, recordType ? recordType.value : 'single');
      updateProvinceFilteredPlaces(form);
    }

    function setLeaderValues(guest) {
      guest = guest || {};
      var fields = {
        'first_name': 'guests[0][first_name]',
        'last_name': 'guests[0][last_name]',
        'gender': 'guests[0][gender]',
        'birth_date': 'guests[0][birth_date]',
        'citizenship_label': 'guests[0][citizenship_label]',
        'birth_state_label': 'guests[0][birth_state_label]',
        'birth_province': 'guests[0][birth_province]',
        'birth_place_label': 'guests[0][birth_place_label]',
        'residence_state_label': 'guests[0][residence_state_label]',
        'residence_province': 'guests[0][residence_province]',
        'residence_place_label': 'guests[0][residence_place_label]',
        'document_type_label': 'guests[0][document_type_label]',
        'document_number': 'guests[0][document_number]',
        'document_issue_place': 'guests[0][document_issue_place]',
        'tourism_type': 'guests[0][tourism_type]',
        'transport_type': 'guests[0][transport_type]'
      };
      Object.keys(fields).forEach(function (key) {
        var field = form.querySelector('[name="' + fields[key] + '"]');
        if (!field) return;
        var value = guest[key];
        if ((field.getAttribute('data-date-role') || '') !== '') {
          if (field._flatpickr && value) {
            try { field._flatpickr.setDate(String(value), true, 'Y-m-d'); } catch (err) { field.value = value || ''; }
          } else {
            field.value = value || '';
          }
        } else {
          setFieldValueSmart(field, value);
        }
      });
      updateProvinceFilteredPlaces(form);
    }

    function updateGroupState() {
      var isGroup = recordType && recordType.value !== 'single';
      if (addButton) {
        addButton.disabled = !isGroup;
        addButton.classList.toggle('is-disabled', !isGroup);
      }
      if (repeater) {
        repeater.style.display = isGroup ? 'grid' : 'none';
        if (!isGroup) {
          clearRepeater();
        }
      }
      refreshGuestCounters();
      refreshDerivedAlloggiatiTypes(form, recordType ? recordType.value : 'single');
      refreshStructureLabels(form, recordType ? recordType.value : 'single');
    }

    function clearClientErrors() {
      $all('.anagrafica-field', modal).forEach(function (node) { node.classList.remove('is-invalid'); });
      $all('.anagrafica-field-error', modal).forEach(function (node) {
        if (!node.dataset.serverError) node.textContent = '';
      });
      $all('input, select, textarea', modal).forEach(function (field) { field.setCustomValidity(''); });
    }

    function fillTopLevel(payload) {
      var map = {
        'record_type': recordType,
        'booking_reference': form.querySelector('[name="booking_reference"]'),
        'booking_received_date': bookingReceivedField,
        'arrival_date': arrivalField,
        'departure_date': departureField,
        'reserved_rooms': form.querySelector('[name="reserved_rooms"]')
      };
      Object.keys(map).forEach(function (key) {
        var field = map[key];
        if (!field) return;
        var value = payload[key];
        if ((field.getAttribute('data-date-role') || '') !== '') {
          if (field._flatpickr && value) {
            try { field._flatpickr.setDate(String(value), true, 'Y-m-d'); } catch (err) { field.value = value || ''; }
          } else {
            field.value = value || '';
          }
        } else {
          field.value = value == null ? '' : String(value);
        }
      });
      if (hiddenPrenotazioneId) hiddenPrenotazioneId.value = payload.prenotazione_id || '';
      if (hiddenRecordId) hiddenRecordId.value = payload.linked_record_id || '';
    }

    function loadPayload(payload) {
      payload = payload || {};
      clearClientErrors();
      clearRepeater();
      fillTopLevel(payload);

      var guests = Array.isArray(payload.guests) ? payload.guests.slice() : [];
      if (!guests.length) guests = [{}];
      setLeaderValues(guests[0] || {});
      for (var i = 1; i < guests.length; i += 1) {
        addGuestCard(guests[i]);
      }
      updateGroupState();
      refreshDerivedAlloggiatiTypes(form, recordType ? recordType.value : 'single');
      refreshStructureLabels(form, recordType ? recordType.value : 'single');
      updateProvinceFilteredPlaces(form);
      setModalState(true);
    }

    triggers.forEach(function (button) {
      button.addEventListener('click', function (event) {
        event.preventDefault();
        var raw = button.getAttribute('data-booking-payload');
        if (!raw) return;
        try {
          loadPayload(JSON.parse(raw));
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

    if (addButton) addButton.addEventListener('click', function () { addGuestCard({}); });
    if (recordType) recordType.addEventListener('change', updateGroupState);

    bindProvincePlaceDependencies(form, form);

    initDates(form);
    installLiveValidation(form);
    bindRemoveButtons();
    updateGroupState();
    updateProvinceFilteredPlaces(form);

    if (modal.classList.contains('is-open') || !modal.hidden) {
      initDates(form);
      updateProvinceFilteredPlaces(form);
      setModalState(true);
    }
  }

  try { initFormPanel(); } catch (err) { console.error('Form panel init failed', err); }
  try { initEditableRows(); } catch (err) { console.error('Editable rows init failed', err); }
  try { initConfirmations(); } catch (err) { console.error('Confirmations init failed', err); }
  try { initDialogModals(); } catch (err) { console.error('Dialog modals init failed', err); }
  try { initMonthPickerAutoSubmit(); } catch (err) { console.error('Month picker init failed', err); }
  try { initMonthRangeConfigurator(); } catch (err) { console.error('Month configurator init failed', err); }
  try { initCarousel(); } catch (err) { console.error('Carousel init failed', err); }
  try { initAlloggiatiModal(); } catch (err) { console.error('Alloggiati modal init failed', err); }
  try { initBookingSyncModal(); } catch (err) { console.error('Booking sync modal init failed', err); }
  try { triggerPendingDownload(); } catch (err) { console.error('Pending download init failed', err); }
});


