/**
 * Vocify AI Admin Scripts
 *
 * @package VocifyAI
 * @version 1.0.0
 */

(function($) {
    'use strict';

    $(document).ready(function() {
        // Test Connection Button
        $('#vocify-test-connection').on('click', function(e) {
            e.preventDefault();

            var $button = $(this);
            var $result = $('#vocify-test-result');

            // Disable button and show loading state
            $button.prop('disabled', true).text(vocifyAdmin.loadingText || 'Testing...');
            $result.removeClass('success error').addClass('loading')
                .html('<span class="vocify-spinner"></span> Testing connection...')
                .show();

            // Send AJAX request
            $.ajax({
                url: vocifyAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'vocify_test_connection',
                    nonce: vocifyAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $result.removeClass('loading error').addClass('success')
                            .html('✓ ' + response.data.message);
                    } else {
                        $result.removeClass('loading success').addClass('error')
                            .html('✗ ' + response.data.message);
                    }
                },
                error: function(xhr, status, error) {
                    $result.removeClass('loading success').addClass('error')
                        .html('✗ Connection failed: ' + error);
                },
                complete: function() {
                    // Re-enable button
                    $button.prop('disabled', false).text(vocifyAdmin.buttonText || 'Test Connection');

                    // Auto-hide success message after 5 seconds
                    if ($result.hasClass('success')) {
                        setTimeout(function() {
                            $result.fadeOut();
                        }, 5000);
                    }
                }
            });
        });

        // API Key Field - Toggle visibility
        var $apiKeyField = $('#vocify_api_key');
        if ($apiKeyField.length) {
            var $toggleButton = $('<button type="button" class="button button-secondary" style="margin-left: 5px;">Show</button>');
            $apiKeyField.after($toggleButton);

            $toggleButton.on('click', function(e) {
                e.preventDefault();
                if ($apiKeyField.attr('type') === 'password') {
                    $apiKeyField.attr('type', 'text');
                    $toggleButton.text('Hide');
                } else {
                    $apiKeyField.attr('type', 'password');
                    $toggleButton.text('Show');
                }
            });
        }

        // Form validation
        $('form').on('submit', function(e) {
            var apiKey = $('#vocify_api_key').val();
            var enabled = $('#vocify_enabled').is(':checked');

            // Validate API key format if integration is enabled
            if (enabled && apiKey && !apiKey.match(/^vcf_(live|test)_[a-zA-Z0-9]{20,}$/)) {
                e.preventDefault();
                alert('Invalid API key format. API key should start with "vcf_live_" or "vcf_test_" followed by alphanumeric characters.');
                return false;
            }

            // Warn if enabling without API key
            if (enabled && !apiKey) {
                e.preventDefault();
                if (confirm('You are enabling the integration without an API key. Webhooks will not be sent until you configure an API key. Continue?')) {
                    // Allow form submission
                    $(this).off('submit').submit();
                }
                return false;
            }
        });

        // Webhook URL validation
        $('#vocify_webhook_url').on('blur', function() {
            var url = $(this).val();
            if (url && !url.match(/^https:\/\/.+/)) {
                alert('Webhook URL must use HTTPS protocol for security.');
                $(this).focus();
            }
        });
    });

})(jQuery);
