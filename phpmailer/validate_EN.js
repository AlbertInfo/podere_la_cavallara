/* <![CDATA[ */
$(document).ready(function () {

    function getHomeUrl() {
        const path = window.location.pathname;
        const lastSlash = path.lastIndexOf('/');
        const basePath = path.substring(0, lastSlash + 1);
        return basePath + 'index.html';
    }

    function bindSuccessModalRedirect() {
        const modalEl = document.getElementById('formSuccessModal');

        if (!modalEl || typeof bootstrap === 'undefined') {
            return;
        }

        $(modalEl).off('hidden.bs.modal.formredirect');

        $(modalEl).on('hidden.bs.modal.formredirect', function () {
            window.location.href = getHomeUrl();
        });
    }

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
                    window.location.href = getHomeUrl();
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

    bindSuccessModalRedirect();

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
                    'Message sent successfully',
                    'Thank you for contacting us. We will respond to you as soon as possible.'
                );
            })
            .fail(function () {
                $('#submit-contact').removeAttr('disabled');
                $('#message-contact')
                    .html('<div class="error_message">An error occurred while sending the message. Please try again.</div>')
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
                    'Request sent successfully',
                    'We have received your booking request. We will respond to you as soon as possible with all the details.'
                );
            })
            .fail(function () {
                $('#submit-booking').removeAttr('disabled');
                $('#message-booking')
                    .html('<div class="error_message">An error occurred while sending the request. Please try again.</div>')
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
                    'Subscription completed',
                    'Thank you, your subscription to the newsletter has been registered successfully.'
                );
            })
            .fail(function () {
                $('#submit-newsletter').removeAttr('disabled');
                $('#message-newsletter')
                    .html('<div class="error_message">An error occurred. Please try again in a moment.</div>')
                    .slideDown('slow');
            });
        });
    });

});
/* ]]> */