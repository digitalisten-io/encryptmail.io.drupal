<div class="wrap">
    <h1>encryptmail.io Settings</h1>

    <?php
    // Check API key status if one is configured
    $api_key = $this->options['api_key'] ?? '';
    $api_status = '';



    // @TODO:
    // - Add a way to test the API key
    // - Send number of email adresses that are configured
    // - Send number of emails that have been sent in current period and were encrypted
    // Return from API:
    // - Plan name
    // - Number of emails that have been sent in current period
    // - Number of emails allowed in current period
    // - period start date
    // - period end date
    // - Number of allowed email adresses


    if (!empty($api_key)) {
        $verify_url = 'https://api.encryptmail.io/v1/verify';
        $response = wp_remote_post($verify_url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json'
            ),
            'timeout' => 15,
            'body' => json_encode(array(
                'domain' => parse_url(get_site_url(), PHP_URL_HOST)
            ))
        ));

        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (!empty($body['valid'])) {
                $api_status = '<span class="api-status valid">API Key Valid</span>';
            } else {
                $api_status = '<span class="api-status invalid">Invalid API Key</span>';
            }
        } else {
            $api_status = '<span class="api-status error">Could not verify API key</span>';
        }
    }
    ?>

    <form method="post" action="options.php">
        <?php
        settings_fields('wp-encryptmailio');
        do_settings_sections('wp-encryptmailio');
        ?>

        <table class="form-table">
            <tr>
                <th>API Key</th>
                <td>
                    <input type="text" name="wp_encryptmailio_settings[api_key]"
                           value="<?php echo esc_attr($api_key); ?>"
                           class="regular-text"
                           id="api-key-input">
                    <button type="button" id="test-api-key" class="button button-secondary">Test API Key</button>
                    <span id="api-key-result"></span>
                    <p class="description">Enter your encryptmail.io API key. Get one at <a href="https://encryptmail.io" target="_blank">encryptmail.io</a></p>
                </td>
            </tr>
        </table>

        <h2>Email Configurations</h2>
        <div id="email-configs">
            <?php
            $configs = $this->options['configs'] ?? array();
            foreach ($configs as $index => $config): ?>
                <div class="email-config">
                    <input type="email"
                           name="wp_encryptmailio_settings[configs][<?php echo $index; ?>][email]"
                           value="<?php echo esc_attr($config['email']); ?>"
                           required>
                    <select name="wp_encryptmailio_settings[configs][<?php echo $index; ?>][type]">
                        <option value="smime" <?php selected($config['type'], 'smime'); ?>>S/MIME</option>
                        <option value="pgp" <?php selected($config['type'], 'pgp'); ?>>PGP</option>
                    </select>
                    <div class="obscure-subject">
                        <label>
                            <input type="checkbox"
                                   name="wp_encryptmailio_settings[configs][<?php echo $index; ?>][obscure_subject]"
                                   value="1"
                                   <?php checked(!empty($config['obscure_subject'])); ?>>
                            Obscure Email Subject
                        </label>
                    </div>
                    <textarea name="wp_encryptmailio_settings[configs][<?php echo $index; ?>][key]"
                              rows="3" required><?php echo esc_textarea($config['key']); ?></textarea>
                    <button type="button" class="remove-config">Remove</button>
                </div>
            <?php endforeach; ?>
        </div>
        <button type="button" id="add-config">Add Email Configuration</button>

        <hr class="ruler" />

        <h2>Send Test Email</h2>
        <table class="form-table">
            <tr>
                <th>Test Email Address</th>
                <td>
                    <input type="email" id="test-email" class="regular-text">
                    <button type="button" id="send-test">Send Test Email</button>
                    <span id="test-result"></span>
                </td>
            </tr>
        </table>

        <?php submit_button(); ?>
    </form>
</div>
