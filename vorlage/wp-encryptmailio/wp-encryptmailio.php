<?php
/*
Plugin Name: encryptmail.io
Description: Encrypts outgoing WordPress emails using S/MIME or PGP
Version: 1.0
Author: encryptmail.io
*/

if (file_exists(plugin_dir_path(__FILE__) . 'vendor/autoload.php')) {
    require_once plugin_dir_path(__FILE__) . 'vendor/autoload.php';
}

if (!defined('ABSPATH')) exit;

/**
 * encryptmail.io Plugin
 *
 * Handles email encryption for WordPress using S/MIME or PGP encryption methods.
 * Provides an admin interface for managing encryption configurations and sending test emails.
 */
class WPEncryptMailio {
    private $options;
    private static $is_processing = false;
    private $encryptor = null;

    /**
     * Initialize the plugin and set up WordPress hooks.
     *
     * Sets up admin pages, registers settings, and configures email filters.
     * Initializes the encryption handler and removes default WordPress email filters.
     */
    public function __construct() {
        $this->options = get_option('wp_encryptmailio_settings', array());
        add_action('admin_menu', array($this, 'add_settings_page'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('wp_ajax_wp_encryptmailio_test_email', array($this, 'send_test_email'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('wp_ajax_wp_encryptmailio_test_api_key', array($this, 'test_api_key'));

        // Initialize encryptor
        require_once plugin_dir_path(__FILE__) . 'includes/Encryptor.php';
        $this->encryptor = new WPEncryptMailioEncryptor();

        // Hook into PHPMailer initialization
        add_action('phpmailer_init', array($this, 'process_email'));
    }

    /**
     * Adds the plugin's settings page to the WordPress admin menu.
     */
    public function add_settings_page() {
        add_options_page(
            'encryptmail.io Settings',
            'encryptmail.io',
            'manage_options',
            'wp-encryptmailio',
            array($this, 'render_settings_page')
        );
    }

    /**
     * Registers plugin settings in WordPress.
     */
    public function register_settings() {
        register_setting(
            'wp-encryptmailio', // Option group
            'wp_encryptmailio_settings', // Option name
            array(
                'type' => 'array',
                'sanitize_callback' => array($this, 'sanitize_settings'),
                'default' => array(
                    'api_key' => '',
                    'configs' => array()
                )
            )
        );
    }

    /**
     * Sanitizes the settings before saving.
     *
     * @param array $input The raw settings input
     * @return array The sanitized settings
     */
    public function sanitize_settings($input) {
        $sanitized = array(
            'api_key' => sanitize_text_field($input['api_key'] ?? ''),
            'configs' => array()
        );

        if (!empty($input['configs']) && is_array($input['configs'])) {
            foreach ($input['configs'] as $index => $config) {
                if (empty($config['email']) || empty($config['key'])) {
                    continue;
                }

                $sanitized['configs'][] = array(
                    'email' => sanitize_email($config['email']),
                    'type' => in_array($config['type'], array('smime', 'pgp')) ? $config['type'] : 'smime',
                    'key' => sanitize_textarea_field($config['key']),
                    'obscure_subject' => !empty($config['obscure_subject'])
                );
            }
        }

        return $sanitized;
    }

    /**
     * Verifies API key with the service
     *
     * @param string $api_key The API key to verify
     * @return array Verification result
     */
    private function verify_api_key($api_key) {
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

        if (is_wp_error($response)) {
            return array(
                'valid' => false,
                'error' => $response->get_error_message()
            );
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $status_code = wp_remote_retrieve_response_code($response);

        if ($status_code !== 200) {
            return array(
                'valid' => false,
                'error' => 'API request failed'
            );
        }

        return $body;
    }

    /**
     * Encrypts an email if configuration exists for the recipient.
     *
     * @param array $args Email arguments containing 'to', 'message', and other email data
     * @return array Modified email arguments with encryption if applicable
     */
    public function encrypt_email($args) {
        if (!is_array($args)) {
            return $args;
        }

        // Check if API key exists
        $api_key = $this->options['api_key'] ?? '';
        if (empty($api_key)) {
            error_log('encryptmail.io: No API key configured');
            return $args;
        }

        // Verify API key
        $verification = $this->verify_api_key($api_key);
        if (!$verification['valid']) {
            error_log('encryptmail.io: ' . ($verification['error'] ?? 'Invalid API key'));
            return $args;
        }

        // Continue with existing encryption logic
        $to = $args['to'];
        $configs = $this->options['configs'] ?? array();

        // Check if we have a configuration for this email
        $config = null;
        foreach ($configs as $cfg) {
            if ($cfg['email'] === $to) {
                $config = $cfg;
                break;
            }
        }

        if (!$config || empty($config['key'])) {
            return $args;
        }

        try {
            // Handle subject obscuring if enabled
            if (!empty($config['obscure_subject'])) {
                // Store original subject in the message body
                $args['message'] = "Original Subject: {$args['subject']}\r\n\r\n" . $args['message'];
                // Replace subject with generic text
                $args['subject'] = 'Encrypted Message';
            }


            if ($config['type'] === 'smime') {
                // Encrypt message
                $args['message'] = $this->encryptor->encrypt_smime($args['message'], $config['key']);
                // Set up headers - using string format instead of array
                $args['headers']  = "From: " . get_option('admin_email') . "\r\n";
                $args['headers'] .= "MIME-Version: 1.0\r\n";
                $args['headers'] .= "Content-Type: application/x-pkcs7-mime; smimetype=enveloped-data; name=\"smime.p7m\"\r\n";
                $args['headers'] .= "Content-Disposition: attachment; filename=\"smime.p7m\"\r\n";
                $args['headers'] .= "Content-Transfer-Encoding: base64\r\n";
                // Add line breaks to encrypted content
                $args['message'] = chunk_split($args['message'], 76, "\r\n");
            } elseif ($config['type'] === 'pgp') {
                $args['message'] = $this->encryptor->encrypt_pgp($args['message'], $config['key']);
                $args['headers']  = "From: " . get_option('admin_email') . "\r\n";
                $args['headers'] .= "MIME-Version: 1.0\r\n";
                $args['headers'] .= "Content-Type: text/plain; charset=utf-8\r\n";
                $args['headers'] .= "Content-Transfer-Encoding: 7bit\r\n";
            } else {
                throw new Exception('Invalid encryption type');
            }

            return $args;

        } catch (Exception $e) {
            error_log('encryptmail.io encryption error: ' . $e->getMessage());
            return $args;
        }
    }

    /**
     * Validates an email configuration.
     *
     * @param string $email The email address to validate
     * @return array|null The configuration array if found, null otherwise
     */
    private function validate_email_config($email) {
        // ... existing code ...
    }

    /**
     * Handles AJAX request to send a test encrypted email.
     *
     * Validates the request and sends a test message to the specified recipient.
     * Returns JSON response indicating success or failure.
     */
    public function send_test_email() {
        if (!check_ajax_referer('wp_encryptmailio_test', 'security', false)) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }

        try {
            $email = sanitize_email($_POST['email']);
            if (!is_email($email)) {
                throw new Exception('Invalid email address');
            }

            // Check if we have a configuration for this email
            $configs = $this->options['configs'] ?? array();
            $config = null;
            foreach ($configs as $cfg) {
                if ($cfg['email'] === $email) {
                    $config = $cfg;
                    break;
                }
            }

            if (!$config) {
                throw new Exception('No encryption configuration found for this email');
            }

            // Send test email - encryption will be handled by process_email filter
            $result = wp_mail(
                $email,
                'encryptmail.io Test Email',
                "This is a test email from encryptmail.io\r\n" .
                "Sent: " . date('Y-m-d H:i:s') . "\r\n" .
                "To: " . $email . "\r\n"
            );

            if ($result) {
                wp_send_json_success(array('message' => 'Test email sent successfully!'));
            } else {
                throw new Exception('Failed to send email');
            }

        } catch (Exception $e) {
            error_log('encryptmail.io test email error: ' . $e->getMessage());
            wp_send_json_error(array('message' => 'Error: ' . $e->getMessage()));
        }
    }

    /**
     * Logs error messages with context for debugging.
     *
     * @param string $message The error message to log
     * @param array $context Additional context data for the error
     */
    private function log_error($message, $context = array()) {
        // ... existing code ...
    }

    /**
     * Renders the plugin settings page HTML.
     *
     * Checks user capabilities and includes the settings page template.
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Regular settings page
        include plugin_dir_path(__FILE__) . 'templates/settings-page.php';
    }

    /**
     * Enqueues admin-specific CSS and JavaScript files.
     *
     * @param string $hook The current admin page hook
     */
    public function enqueue_admin_assets($hook) {
        if ($hook !== 'settings_page_wp-encryptmailio') {
            return;
        }

        wp_enqueue_style(
            'wp-encryptmailio-admin',
            plugin_dir_url(__FILE__) . 'assets/css/admin.css',
            array(),
            '1.0.0'
        );

        wp_enqueue_script(
            'wp-encryptmailio-admin',
            plugin_dir_url(__FILE__) . 'assets/js/admin.js',
            array('jquery'),
            '1.0.0',
            true
        );

        wp_localize_script('wp-encryptmailio-admin', 'wpEncryptMailio', array(
            'nonce' => wp_create_nonce('wp_encryptmailio_test')
        ));
    }

    /**
     * Sets the content type for encrypted emails.
     *
     * @param string $content_type The current content type
     * @return string The modified content type
     */
    public function set_content_type($content_type) {
        if (self::$is_processing) {
            return $content_type;
        }
        return 'text/plain';
    }

    /**
     * Sets the from email address.
     *
     * @param string $from_email The current from email
     * @return string The modified from email
     */
    public function set_from_email($from_email) {
        if (self::$is_processing) {
            return $from_email;
        }
        return get_option('admin_email');
    }

    /**
     * Processes outgoing emails to apply encryption if needed.
     *
     * @param PHPMailer\PHPMailer\PHPMailer $phpmailer The PHPMailer instance
     */
    public function process_email($phpmailer) {
        // If already processing, skip
        if (self::$is_processing) {
            return;
        }

        // Set processing flag
        self::$is_processing = true;

        try {
            // Get the recipient email
            $to = $phpmailer->getToAddresses()[0][0] ?? '';
            if (empty($to)) {
                return;
            }

            // Prepare email arguments for encryption
            $args = array(
                'to' => $to,
                'subject' => $phpmailer->Subject,
                'message' => $phpmailer->Body,
                'headers' => array()
            );

            // Encrypt email using existing method
            $encrypted = $this->encrypt_email($args);

            // If encryption was applied (args were modified)
            if ($encrypted !== $args) {
                // Update subject if it was modified
                $phpmailer->Subject = $encrypted['subject'];

                // Update message body
                $phpmailer->Body = $encrypted['message'];

                // Parse and set headers
                $headers = is_array($encrypted['headers']) ? $encrypted['headers'] : explode("\r\n", $encrypted['headers']);
                foreach ($headers as $header) {
                    if (strpos($header, ':') !== false) {
                        list($name, $value) = explode(':', $header, 2);
                        $phpmailer->addCustomHeader(trim($name), trim($value));

                        // Set content type if present
                        if (strtolower(trim($name)) === 'content-type') {
                            $phpmailer->ContentType = trim(explode(';', $value)[0]);
                        }
                    }
                }
            }

        } catch (Exception $e) {
            error_log('encryptmail.io process_email error: ' . $e->getMessage());
        } finally {
            self::$is_processing = false;
        }
    }

    /**
     * Handles AJAX request to test an API key
     */
    public function test_api_key() {
        if (!check_ajax_referer('wp_encryptmailio_test', 'security', false)) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }

        $api_key = sanitize_text_field($_POST['api_key'] ?? '');
        if (empty($api_key)) {
            wp_send_json_error(array('message' => 'Please enter an API key'));
            return;
        }

        $verification = $this->verify_api_key($api_key);

        if ($verification['valid']) {
            $message = 'API key is valid';
            if (!empty($verification['plan'])) {
                $message .= ' (' . $verification['plan'] . ' plan)';
            }
            wp_send_json_success(array('message' => $message));
        } else {
            wp_send_json_error(array(
                'message' => $verification['error'] ?? 'Invalid API key'
            ));
        }
    }
}

new WPEncryptMailio();
