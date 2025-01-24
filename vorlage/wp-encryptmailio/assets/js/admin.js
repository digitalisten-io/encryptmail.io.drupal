jQuery(document).ready(function($) {
    // Add configuration
    $('#add-config').on('click', function() {
        const index = $('.email-config').length;
        const newConfig = `
            <div class="email-config">
                <input type="email" name="wp_encryptmailio_settings[configs][${index}][email]" required>
                <select name="wp_encryptmailio_settings[configs][${index}][type]">
                    <option value="smime">S/MIME</option>
                    <option value="pgp">PGP</option>
                </select>
                <div class="obscure-subject">
                    <label>
                        <input type="checkbox"
                               name="wp_encryptmailio_settings[configs][${index}][obscure_subject]"
                               value="1">
                        Obscure Email Subject
                    </label>
                </div>
                <textarea name="wp_encryptmailio_settings[configs][${index}][key]" rows="3" required></textarea>
                <button type="button" class="remove-config">Remove</button>
            </div>
        `;
        $('#email-configs').append(newConfig);
    });

    // Remove configuration
    $(document).on('click', '.remove-config', function() {
        $(this).closest('.email-config').remove();
    });

    // Send test email
    $('#send-test').on('click', function() {
        const button = $(this);
        const resultSpan = $('#test-result');
        const email = $('#test-email').val();

        if (!email) {
            resultSpan.html('Please enter an email address').css('color', 'red');
            return;
        }

        button.prop('disabled', true);
        resultSpan.html('Sending...').css('color', 'black');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'wp_encryptmailio_test_email',
                email: email,
                security: wpEncryptMailio.nonce
            },
            success: function(response) {
                if (response.success) {
                    resultSpan.html(response.data.message).css('color', 'green');
                } else {
                    resultSpan.html(response.data.message).css('color', 'red');
                }
            },
            error: function() {
                resultSpan.html('Failed to send test email').css('color', 'red');
            },
            complete: function() {
                button.prop('disabled', false);
            }
        });
    });

    // Test API key
    $('#test-api-key').on('click', function() {
        const button = $(this);
        const resultSpan = $('#api-key-result');
        const apiKey = $('#api-key-input').val();

        if (!apiKey) {
            resultSpan.html('<span class="api-status invalid">Please enter an API key</span>');
            return;
        }

        button.prop('disabled', true);
        resultSpan.html('<span class="api-status">Testing...</span>');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'wp_encryptmailio_test_api_key',
                api_key: apiKey,
                security: wpEncryptMailio.nonce
            },
            success: function(response) {
                if (response.success) {
                    resultSpan.html('<span class="api-status valid">' + response.data.message + '</span>');
                } else {
                    resultSpan.html('<span class="api-status invalid">' + response.data.message + '</span>');
                }
            },
            error: function() {
                resultSpan.html('<span class="api-status error">Connection failed</span>');
            },
            complete: function() {
                button.prop('disabled', false);
            }
        });
    });
});
