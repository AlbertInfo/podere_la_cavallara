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
  var comuniOptionsByProvince = parseJsonScript('anagraficaComuniByProvinceOptions', {});
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

    var rawValue = String(value || '').trim().toUpperCase();
    if (rawValue && Object.prototype.hasOwnProperty.call(comuniOptionsByProvince, rawValue)) {
      return rawValue;
    }

    if (Object.prototype.hasOwnProperty.call(provinceMap, value)) {
      return provinceMap[value];
    }

    var keys = Object.keys(provinceMap);
    for (var i = 0; i < keys.length; i += 1) {
      if (normalizeValue(keys[i]) === normalized) {
        return provinceMap[keys[i]];
      }
    }
    return '';
  }

  function fillSelectOptions(select, options, placeholder, selectedValue) {
    if (!select) return;
    var normalizedSelected = String(selectedValue || '').trim();
    select.innerHTML = '';
    var firstOption = document.createElement('option');
    firstOption.value = '';
    firstOption.textContent = placeholder || 'Seleziona';
    select.appendChild(firstOption);
    (options || []).forEach(function (item) {
      var option = document.createElement('option');
      option.value = String(item.code || '');
      option.textContent = String(item.label || item.description || item.code || '');
      if (normalizedSelected !== '' && option.value === normalizedSelected) {
        option.selected = true;
      }
      select.appendChild(option);
    });
  }

  function guestScopes(scope) {
    var root = scope || document;
    var scopes = [];
    if (root && root.nodeType === 1 && root.hasAttribute('data-guest-scope')) {
      scopes.push(root);
    }
    return scopes.concat($all('[data-guest-scope]', root));
  }

  function updateProvinceFilteredPlaces(scope) {
    guestScopes(scope).forEach(function (card) {
      var birthState = $('[data-state-role="birth"]', card);
      var birthProvince = $('[data-province-role="birth"]', card);
      var birthPlace = $('[data-place-role="birth"]', card);

      var residenceState = $('[data-state-role="residence"]', card);
      var residenceProvince = $('[data-province-role="residence"]', card);
      var residenceSelect = $('[data-place-role="residence-select"]', card);
      var residenceText = $('[data-place-role="residence-text"]', card);
      var residenceLabel = $('[data-residence-place-label]', card);

      if (birthState && birthProvince && birthPlace) {
        var birthIsItaly = birthState.value === '100000100';
        var birthCode = resolveProvinceCode(birthProvince.value);
        var selectedBirth = String(birthPlace.value || '').trim();
        birthProvince.disabled = !birthIsItaly;
        birthPlace.disabled = !birthIsItaly;
        birthProvince.required = birthIsItaly;
        birthPlace.required = birthIsItaly;
        fillSelectOptions(
          birthPlace,
          birthIsItaly && birthCode ? (comuniOptionsByProvince[birthCode] || []) : [],
          birthIsItaly ? (birthCode ? 'Seleziona comune di nascita' : 'Seleziona prima la provincia') : 'Non richiesto per estero',
          birthIsItaly ? selectedBirth : ''
        );
        if (!birthIsItaly) {
          birthPlace.value = '';
        }
      }

      if (residenceState && residenceProvince && residenceSelect && residenceText) {
        var residenceIsItaly = residenceState.value === '100000100';
        var residenceCode = resolveProvinceCode(residenceProvince.value);
        var selectedResidenceCode = String(residenceSelect.value || '').trim();
        var selectedResidenceText = String(residenceText.value || '').trim();

        residenceProvince.disabled = !residenceIsItaly;
        residenceProvince.required = residenceIsItaly;

        if (residenceLabel) {
          residenceLabel.textContent = residenceIsItaly ? 'Comune residenza' : 'Località / NUTS residenza';
        }

        fillSelectOptions(
          residenceSelect,
          residenceIsItaly && residenceCode ? (comuniOptionsByProvince[residenceCode] || []) : [],
          residenceIsItaly ? (residenceCode ? 'Seleziona comune di residenza' : 'Seleziona prima la provincia') : 'Seleziona comune di residenza',
          residenceIsItaly ? selectedResidenceCode : ''
        );

        if (residenceIsItaly) {
          residenceSelect.hidden = false;
          residenceSelect.disabled = false;
          residenceSelect.required = true;
          residenceText.hidden = true;
          residenceText.disabled = true;
          residenceText.required = false;
          if (residenceText.name) {
            residenceSelect.name = residenceText.name;
          }
          residenceText.name = '';
        } else {
          residenceSelect.hidden = true;
          residenceSelect.disabled = true;
          residenceSelect.required = false;
          residenceText.hidden = false;
          residenceText.disabled = false;
          residenceText.required = true;
          if (residenceSelect.name) {
            residenceText.name = residenceSelect.name;
          }
          residenceSelect.name = '';
          residenceText.placeholder = 'Località o codice NUTS';
        }
      }

      refreshFieldVisualStates(card);
    });
  }

  function setFieldVisualState(field) {
    if (!field) return;
    var label = field.closest('.anagrafica-field');
    if (!label) return;

    var candidates = $all('input, select, textarea', label).filter(function (candidate) {
      return !(candidate.disabled || candidate.type === 'hidden' || candidate.hidden || candidate.readOnly);
    });

    if (!candidates.length) {
      label.classList.remove('is-valid');
      return;
    }

    if (label.classList.contains('is-invalid')) {
      label.classList.remove('is-valid');
      return;
    }

    var activeField = candidates.find(function (candidate) {
      return candidate.offsetParent !== null || candidate === document.activeElement;
    }) || candidates[0];

    var value = String(activeField.value || '').trim();
    if (value === '') {
      label.classList.remove('is-valid');
      return;
    }

    label.classList.toggle('is-valid', activeField.checkValidity());
  }

  function refreshFieldVisualStates(scope) {
    var labels = $all('.anagrafica-field', scope || document);
    if (labels.length) {
      labels.forEach(function (label) {
        var field = $('input, select, textarea', label);
        if (field) setFieldVisualState(field);
      });
      return;
    }

    $all('input, select, textarea', scope || document).forEach(function (field) {
      setFieldVisualState(field);
    });
  }

  function addInvalidState(field, message) {
    if (!field) return;
    var label = field.closest('.anagrafica-field');
    if (label) {
      label.classList.add('is-invalid');
      label.classList.remove('is-valid');
    }
    field.setCustomValidity(message || 'Campo obbligatorio');
  }

  function clearInvalidState(field) {
    if (!field) return;
    var label = field.closest('.anagrafica-field');
    if (label) label.classList.remove('is-invalid');
    field.setCustomValidity('');
    setFieldVisualState(field);
  }

  function installLiveValidation(scope) {
    $all('input, select, textarea', scope || document).forEach(function (field) {
      field.addEventListener('input', function () { clearInvalidState(field); });
      field.addEventListener('change', function () { clearInvalidState(field); });
      field.addEventListener('blur', function () { setFieldVisualState(field); });
      setFieldVisualState(field);
    });
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
    $all('[data-state-role], [data-province-role]', scope || document).forEach(function (field) {
      if (field.dataset.depBound === '1') return;
      field.dataset.depBound = '1';
      field.addEventListener('change', function () { updateProvinceFilteredPlaces(rootForm || document); });
      field.addEventListener('input', function () { updateProvinceFilteredPlaces(rootForm || document); });
    });

    $all('[data-place-role="residence-select"], [data-place-role="residence-text"]', scope || document).forEach(function (field) {
      if (field.dataset.depBound === '1') return;
      field.dataset.depBound = '1';
      var refresh = function () {
        var card = field.closest('[data-guest-scope]');
        if (card) {
          refreshFieldVisualStates(card);
        }
      };
      field.addEventListener('change', refresh);
      field.addEventListener('input', refresh);
      field.addEventListener('blur', refresh);
    });
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

  function formatOcrDateForDisplay(value) {
    if (!value) return '';
    var raw = String(value || '').trim();
    if (!/^\d{4}-\d{2}-\d{2}$/.test(raw)) return raw;
    return raw.slice(8, 10) + '/' + raw.slice(5, 7) + '/' + raw.slice(0, 4);
  }

  function cardGuestTitle(card) {
    if (!card) return 'ospite selezionato';
    var firstName = $('[data-name="first_name"]', card);
    var lastName = $('[data-name="last_name"]', card);
    var role = $('[data-guest-role-label]', card);
    var number = $('[data-guest-number]', card);
    var name = [firstName && firstName.value ? firstName.value.trim() : '', lastName && lastName.value ? lastName.value.trim() : '']
      .filter(Boolean)
      .join(' ')
      .trim();
    if (name) return name;
    if (role && number && number.textContent) {
      return (role.textContent || 'Ospite').trim() + ' ' + number.textContent.trim();
    }
    if (role && role.textContent) return role.textContent.trim();
    return 'ospite selezionato';
  }

  function setCardOcrFeedback(card, message, variant) {
    if (!card) return;
    var box = $('[data-document-ocr-status]', card);
    if (!box) return;
    if (!message) {
      box.hidden = true;
      box.className = 'anagrafica-ocr-feedback';
      box.textContent = '';
      return;
    }
    box.hidden = false;
    box.className = 'anagrafica-ocr-feedback is-' + (variant || 'info');
    box.textContent = message;
  }

  function applyGuestPayloadToCard(card, payload) {
    payload = payload || {};
    if (!card) return;

    $all('[data-name]', card).forEach(function (field) {
      var key = field.getAttribute('data-name');
      if (!key) return;
      var value = payload[key];
      if (value == null || String(value).trim() === '') return;

      var placeRole = field.getAttribute('data-place-role') || '';
      if (placeRole === 'residence-select' || placeRole === 'residence-text' || placeRole === 'birth') {
        return;
      }

      if ((field.getAttribute('data-date-role') || '') !== '') {
        if (field._flatpickr) {
          try {
            field._flatpickr.setDate(String(value), true, 'Y-m-d');
          } catch (err) {
            field.value = formatOcrDateForDisplay(value);
          }
        } else {
          field.value = formatOcrDateForDisplay(value);
        }
        return;
      }

      if (field.tagName === 'SELECT') {
        field.value = String(value);
      } else {
        field.value = String(value);
      }
    });

    updateProvinceFilteredPlaces(card);

    var birthPlace = $('[data-place-role="birth"]', card);
    var birthValue = payload.birth_city_code || payload.birth_place_code || payload.birth_place_label || '';
    if (birthPlace && String(birthValue || '').trim() !== '') {
      birthPlace.value = String(birthValue);
    }

    var residenceState = $('[data-state-role="residence"]', card);
    var residenceSelect = $('[data-place-role="residence-select"]', card);
    var residenceText = $('[data-place-role="residence-text"]', card);
    var residenceValue = payload.residence_place_code || payload.residence_place_label || payload.residence_place || '';
    if (residenceState && residenceState.value === '100000100') {
      if (residenceSelect && String(residenceValue || '').trim() !== '') {
        residenceSelect.value = String(residenceValue);
      }
    } else if (residenceText && String(residenceValue || '').trim() !== '') {
      residenceText.value = String(residenceValue);
    }

    updateProvinceFilteredPlaces(card);
    refreshFieldVisualStates(card);
  }

  function initDocumentOcr() {
    var config = parseJsonScript('documentOcrConfig', null);
    if (!config || !config.enabled) return;

    var modal = document.getElementById('documentOcrModal');
    var form = document.getElementById('documentOcrForm');
    if (!modal || !form) return;

    var frontInput = document.getElementById('documentOcrFront');
    var backInput = document.getElementById('documentOcrBack');
    var frontName = $('[data-document-ocr-front-name]', modal);
    var backName = $('[data-document-ocr-back-name]', modal);
    var statusPanel = $('[data-document-ocr-status-panel]', modal);
    var resultPanel = $('[data-document-ocr-result]', modal);
    var summaryPanel = $('[data-document-ocr-summary]', modal);
    var warningsPanel = $('[data-document-ocr-warnings]', modal);
    var submitButton = $('[data-document-ocr-submit]', modal);
    var applyButton = $('[data-document-ocr-apply]', modal);
    var targetTitle = $('[data-document-ocr-target-title]', modal);
    var targetSubtitle = $('[data-document-ocr-target-subtitle]', modal);
    var hiddenTargetForm = $('[name="target_form_id"]', form);
    var hiddenTargetGuest = $('[name="target_guest_index"]', form);
    var activeCard = null;
    var activePayload = null;

    function updateFilename(input, node, emptyText) {
      if (!node) return;
      if (!input || !input.files || !input.files.length) {
        node.textContent = emptyText;
        return;
      }
      node.textContent = input.files[0].name;
    }

    function showStatus(message, variant) {
      if (!statusPanel) return;
      if (!message) {
        statusPanel.hidden = true;
        statusPanel.className = 'document-ocr-status';
        statusPanel.textContent = '';
        return;
      }
      statusPanel.hidden = false;
      statusPanel.className = 'document-ocr-status is-' + (variant || 'info');
      statusPanel.textContent = message;
    }

    function resetModal() {
      form.reset();
      updateFilename(frontInput, frontName, 'Nessun file selezionato');
      updateFilename(backInput, backName, 'Nessun file selezionato');
      showStatus('', 'info');
      if (resultPanel) resultPanel.hidden = true;
      if (summaryPanel) summaryPanel.innerHTML = '';
      if (warningsPanel) {
        warningsPanel.hidden = true;
        warningsPanel.innerHTML = '';
      }
      if (applyButton) applyButton.hidden = true;
      if (submitButton) {
        submitButton.disabled = false;
        submitButton.textContent = 'Analizza documento';
      }
      activePayload = null;
      activeCard = null;
    }

    function closeModal() {
      modal.hidden = true;
      modal.classList.remove('is-open');
      document.body.classList.remove('is-modal-open');
      resetModal();
    }

    function openModalForCard(card) {
      activeCard = card;
      activePayload = null;
      updateFilename(frontInput, frontName, 'Nessun file selezionato');
      updateFilename(backInput, backName, 'Nessun file selezionato');
      if (hiddenTargetForm) hiddenTargetForm.value = (card.closest('form') || {}).id || '';
      if (hiddenTargetGuest) hiddenTargetGuest.value = card.getAttribute('data-guest-index') || '0';
      if (targetTitle) targetTitle.textContent = 'Scansione documento · ' + cardGuestTitle(card);
      if (targetSubtitle) targetSubtitle.textContent = 'Il JSON OCR verrà applicato solo alla card selezionata.';
      showStatus('', 'info');
      if (resultPanel) resultPanel.hidden = true;
      if (applyButton) applyButton.hidden = true;
      modal.hidden = false;
      modal.classList.add('is-open');
      document.body.classList.add('is-modal-open');
    }

    function renderSummary(result) {
      if (!summaryPanel || !resultPanel) return;
      var display = result.display_payload || {};
      var rows = Object.keys(display).map(function (label) {
        return '<div><span>' + label + '</span><strong>' + String(display[label]) + '</strong></div>';
      });
      if (!rows.length) {
        rows.push('<div><span>Esito</span><strong>Nessun campo riconosciuto con sicurezza</strong></div>');
      }
      summaryPanel.innerHTML = rows.join('');
      resultPanel.hidden = false;

      var warnings = Array.isArray(result.warnings) ? result.warnings.filter(Boolean) : [];
      if (warningsPanel) {
        if (warnings.length) {
          warningsPanel.hidden = false;
          warningsPanel.innerHTML = '<strong>Controlli consigliati</strong><ul>' + warnings.map(function (msg) {
            return '<li>' + String(msg) + '</li>';
          }).join('') + '</ul>';
        } else {
          warningsPanel.hidden = true;
          warningsPanel.innerHTML = '';
        }
      }
    }

    frontInput.addEventListener('change', function () {
      updateFilename(frontInput, frontName, 'Nessun file selezionato');
    });
    backInput.addEventListener('change', function () {
      updateFilename(backInput, backName, 'Nessun file selezionato');
    });

    document.addEventListener('click', function (event) {
      var trigger = event.target.closest('[data-document-ocr-trigger]');
      if (trigger) {
        if (trigger.disabled || trigger.classList.contains('is-disabled')) return;
        event.preventDefault();
        var card = trigger.closest('[data-guest-scope]');
        if (!card) return;
        openModalForCard(card);
        return;
      }

      var closeTrigger = event.target.closest('[data-document-ocr-close]');
      if (closeTrigger) {
        event.preventDefault();
        closeModal();
        return;
      }
    });

    modal.addEventListener('click', function (event) {
      if (event.target === modal || event.target.classList.contains('anagrafica-modal__backdrop')) {
        closeModal();
      }
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && !modal.hidden) {
        closeModal();
      }
    });

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      if (!activeCard) return;
      if (!frontInput.files || !frontInput.files.length) {
        showStatus('Carica il fronte del documento prima di avviare l\'OCR.', 'error');
        return;
      }

      if (submitButton) {
        submitButton.disabled = true;
        submitButton.textContent = 'Analisi in corso…';
      }
      if (applyButton) applyButton.hidden = true;
      showStatus('Invio immagini al processore OCR e costruzione del JSON in corso…', 'loading');

      var formData = new FormData(form);
      fetch(config.processUrl, {
        method: 'POST',
        body: formData,
        headers: {
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        }
      })
        .then(function (response) {
          return response.json().catch(function () {
            return { success: false, message: 'Risposta OCR non valida.' };
          }).then(function (data) {
            data._httpStatus = response.status;
            return data;
          });
        })
        .then(function (data) {
          if (!data || !data.success) {
            throw new Error((data && data.message) ? data.message : 'Analisi OCR non riuscita.');
          }
          activePayload = data.result || {};
          renderSummary(activePayload);
          showStatus(data.message || 'Documento analizzato correttamente.', 'success');
          if (applyButton) applyButton.hidden = false;
        })
        .catch(function (error) {
          activePayload = null;
          showStatus(error && error.message ? error.message : 'Analisi OCR non riuscita.', 'error');
        })
        .finally(function () {
          if (submitButton) {
            submitButton.disabled = false;
            submitButton.textContent = 'Analizza documento';
          }
        });
    });

    if (applyButton) {
      applyButton.addEventListener('click', function () {
        if (!activeCard || !activePayload || !activePayload.form_payload) return;
        applyGuestPayloadToCard(activeCard, activePayload.form_payload);
        setCardOcrFeedback(activeCard, 'Dati OCR applicati. Controlla i campi evidenziati prima del salvataggio.', 'success');
        closeModal();
      });
    }
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
      $all('.anagrafica-field.is-invalid', scope || form).forEach(function (field) {
        field.classList.remove('is-invalid');
      });
      $all('input, select, textarea', scope || form).forEach(function (field) {
        field.setCustomValidity('');
      });
      refreshFieldVisualStates(scope || form);
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
      refreshFieldVisualStates(form);
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

    var prevMonthUrl = carousel.getAttribute('data-prev-month-url') || '';
    var nextMonthUrl = carousel.getAttribute('data-next-month-url') || '';
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

    function boundaryState() {
      var max = Math.max(0, viewport.scrollWidth - viewport.clientWidth);
      return {
        atStart: viewport.scrollLeft <= 2,
        atEnd: viewport.scrollLeft >= (max - 2),
        max: max
      };
    }

    function setNavState(button, isBoundary, boundaryUrl, baseLabel, boundaryLabel) {
      if (!button) return;
      button.disabled = false;
      button.classList.toggle('is-boundary', !!isBoundary && !!boundaryUrl);
      button.classList.toggle('is-inactive', !!isBoundary && !boundaryUrl);
      if (isBoundary && !boundaryUrl) {
        button.disabled = true;
      }
      button.setAttribute('title', isBoundary && boundaryUrl ? boundaryLabel : baseLabel);
      button.setAttribute('aria-label', isBoundary && boundaryUrl ? boundaryLabel : baseLabel);
    }

    function updateControls() {
      var state = boundaryState();
      setNavState(prevButton, state.atStart, prevMonthUrl, 'Scorri ai giorni precedenti', 'Vai al mese precedente');
      setNavState(nextButton, state.atEnd, nextMonthUrl, 'Scorri ai giorni successivi', 'Vai al mese successivo');
    }

    function scrollStep(direction) {
      var state = boundaryState();
      if (direction < 0 && state.atStart && prevMonthUrl) {
        window.location.href = prevMonthUrl;
        return;
      }
      if (direction > 0 && state.atEnd && nextMonthUrl) {
        window.location.href = nextMonthUrl;
        return;
      }
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
        var placeRole = field.getAttribute('data-place-role') || '';
        if (placeRole === 'residence-select' || placeRole === 'residence-text') {
          return;
        }
        var value = guest[key];
        if (field.tagName === 'SELECT') {
          field.value = value == null ? '' : String(value);
        } else if ((field.getAttribute('data-date-role') || '') !== '') {
          if (field._flatpickr && value) {
            try { field._flatpickr.setDate(String(value), true, 'Y-m-d'); } catch (err) { field.value = value || ''; }
          } else {
            field.value = value || '';
          }
        } else {
          field.value = value == null ? '' : String(value);
        }
      });
      updateProvinceFilteredPlaces(card);
      var birthPlace = $('[data-place-role="birth"]', card);
      var birthValue = '';
      if (guest.birth_city_code != null && String(guest.birth_city_code).trim() !== '') {
        birthValue = String(guest.birth_city_code || '');
      } else if (guest.birth_place_code != null && String(guest.birth_place_code).trim() !== '') {
        birthValue = String(guest.birth_place_code || '');
      } else if (guest.birth_place_label != null) {
        birthValue = String(guest.birth_place_label || '');
      }
      if (birthPlace) {
        birthPlace.value = birthValue;
      }
      var residenceState = $('[data-state-role="residence"]', card);
      var residenceSelect = $('[data-place-role="residence-select"]', card);
      var residenceText = $('[data-place-role="residence-text"]', card);
      var residenceValue = '';
      if (residenceState && residenceState.value === '100000100') {
        if (guest.residence_place_code != null && String(guest.residence_place_code).trim() !== '') {
          residenceValue = String(guest.residence_place_code || '');
        } else if (guest.residence_place_label != null) {
          residenceValue = String(guest.residence_place_label || '');
        }
        if (residenceSelect) {
          residenceSelect.value = residenceValue;
        }
      } else if (residenceText) {
        if (guest.residence_place_label != null && String(guest.residence_place_label).trim() !== '') {
          residenceValue = String(guest.residence_place_label || '');
        } else if (guest.residence_place != null) {
          residenceValue = String(guest.residence_place || '');
        } else if (guest.residence_place_code != null) {
          residenceValue = String(guest.residence_place_code || '');
        }
        residenceText.value = residenceValue;
      }
      refreshFieldVisualStates(card);
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
      var leaderCard = $('[data-guest-scope][data-guest-index="0"]', form);
      if (!leaderCard) return;
      setGuestValues(leaderCard, guest);
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

    function openTrigger(trigger, event) {
      if (event && trigger.hasAttribute('data-booking-row') && event.target && event.target.closest('[data-row-ignore]')) {
        return;
      }
      if (event) event.preventDefault();
      var raw = trigger.getAttribute('data-booking-payload');
      if (!raw) return;
      try {
        loadPayload(JSON.parse(raw));
      } catch (err) {
        console.error('Booking modal payload parse failed', err);
      }
    }

    triggers.forEach(function (button) {
      button.addEventListener('click', function (event) {
        openTrigger(button, event);
      });
      if (button.hasAttribute('data-booking-row')) {
        button.addEventListener('keydown', function (event) {
          if (event.key !== 'Enter' && event.key !== ' ') return;
          openTrigger(button, event);
        });
      }
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
  try { initDocumentOcr(); } catch (err) { console.error('Document OCR init failed', err); }
  try { triggerPendingDownload(); } catch (err) { console.error('Pending download init failed', err); }
});


