/* <![CDATA[ */
$(function () {

    let activeFormSelector = null;
    let activeMessageSelector = null;

    function scrollToElement(selector) {
        const target = document.querySelector(selector);
        if (!target) return;

        const offset = window.innerWidth < 768 ? 90 : 120;
        const top = target.getBoundingClientRect().top + window.pageYOffset - offset;

        window.scrollTo({
            top: top,
            behavior: 'smooth'
        });
    }

    function openSuccessModal(title, text, formSelector, messageSelector) {
        const modalEl = document.getElementById('formSuccessModal');
        const titleEl = document.getElementById('formSuccessModalTitle');
        const textEl = document.getElementById('formSuccessModalText');

        if (!modalEl) {
            console.error('Modale non trovata: #formSuccessModal');
            return false;
        }

        if (typeof bootstrap === 'undefined') {
            console.error('Bootstrap JS non caricato');
            return false;
        }

        activeFormSelector = formSelector;
        activeMessageSelector = messageSelector;

        if (titleEl) titleEl.textContent = title;
        if (textEl) textEl.textContent = text;

        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);

        modalEl.addEventListener('hidden.bs.modal', function handleHidden() {
            window.scrollTo({
                top: 0,
                behavior: 'smooth'
            });

            setTimeout(function () {
                if (activeFormSelector) {
                    $(activeFormSelector).slideDown('slow');
                }

                if (activeMessageSelector) {
                    $(activeMessageSelector).hide().html('');
                }

                activeFormSelector = null;
                activeMessageSelector = null;
            }, 350);

            modalEl.removeEventListener('hidden.bs.modal', handleHidden);
        });

        modal.show();
        return true;
    }

    function showInlineFallback(messageSelector, title, text) {
        const html = `
            <div class="form-success-fallback">
                <div class="form-success-fallback-icon">✓</div>
                <h4>${title}</h4>
                <p>${text}</p>
            </div>
        `;
        $(messageSelector).html(html).slideDown('slow', function () {
            scrollToElement(messageSelector);
        });
    }

    function handleResponse(messageSelector, submitSelector, formSelector, data, fallbackTitle, fallbackText) {
        const wrapper = document.createElement('div');
        wrapper.innerHTML = data;

        const successNode = wrapper.querySelector('#success_page');
        const isSuccess = !!successNode;

        $(submitSelector).removeAttr('disabled');

        if (isSuccess) {
            const title = successNode.dataset.title || fallbackTitle;
            const text = successNode.dataset.text || fallbackText;

            $(formSelector).slideUp('slow', function () {
                $(messageSelector).hide().html('');

                const opened = openSuccessModal(title, text, formSelector, messageSelector);
                if (!opened) {
                    showInlineFallback(messageSelector, title, text);
                }
            });

            return;
        }

        $(messageSelector).html(data).slideDown('slow', function () {
            scrollToElement(messageSelector);
        });
    }

    $('#newsletter_form').on('submit', function (e) {
        e.preventDefault();

        const action = $(this).attr('action');

        $('#message-newsletter').slideUp(200, function () {
            $('#message-newsletter').hide();
            $('#submit-newsletter').attr('disabled', 'disabled');

            $.post(action, {
                email_newsletter: $('#email_newsletter').val()
            })
            .done(function (data) {
                handleResponse(
                    '#message-newsletter',
                    '#submit-newsletter',
                    '#newsletter_form',
                    data,
                    'Iscrizione completata',
                    'Grazie, la tua iscrizione alla newsletter è stata registrata correttamente.'
                );
            })
            .fail(function () {
                $('#submit-newsletter').removeAttr('disabled');
                $('#message-newsletter')
                    .html('<div class="error_message">Si è verificato un errore. Riprova tra qualche istante.</div>')
                    .slideDown('slow', function () {
                        scrollToElement('#message-newsletter');
                    });
            });
        });
    });

    $('#bookingform').on('submit', function (e) {
        e.preventDefault();

        const action = $(this).attr('action');

        $('#message-booking').slideUp(200, function () {
            $('#message-booking').hide();
            $('#submit-booking').attr('disabled', 'disabled');

            $.post(action, {
                date_booking: $('#date_booking').val(),
                rooms_booking: $('#rooms_booking').val(),
                adults_booking: $('#adults_booking').val(),
                childs_booking: $('#childs_booking').val(),
                name_booking: $('#name_booking').val(),
                email_booking: $('#email_booking').val(),
                verify_booking: $('#verify_booking').val()
            })
            .done(function (data) {
                handleResponse(
                    '#message-booking',
                    '#submit-booking',
                    '#bookingform',
                    data,
                    'Richiesta inviata correttamente',
                    'Abbiamo ricevuto la tua richiesta di prenotazione. Ti risponderemo al più presto con tutti i dettagli.'
                );
            })
            .fail(function () {
                $('#submit-booking').removeAttr('disabled');
                $('#message-booking')
                    .html('<div class="error_message">Si è verificato un errore durante l’invio della richiesta. Riprova.</div>')
                    .slideDown('slow', function () {
                        scrollToElement('#message-booking');
                    });
            });
        });
    });

    $('#contactform').on('submit', function (e) {
        e.preventDefault();

        const action = $(this).attr('action');

        $('#message-contact').slideUp(200, function () {
            $('#message-contact').hide();
            $('#submit-contact').attr('disabled', 'disabled');

            $.post(action, {
                name_contact: $('#name_contact').val(),
                lastname_contact: $('#lastname_contact').val(),
                email_contact: $('#email_contact').val(),
                phone_contact: $('#phone_contact').val(),
                message_contact: $('#message_contact').val(),
                verify_contact: $('#verify_contact').val()
            })
            .done(function (data) {
                handleResponse(
                    '#message-contact',
                    '#submit-contact',
                    '#contactform',
                    data,
                    'Messaggio inviato correttamente',
                    'Grazie per averci contattato. Ti risponderemo al più presto.'
                );
            })
            .fail(function () {
                $('#submit-contact').removeAttr('disabled');
                $('#message-contact')
                    .html('<div class="error_message">Si è verificato un errore durante l’invio del messaggio. Riprova.</div>')
                    .slideDown('slow', function () {
                        scrollToElement('#message-contact');
                    });
            });
        });
    });

});
/* ]]> */