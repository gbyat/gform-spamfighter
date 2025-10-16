/**
 * Form JavaScript for GForm Spamfighter
 * Tracks form interaction timing and enforces strike lockouts
 */

(function ($) {
    'use strict';

    if (typeof gformSpamfighterForm === 'undefined') {
        return;
    }

    const formId = gformSpamfighterForm.formId;
    const startTime = gformSpamfighterForm.startTime;
    const hasStrikes = gformSpamfighterForm.hasStrikes;
    const strikeMessage = gformSpamfighterForm.strikeMessage;

    $(document).ready(function () {
        const $form = $('#gform_' + formId);

        if (!$form.length) {
            return;
        }

        // Add hidden field to track form start time
        $('<input>')
            .attr('type', 'hidden')
            .attr('name', 'gform_timer_' + formId)
            .val(startTime)
            .appendTo($form);

        // If user has strikes, lock the form completely
        if (hasStrikes) {
            // Disable all input fields
            $form.find('input, textarea, select, button').prop('disabled', true);

            // Disable submit button specifically
            $form.find('.gform_button, input[type="submit"]').prop('disabled', true).css({
                'opacity': '0.5',
                'cursor': 'not-allowed'
            });

            // Show warning message at top of form
            const $warningDiv = $('<div>')
                .addClass('gform-strike-warning')
                .css({
                    'background': '#fff3cd',
                    'border': '1px solid #ffc107',
                    'border-left': '4px solid #ff9800',
                    'padding': '15px',
                    'margin': '0 0 20px 0',
                    'border-radius': '4px',
                    'color': '#856404',
                    'font-size': '14px'
                })
                .html('<strong>⚠️ ' + strikeMessage + '</strong><br><p style="margin:10px 0 0 0;">You can <a href="javascript:location.reload()" style="color:#856404;text-decoration:underline;">reload the page</a> to start fresh.</p>');

            $form.prepend($warningDiv);

            // Prevent form submission completely
            $form.on('submit', function (e) {
                e.preventDefault();
                e.stopPropagation();
                alert(strikeMessage);
                return false;
            });
        }
    });

})(jQuery);
