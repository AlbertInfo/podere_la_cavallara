document.addEventListener('DOMContentLoaded', () => {
  const form = document.getElementById('anagraficaForm');
  if (!form) return;

  const recordType = document.getElementById('recordType');
  const expectedGuests = document.getElementById('expectedGuests');
  const repeater = document.getElementById('guestRepeater');
  const addButton = document.getElementById('addGuestButton');
  const template = document.getElementById('guestTemplate');
  const endpoint = document.getElementById('documentExtractEndpoint')?.value || '';
  const csrfToken = form.querySelector('input[name="_csrf"]')?.value || '';
  const arrivalField = form.querySelector('[data-date-role="arrival"]');
  const departureField = form.querySelector('[data-date-role="departure"]');

  let cloneIndex = 1;

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
  const expiryFields = scope.querySelectorAll('[data-date-role="document-expiry"]');

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

  function setStatus(panel, message, tone = 'neutral') {
    const statusNode = panel.querySelector('[data-doc-status]');
    if (!statusNode) return;
    statusNode.textContent = message;
    statusNode.dataset.tone = tone;
  }

  function renderPreview(panel) {
    const preview = panel.querySelector('[data-doc-preview]');
    if (!preview) return;

    const front = panel.querySelector('[data-doc-file="front"]')?.files?.[0] || null;
    const back = panel.querySelector('[data-doc-file="back"]')?.files?.[0] || null;
    const parts = [];

    if (front) {
      parts.push(`<span class="doc-file-chip">Fronte: ${front.name}</span>`);
    }

    if (back) {
      parts.push(`<span class="doc-file-chip">Retro: ${back.name}</span>`);
    }

    preview.innerHTML = parts.join('');
  }

  function setFieldValue(field, value) {
    if (!field || value === null || value === undefined || value === '') return;

    if (field._flatpickr) {
      field._flatpickr.setDate(value, true, 'Y-m-d');
      return;
    }

    field.value = value;
  }

  function applyExtraction(card, payload) {
    const fields = payload.fields || {};
    const applied = [];

    Object.entries(fields).forEach(([key, meta]) => {
      const value = typeof meta === 'object' && meta !== null ? meta.value : meta;
      if (!value) return;

      const field = card.querySelector(`[data-field="${key}"]`);
      if (!field) return;

      setFieldValue(field, value);
      const badge = typeof meta === 'object' && meta !== null ? `${meta.source || 'ocr'} · ${(meta.confidence ?? 0).toFixed ? Math.round((meta.confidence ?? 0) * 100) : meta.confidence}%` : 'ocr';
      field.dataset.extracted = badge;
      applied.push({ key, value, badge });
    });

    const box = card.querySelector('[data-doc-result]');
    if (!box) return;

    const warnings = Array.isArray(payload.warnings) ? payload.warnings : [];
    const listItems = applied.map((item) => `<li><strong>${labelForField(item.key)}:</strong> ${escapeHtml(item.value)} <span class="doc-result-pill">${escapeHtml(item.badge)}</span></li>`).join('');
    const warningItems = warnings.map((warning) => `<li>${escapeHtml(warning)}</li>`).join('');

    box.hidden = false;
    box.innerHTML = `
      <div class="doc-result-box__section">
        <strong>Campi precompilati</strong>
        ${listItems ? `<ul>${listItems}</ul>` : '<p>Nessun campo compilato automaticamente.</p>'}
      </div>
      ${warningItems ? `<div class="doc-result-box__section"><strong>Note</strong><ul>${warningItems}</ul></div>` : ''}
    `;
  }

  function labelForField(key) {
    const map = {
      first_name: 'Nome',
      last_name: 'Cognome',
      birth_date: 'Data di nascita',
      gender: 'Sesso',
      citizenship_label: 'Cittadinanza',
      document_number: 'Numero documento',
      document_expiry_date: 'Scadenza documento',
      document_issue_date: 'Data documento',
      document_issue_place: 'Luogo di emissione',
      document_type: 'Tipologia documento',
    };
    return map[key] || key;
  }

  function escapeHtml(value) {
    return String(value)
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;');
  }

  async function handleDocumentExtraction(card) {
    if (!endpoint) return;

    const panel = card.querySelector('[data-doc-scan]');
    const front = panel.querySelector('[data-doc-file="front"]')?.files?.[0] || null;
    const back = panel.querySelector('[data-doc-file="back"]')?.files?.[0] || null;
    const actionButton = panel.querySelector('[data-doc-extract]');

    if (!front && !back) {
      setStatus(panel, 'Carica almeno una foto del documento.', 'error');
      return;
    }

    const formData = new FormData();
    formData.append('_csrf', csrfToken);
    formData.append('guest_slot', card.dataset.guestIndex || '0');
    if (front) formData.append('document_front', front);
    if (back) formData.append('document_back', back);

    actionButton.disabled = true;
    setStatus(panel, 'Analisi del documento in corso…', 'loading');

    try {
      const response = await fetch(endpoint, {
        method: 'POST',
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          'Accept': 'application/json',
        },
        body: formData,
      });

      const payload = await response.json();
      if (!response.ok || !payload.success) {
        throw new Error(payload.message || 'Analisi non riuscita.');
      }

      applyExtraction(card, payload);
      const filledCount = Object.keys(payload.fields || {}).length;
      setStatus(panel, filledCount > 0 ? `Analisi completata: ${filledCount} campi proposti.` : 'Analisi completata senza campi utili.', filledCount > 0 ? 'success' : 'warning');
    } catch (error) {
      setStatus(panel, error.message || 'Analisi non riuscita.', 'error');
    } finally {
      actionButton.disabled = false;
    }
  }

  function initDocScan(scope = document) {
    scope.querySelectorAll('[data-doc-scan]').forEach((panel) => {
      if (panel.dataset.bound === '1') return;
      panel.dataset.bound = '1';

      panel.querySelectorAll('[data-doc-file]').forEach((input) => {
        input.addEventListener('change', () => {
          renderPreview(panel);
          setStatus(panel, 'File pronti per l’analisi.', 'neutral');
        });
      });

      const trigger = panel.querySelector('[data-doc-extract]');
      const card = panel.closest('[data-guest-card]');
      if (trigger && card) {
        trigger.addEventListener('click', () => handleDocumentExtraction(card));
      }
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
      return;
    }

    if (Number(expectedGuests.value || 0) < 2) {
      expectedGuests.value = 2;
    }
  }

  function addGuestCard() {
    const fragment = template.content.cloneNode(true);
    const card = fragment.querySelector('[data-guest-card]');
    card.dataset.guestIndex = String(cloneIndex);

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
    initDocScan(card);
    cloneIndex += 1;
    expectedGuests.value = 1 + repeater.querySelectorAll('[data-guest-card]').length;
  }

  initDates(form);
  initDocScan(form);
  recordType.addEventListener('change', updateGroupState);
  addButton.addEventListener('click', addGuestCard);
  updateGroupState();
});
