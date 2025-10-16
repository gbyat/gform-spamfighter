/**
 * Admin JavaScript for GForm Spamfighter
 */

(function ($) {
    'use strict';

    $(document).ready(function () {
        // Toggle OpenAI fields visibility based on checkbox
        const $openaiEnabled = $('input[name="gform_spamfighter_settings[openai_enabled]"]');
        const $openaiFields = $openaiEnabled.closest('tr').nextAll('tr').slice(0, 3);

        function toggleOpenAIFields() {
            if ($openaiEnabled.is(':checked')) {
                $openaiFields.show();
            } else {
                $openaiFields.hide();
            }
        }

        $openaiEnabled.on('change', toggleOpenAIFields);
        toggleOpenAIFields();

        // Show/hide dependent fields
        $('input[type="checkbox"][name*="gform_spamfighter_settings"]').on('change', function () {
            const fieldId = $(this).attr('name').match(/\[(.*?)\]/)[1];
            const $dependentField = $(this).closest('tr').next('tr');

            if ($(this).is(':checked')) {
                $dependentField.show();
            } else {
                $dependentField.hide();
            }
        }).trigger('change');

        // Test API Connection via AJAX
        $('#gform-test-api-btn').on('click', function () {
            const $btn = $(this);
            const $result = $('#gform-test-api-result');

            console.log('Test API button clicked');

            // Check if data is available
            if (typeof gformSpamfighterAdmin === 'undefined') {
                console.error('gformSpamfighterAdmin not defined!');
                $result.html('<span style="color:red;">✗ Error: Admin data not loaded</span>');
                return;
            }

            console.log('AJAX URL:', gformSpamfighterAdmin.ajax_url);
            console.log('Nonce:', gformSpamfighterAdmin.nonce);

            $btn.prop('disabled', true).text('Testing...');
            $result.html('<span style="color:#666;">⏳ Connecting to OpenAI...</span>');

            $.ajax({
                url: gformSpamfighterAdmin.ajax_url,
                type: 'POST',
                data: {
                    action: 'gform_spamfighter_test_api',
                    nonce: gformSpamfighterAdmin.nonce
                },
                timeout: 50000, // 50 seconds
                success: function (response) {
                    console.log('API Test response:', response);
                    if (response.success) {
                        $result.html('<span style="color:green;font-weight:bold;">✓ ' + response.data.message + '</span>');
                    } else {
                        $result.html('<span style="color:red;font-weight:bold;">✗ ' + response.data.message + '</span>');
                    }
                },
                error: function (jqXHR, textStatus, errorThrown) {
                    console.error('API Test error:', textStatus, errorThrown);
                    console.error('Response:', jqXHR.responseText);
                    $result.html('<span style="color:red;font-weight:bold;">✗ Error: ' + textStatus + '</span>');
                },
                complete: function () {
                    $btn.prop('disabled', false).text('Test Connection');
                }
            });
        });
    });

})(jQuery);
