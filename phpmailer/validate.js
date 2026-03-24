/* <![CDATA[ */
$(document).ready(function () {

    function openSuccessModal(title, text) {
        const modalEl = document.getElementById('formSuccessModal');
        const titleEl = document.getElementById('formSuccessModalTitle');
        const textEl = document.getElementById('formSuccessModalText');

        if (!modalEl || typeof bootstrap === 'undefined') {
            return false;
        }

        if (titleEl) titleEl.textContent = title;
        if (textEl) textEl.textContent = text;

        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);

        // Alla chiusura della modale ricarica la pagina senza hash
        $(modalEl).off('hidden.bs.modal').one('hidden.bs.modal', function () {
            const cleanUrl = window.location.pathname + window.location.search;
            window.location.href = cleanUrl;
        });

        modal.show();
        return true;
    }

    function showInlineSuccess(messageSelector, title, text) {
        const html = `
            <div class="form-success-fallback">
                <div class="form-success-fallback-icon">✓</div>
                <h4>${title}</h4>
                <p>${text}</p>
            </div>
        `;
        $(messageSelector).html(html).slideDown('slow');
    }

    function handleResponse(data, formSelector, messageSelector, submitSelector, fallbackTitle, fallbackText) {
        const wrapper = document.createElement('div');
        wrapper.innerHTML = data;

        const successNode = wrapper.querySelector('#success_page');
        const isSuccess = !!successNode;

        $(submitSelector).removeAttr('disabled');

        if (isSuccess) {
            const title = successNode.dataset.title || fallbackTitle;
            const text = successNode.dataset.text || fallbackText;

            const modalOpened = openSuccessModal(title, text);

            if (modalOpened) {
                $(formSelector).stop(true, true).slideUp('slow');
                $(messageSelector).hide().html('');
            } else {
                $(formSelector).stop(true, true).slideUp('slow', function () {
                    showInlineSuccess(messageSelector, title, text);
                    window.scrollTo({
                        top: 0,
                        behavior: 'smooth'
                    });
                });
            }

            return;
        }

        $(messageSelector).html(data).slideDown('slow', function () {
            const target = document.querySelector(messageSelector);
            if (target) {
                const top = target.getBoundingClientRect().top + window.pageYOffset - 100;
                window.scrollTo({
                    top: top,
                    behavior: 'smooth'
                });
            }
        });
    }

    // CONTACT FORM
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
                    data,
                    '#contactform',
                    '#message-contact',
                    '#submit-contact',
                    'Messaggio inviato correttamente',
                    'Grazie per averci contattato. Ti risponderemo al più presto.'
                );
            })
            .fail(function () {
                $('#submit-contact').removeAttr('disabled');
                $('#message-contact')
                    .html('<div class="error_message">Si è verificato un errore durante l’invio del messaggio. Riprova.</div>')
                    .slideDown('slow');
            });
        });
    });

    // BOOKING FORM
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
                    data,
                    '#bookingform',
                    '#message-booking',
                    '#submit-booking',
                    'Richiesta inviata correttamente',
                    'Abbiamo ricevuto la tua richiesta di prenotazione. Ti risponderemo al più presto con tutti i dettagli.'
                );
            })
            .fail(function () {
                $('#submit-booking').removeAttr('disabled');
                $('#message-booking')
                    .html('<div class="error_message">Si è verificato un errore durante l’invio della richiesta. Riprova.</div>')
                    .slideDown('slow');
            });
        });
    });

    // NEWSLETTER FORM
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
                    data,
                    '#newsletter_form',
                    '#message-newsletter',
                    '#submit-newsletter',
                    'Iscrizione completata',
                    'Grazie, la tua iscrizione alla newsletter è stata registrata correttamente.'
                );
            })
            .fail(function () {
                $('#submit-newsletter').removeAttr('disabled');
                $('#message-newsletter')
                    .html('<div class="error_message">Si è verificato un errore. Riprova tra qualche istante.</div>')
                    .slideDown('slow');
            });
        });
    });

});
/* ]]> */