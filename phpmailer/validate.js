/* <![CDATA[ */
$(function () {

    function scrollToMessage(selector) {
        const target = document.querySelector(selector);
        if (!target) return;

        const offset = window.innerWidth < 768 ? 90 : 120;
        const top = target.getBoundingClientRect().top + window.pageYOffset - offset;

        window.scrollTo({
            top: top,
            behavior: 'smooth'
        });
    }

    function showResponse(messageSelector, submitSelector, formSelector, data) {
        const isSuccess = data.indexOf('success_page') !== -1;

        document.querySelector(messageSelector).innerHTML = data;

        $(messageSelector).slideDown('slow', function () {
            if (isSuccess) {
                $(formSelector).slideUp('slow', function () {
                    setTimeout(function () {
                        scrollToMessage(messageSelector);
                    }, 150);
                });
            } else {
                setTimeout(function () {
                    scrollToMessage(messageSelector);
                }, 100);
            }
        });

        $(submitSelector).removeAttr('disabled');
    }

    $('#newsletter_form').submit(function (e) {
        e.preventDefault();

        var action = $(this).attr('action');

        $("#message-newsletter").slideUp(300, function () {
            $('#message-newsletter').hide();
            $('#submit-newsletter').attr('disabled', 'disabled');

            $.post(action, {
                email_newsletter: $('#email_newsletter').val()
            })
            .done(function (data) {
                showResponse('#message-newsletter', '#submit-newsletter', '#newsletter_form', data);
            })
            .fail(function () {
                $('#message-newsletter').html('<div class="error_message">Si è verificato un errore. Riprova tra qualche istante.</div>').slideDown('slow');
                $('#submit-newsletter').removeAttr('disabled');
                scrollToMessage('#message-newsletter');
            });
        });
    });

    $('#bookingform').submit(function (e) {
        e.preventDefault();

        var action = $(this).attr('action');

        $("#message-booking").slideUp(300, function () {
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
                showResponse('#message-booking', '#submit-booking', '#bookingform', data);
            })
            .fail(function () {
                $('#message-booking').html('<div class="error_message">Si è verificato un errore durante l\'invio della richiesta. Riprova.</div>').slideDown('slow');
                $('#submit-booking').removeAttr('disabled');
                scrollToMessage('#message-booking');
            });
        });
    });

    $('#contactform').submit(function (e) {
        e.preventDefault();

        var action = $(this).attr('action');

        $("#message-contact").slideUp(300, function () {
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
                showResponse('#message-contact', '#submit-contact', '#contactform', data);
            })
            .fail(function () {
                $('#message-contact').html('<div class="error_message">Si è verificato un errore durante l\'invio del messaggio. Riprova.</div>').slideDown('slow');
                $('#submit-contact').removeAttr('disabled');
                scrollToMessage('#message-contact');
            });
        });
    });

});
/* ]]> */