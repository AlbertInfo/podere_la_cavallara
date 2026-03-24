/* <![CDATA[ */
$(document).ready(function () {
    "use strict";

    function getHomeUrl() {
        var path = window.location.pathname || '';
        var lastSlash = path.lastIndexOf('/');
        var basePath = lastSlash !== -1 ? path.substring(0, lastSlash + 1) : '';
        return basePath + 'index.html';
    }

    function getSuccessModalElements() {
        return {
            modalEl: document.getElementById('formSuccessModal'),
            titleEl: document.getElementById('formSuccessModalTitle'),
            textEl: document.getElementById('formSuccessModalText')
        };
    }

    function openSuccessModal(title, text) {
        var els = getSuccessModalElements();
        var modalEl = els.modalEl;
        var titleEl = els.titleEl;
        var textEl = els.textEl;

        if (!modalEl || typeof bootstrap === 'undefined' || !bootstrap.Modal) {
            return false;
        }

        if (titleEl) titleEl.textContent = title;
        if (textEl) textEl.textContent = text;

        var modal = bootstrap.Modal.getOrCreateInstance(modalEl, {
            backdrop: true,
            keyboard: true,
            focus: true
        });

        modal.show();
        return true;
    }

    function bindSuccessModalRedirect() {
        var els = getSuccessModalElements();
        var modalEl = els.modalEl;

        if (!modalEl || typeof bootstrap === 'undefined' || !bootstrap.Modal) {
            return;
        }

        $(modalEl).off('hidden.bs.modal.formredirect');
        $(modalEl).on('hidden.bs.modal.formredirect', function () {
            window.location.href = getHomeUrl();
        });
    }

    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function showInlineSuccess(messageSelector, title, text) {
        var html =
            '<div class="form-success-fallback">' +
                '<div class="form-success-fallback-icon">✓</div>' +
                '<h4>' + escapeHtml(title) + '</h4>' +
                '<p>' + escapeHtml(text) + '</p>' +
            '</div>';

        $(messageSelector).html(html).slideDown('slow');
    }

    function parseSuccessResponse(data) {
        var wrapper = document.createElement('div');
        wrapper.innerHTML = data;

        var successNode = wrapper.querySelector('#success_page');
        if (!successNode) return null;

        return {
            title: successNode.getAttribute('data-title') || '',
            text: successNode.getAttribute('data-text') || ''
        };
    }

    function showServerMessage(messageSelector, data) {
        $(messageSelector).html(data).slideDown('slow', function () {
            var target = document.querySelector(messageSelector);
            if (target) {
                var top = target.getBoundingClientRect().top + window.pageYOffset - 100;
                window.scrollTo({
                    top: top,
                    behavior: 'smooth'
                });
            }
        });
    }

    function beforeSubmit(messageSelector, submitSelector) {
        $(messageSelector).stop(true, true).slideUp(200, function () {
            $(this).hide().html('');
        });

        $(submitSelector).attr('disabled', 'disabled');
    }

    function afterFail(messageSelector, submitSelector, errorText) {
        $(submitSelector).removeAttr('disabled');
        $(messageSelector)
            .html('<div class="error_message">' + errorText + '</div>')
            .slideDown('slow');
    }

    function handleSuccessResponse(data, options) {
        var parsed = parseSuccessResponse(data);

        $(options.submitSelector).removeAttr('disabled');

        if (parsed) {
            var title = parsed.title || options.fallbackTitle;
            var text = parsed.text || options.fallbackText;

            var modalOpened = openSuccessModal(title, text);

            if (modalOpened) {
                $(options.formSelector).stop(true, true).slideUp('slow');
                $(options.messageSelector).hide().html('');
            } else {
                $(options.formSelector).stop(true, true).slideUp('slow', function () {
                    showInlineSuccess(options.messageSelector, title, text);
                    window.location.href = getHomeUrl();
                });
            }

            return;
        }

        showServerMessage(options.messageSelector, data);
    }

    function submitAjaxForm(config) {
        var $form = $(config.formSelector);
        if (!$form.length) return;

        $form.on('submit', function (e) {
            e.preventDefault();

            var action = $form.attr('action');
            if (!action) {
                afterFail(
                    config.messageSelector,
                    config.submitSelector,
                    'Azione del form non trovata. Controlla l’attributo action.'
                );
                return;
            }

            beforeSubmit(config.messageSelector, config.submitSelector);

            $.post(action, config.getData())
                .done(function (data) {
                    handleSuccessResponse(data, config);
                })
                .fail(function () {
                    afterFail(config.messageSelector, config.submitSelector, config.errorText);
                });
        });
    }

    bindSuccessModalRedirect();

    submitAjaxForm({
        formSelector: '#contactform',
        messageSelector: '#message-contact',
        submitSelector: '#submit-contact',
        fallbackTitle: 'Messaggio inviato correttamente',
        fallbackText: 'Grazie per averci contattato. Ti risponderemo al più presto.',
        errorText: 'Si è verificato un errore durante l’invio del messaggio. Riprova.',
        getData: function () {
            return {
                name_contact: $('#name_contact').val(),
                lastname_contact: $('#lastname_contact').val(),
                email_contact: $('#email_contact').val(),
                phone_contact: $('#phone_contact').val(),
                message_contact: $('#message_contact').val(),
                verify_contact: $('#verify_contact').val()
            };
        }
    });

    submitAjaxForm({
        formSelector: '#bookingform',
        messageSelector: '#message-booking',
        submitSelector: '#submit-booking',
        fallbackTitle: 'Richiesta inviata correttamente',
        fallbackText: 'Abbiamo ricevuto la tua richiesta di prenotazione. Ti risponderemo al più presto con tutti i dettagli.',
        errorText: 'Si è verificato un errore durante l’invio della richiesta. Riprova.',
        getData: function () {
            return {
                date_booking: $('#date_booking').val(),
                rooms_booking: $('#rooms_booking').val(),
                adults_booking: $('#adults_booking').val(),
                childs_booking: $('#childs_booking').val(),
                name_booking: $('#name_booking').val(),
                email_booking: $('#email_booking').val(),
                verify_booking: $('#verify_booking').val()
            };
        }
    });

    submitAjaxForm({
        formSelector: '#newsletter_form',
        messageSelector: '#message-newsletter',
        submitSelector: '#submit-newsletter',
        fallbackTitle: 'Iscrizione completata',
        fallbackText: 'Grazie, la tua iscrizione alla newsletter è stata registrata correttamente.',
        errorText: 'Si è verificato un errore. Riprova tra qualche istante.',
        getData: function () {
            return {
                email_newsletter: $('#email_newsletter').val()
            };
        }
    });
});
/* ]]> */