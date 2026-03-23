/* <![CDATA[ */
$(function () {

    function openSuccessModal(title, text) {
        const titleEl = document.getElementById('formSuccessModalTitle');
        const textEl = document.getElementById('formSuccessModalText');

        if (titleEl) titleEl.textContent = title;
        if (textEl) textEl.textContent = text;

        const modalEl = document.getElementById('formSuccessModal');
        const modal = new bootstrap.Modal(modalEl);
        modal.show();
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

            $(formSelector).slideUp('slow');
            $(messageSelector).hide().html('');
            openSuccessModal(title, text);
            return;
        }

        document.querySelector(messageSelector).innerHTML = data;
        $(messageSelector).slideDown('slow');
    }

    $('#bookingform').submit(function (e) {
        e.preventDefault();

        var action = $(this).attr('action');

        $("#message-booking").slideUp(200, function () {
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
                    .slideDown('slow');
            });
        });
    });

    $('#contactform').submit(function (e) {
        e.preventDefault();

        var action = $(this).attr('action');

        $("#message-contact").slideUp(200, function () {
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
                    .slideDown('slow');
            });
        });
    });

});
/* ]]> */