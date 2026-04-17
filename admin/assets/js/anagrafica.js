
document.addEventListener('DOMContentLoaded', function () {
  'use strict';

  function $(selector, scope) {
    return (scope || document).querySelector(selector);
  }

  function $all(selector, scope) {
    return Array.prototype.slice.call((scope || document).querySelectorAll(selector));
  }

  function getSubmitter(event) {
    return event.submitter || document.activeElement || null;
  }

  function safeReplaceUrl(url) {
    try {
      if (window.history && typeof window.history.replaceState === 'function') {
        window.history.replaceState({}, document.title, url);
      }
    } catch (err) {
      // no-op
    }
  }

  function setHidden(el, hidden) {
    if (!el) return;
    el.hidden = !!hidden;
    if (hidden) {
      el.classList.remove('is-open');
    } else {
      el.classList.add('is-open');
    }
  }

  function initFormPanel() {
    var formCard = document.getElementById('anagraficaFormCard');
    var form = document.getElementById('anagraficaForm');
    if (!formCard || !form) return;

    var closeButton = document.getElementById('closeAnagraficaForm');
    var openLinks = $all('[data-anagrafica-open-link]');
    var baseUrl = formCard.getAttribute('data-base-url') || window.location.pathname;
    var forceOpen = (formCard.getAttribute('data-force-open') || '0') === '1';
    var recordType = document.getElementById('recordType');
    var addButton = document.getElementById('addGuestButton');
    var repeater = document.getElementById('guestRepeater');
    var template = document.getElementById('guestTemplate');
    var expectedGuests = document.getElementById('expectedGuests');
    var arrivalField = $('[data-date-role="arrival"]', form);
    var departureField = $('[data-date-role="departure"]', form);
    var cloneIndex = repeater ? $all('[data-guest-card]', repeater).length + 1 : 1;

    function openPanel() {
      setHidden(formCard, false);
      window.requestAnimationFrame(function () {
        try {
          formCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
        } catch (err) {
          formCard.scrollIntoView(true);
        }
      });
    }

    function closePanel() {
      setHidden(formCard, true);
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

      if (role === 'birth') {
        options.maxDate = 'today';
      }

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
      scope = scope || form;
      $all('.js-date', scope).forEach(function (field) {
        createDatePicker(field, {
          onReady: [function () { syncDateConstraints(scope); }],
          onChange: [function () { syncDateConstraints(scope); }]
        });
      });
      syncDateConstraints(scope);
    }

    function refreshGuestCounters() {
      if (!repeater) return;
      $all('[data-guest-card]', repeater).forEach(function (card, index) {
        var num = $('[data-guest-number]', card);
        if (num) num.textContent = String(index + 2);
      });

      if (expectedGuests && recordType && recordType.value !== 'single') {
        expectedGuests.value = String(1 + $all('[data-guest-card]', repeater).length);
      }
    }

    function bindRemoveButtons(scope) {
      scope = scope || repeater;
      if (!scope) return;

      $all('[data-remove-guest]', scope).forEach(function (button) {
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

      if (!repeater) return;

      repeater.style.display = isGroup ? 'grid' : 'none';

      if (!isGroup) {
        $all('[data-guest-card]', repeater).forEach(function (card) { card.remove(); });
        cloneIndex = 1;
        if (expectedGuests) expectedGuests.value = '1';
      } else if (expectedGuests && parseInt(expectedGuests.value || '0', 10) < 2) {
        expectedGuests.value = String(Math.max(2, 1 + $all('[data-guest-card]', repeater).length));
      }
    }

    function addGuestCard() {
      if (!template || !repeater) return;

      var fragment = template.content.cloneNode(true);
      var card = $('[data-guest-card]', fragment);
      if (!card) return;

      var requiredFields = [
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
        'transport_type'
      ];

      $all('[data-name]', card).forEach(function (field) {
        var dataName = field.getAttribute('data-name');
        field.name = 'guests[' + cloneIndex + '][' + dataName + ']';
        if (requiredFields.indexOf(dataName) !== -1) {
          field.required = true;
        }
      });

      repeater.appendChild(card);
      bindRemoveButtons(card);
      initDates(card);
      cloneIndex += 1;
      refreshGuestCounters();
    }

    openLinks.forEach(function (link) {
      link.addEventListener('click', function (event) {
        event.preventDefault();
        openPanel();
        safeReplaceUrl(link.getAttribute('href') || baseUrl);
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

    initDates(form);
    bindRemoveButtons();
    refreshGuestCounters();
    updateGroupState();
    setHidden(formCard, !forceOpen);
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
    $all('[data-month-export-form]').forEach(function (monthForm) {
      monthForm.addEventListener('submit', function (event) {
        var ok = window.confirm("Questa azione precompila il mese come aperto per i giorni non ancora chiusi e scarica l'XML mensile ROSS1000. Continuare?");
        if (!ok) event.preventDefault();
      });
    });

    $all('[data-delete-form]').forEach(function (deleteForm) {
      deleteForm.addEventListener('submit', function (event) {
        var ok = window.confirm('Eliminare definitivamente questa anagrafica?');
        if (!ok) event.preventDefault();
      });
    });

    $all('.ross-day-settings').forEach(function (dayForm) {
      dayForm.addEventListener('submit', function (event) {
        var submitter = getSubmitter(event);
        var intent = submitter ? (submitter.value || submitter.getAttribute('value') || '') : '';
        if (intent === 'close') {
          var ok = window.confirm('Chiudere definitivamente il giorno selezionato?');
          if (!ok) event.preventDefault();
        }
      });
    });

    $all('[data-day-export-link]').forEach(function (link) {
      link.addEventListener('click', function (event) {
        if (link.classList.contains('is-disabled') || link.getAttribute('aria-disabled') === 'true') {
          event.preventDefault();
          return;
        }
        var message = link.getAttribute('data-confirm-message') || "Confermare l'esportazione del file?";
        var ok = window.confirm(message);
        if (!ok) event.preventDefault();
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

    function getStep() {
      var firstCard = $('.ross-day-card', strip);
      if (!firstCard) return Math.max(240, Math.round(viewport.clientWidth * 0.7));
      var style = window.getComputedStyle(strip);
      var gap = parseFloat(style.columnGap || style.gap || '0') || 0;
      return Math.round(firstCard.getBoundingClientRect().width + gap);
    }

    function maxScrollLeft() {
      return Math.max(0, viewport.scrollWidth - viewport.clientWidth);
    }

    function updateControls() {
      if (!prevButton || !nextButton) return;
      var left = Math.round(viewport.scrollLeft);
      var max = Math.round(maxScrollLeft());
      prevButton.disabled = left <= 2;
      nextButton.disabled = left >= (max - 2);
    }

    function scrollByStep(direction) {
      viewport.scrollBy({
        left: getStep() * direction,
        behavior: 'smooth'
      });
      window.setTimeout(updateControls, 350);
    }

    if (prevButton) {
      prevButton.addEventListener('click', function (event) {
        event.preventDefault();
        scrollByStep(-1);
      });
    }

    if (nextButton) {
      nextButton.addEventListener('click', function (event) {
        event.preventDefault();
        scrollByStep(1);
      });
    }

    viewport.addEventListener('scroll', updateControls, { passive: true });
    window.addEventListener('resize', updateControls);

    viewport.addEventListener('wheel', function (event) {
      if (Math.abs(event.deltaY) <= Math.abs(event.deltaX)) return;
      event.preventDefault();
      viewport.scrollLeft += event.deltaY;
    }, { passive: false });

    var isDown = false;
    var startX = 0;
    var startScrollLeft = 0;

    viewport.addEventListener('pointerdown', function (event) {
      if (event.pointerType === 'mouse' && event.button !== 0) return;
      isDown = true;
      startX = event.clientX;
      startScrollLeft = viewport.scrollLeft;
      carousel.classList.add('is-dragging');
      if (typeof viewport.setPointerCapture === 'function') {
        try { viewport.setPointerCapture(event.pointerId); } catch (err) {}
      }
    });

    viewport.addEventListener('pointermove', function (event) {
      if (!isDown) return;
      var delta = event.clientX - startX;
      viewport.scrollLeft = startScrollLeft - delta;
    });

    function stopDrag(event) {
      if (!isDown) return;
      isDown = false;
      carousel.classList.remove('is-dragging');
      if (event && typeof viewport.releasePointerCapture === 'function' && event.pointerId != null) {
        try { viewport.releasePointerCapture(event.pointerId); } catch (err) {}
      }
      updateControls();
    }

    viewport.addEventListener('pointerup', stopDrag);
    viewport.addEventListener('pointercancel', stopDrag);
    viewport.addEventListener('mouseleave', function () {
      if (isDown) stopDrag();
    });

    var selectedCard = $('.ross-day-card.is-selected', strip);
    if (selectedCard) {
      var targetLeft = selectedCard.offsetLeft - ((viewport.clientWidth - selectedCard.offsetWidth) / 2);
      viewport.scrollLeft = Math.max(0, targetLeft);
    }

    updateControls();
  }

  try { initFormPanel(); } catch (err) { console.error('Anagrafica form init failed:', err); }
  try { initEditableRows(); } catch (err) { console.error('Editable rows init failed:', err); }
  try { initConfirmations(); } catch (err) { console.error('Confirmations init failed:', err); }
  try { initCarousel(); } catch (err) { console.error('Carousel init failed:', err); }
});
