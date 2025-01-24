<?php

namespace Drupal\encryptmailio\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Mail\MailManagerInterface;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure EncryptMail.io settings.
 */
class EncryptMailIOSettingsForm extends ConfigFormBase {

  /**
   * The mail manager.
   *
   * @var \Drupal\Core\Mail\MailManagerInterface
   */
  protected $mailManager;

  /**
   * Constructs a new EncryptMailIOSettingsForm.
   *
   * @param \Drupal\Core\Mail\MailManagerInterface $mail_manager
   *   The mail manager.
   */
  public function __construct(MailManagerInterface $mail_manager) {
    $this->mailManager = $mail_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('plugin.manager.mail')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'encryptmailio_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['encryptmailio.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('encryptmailio.settings');

    $form['api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API Key'),
      '#default_value' => $config->get('api_key'),
      '#description' => [
        '#type' => 'inline_template',
        '#template' => 'Enter your encryptmail.io API key. Get one at <a href="@url">encryptmail.io</a>',
        '#context' => ['@url' => 'https://encryptmail.io'],
      ],
      '#required' => TRUE,
    ];

    $form['test_api_key'] = [
      '#type' => 'button',
      '#value' => $this->t('Test API Key'),
      '#ajax' => [
        'callback' => '::testApiKey',
        'wrapper' => 'api-key-result',
        'effect' => 'fade',
      ],
    ];

    $form['api_key_result'] = [
      '#type' => 'markup',
      '#markup' => '<div id="api-key-result"></div>',
    ];

    // Email configurations container.
    $form['configs'] = [
      '#type' => 'container',
      '#tree' => TRUE,
      '#prefix' => '<div id="email-configs">',
      '#suffix' => '</div>',
    ];

    // Get configs from form state if available, otherwise from config.
    $configs = $form_state->get('configs');
    if ($configs === NULL) {
      $configs = $config->get('configs') ?: [];
      $form_state->set('configs', $configs);
    }

    foreach ($configs as $delta => $config_item) {
      $form['configs'][$delta] = $this->buildConfigurationField($delta, $config_item);
    }

    $form['add_config'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add Email Configuration'),
      '#submit' => ['::addConfiguration'],
      '#ajax' => [
        'callback' => '::updateConfigurations',
        'wrapper' => 'email-configs',
        'effect' => 'fade',
        'progress' => [
          'type' => 'throbber',
          'message' => t('Adding new configuration...'),
        ],
      ],
      '#attributes' => [
        'class' => ['button--primary'],
      ],
    ];

    // Add test email section.
    $form['test_email'] = [
      '#type' => 'details',
      '#title' => $this->t('Test Email'),
      '#open' => TRUE,
    ];

    $form['test_email']['recipient'] = [
      '#type' => 'email',
      '#title' => $this->t('Recipient Email'),
      '#description' => $this->t('Enter an email address to send a test message to.'),
      '#required' => FALSE,
    ];

    $form['test_email']['send_test'] = [
      '#type' => 'submit',
      '#value' => $this->t('Send Test Email'),
      '#submit' => ['::sendTestEmail'],
      '#ajax' => [
        'callback' => '::updateTestResult',
        'wrapper' => 'test-result',
        'effect' => 'fade',
        'progress' => [
          'type' => 'throbber',
          'message' => $this->t('Sending test email...'),
        ],
      ],
    ];

    $form['test_email']['result'] = [
      '#type' => 'markup',
      '#markup' => '<div id="test-result"></div>',
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * Builds a single email configuration field group.
   */
  protected function buildConfigurationField($delta, array $config = []): array {
    $field = [
      '#type' => 'fieldset',
      '#title' => $this->t('Email Configuration @num', ['@num' => $delta + 1]),
    ];

    $field['email'] = [
      '#type' => 'email',
      '#title' => $this->t('Email Address'),
      '#default_value' => $config['email'] ?? '',
      '#required' => TRUE,
    ];

    $field['type'] = [
      '#type' => 'select',
      '#title' => $this->t('Encryption Type'),
      '#options' => [
        'smime' => 'S/MIME',
        'pgp' => 'PGP',
      ],
      '#default_value' => $config['type'] ?? 'smime',
    ];

    $field['obscure_subject'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Obscure Email Subject'),
      '#default_value' => $config['obscure_subject'] ?? FALSE,
    ];

    $field['key'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Encryption Key'),
      '#default_value' => $config['key'] ?? '',
      '#required' => TRUE,
      '#rows' => 3,
    ];

    $field['remove'] = [
      '#type' => 'submit',
      '#value' => $this->t('Remove'),
      '#name' => 'remove_' . $delta,
      '#submit' => ['::removeConfiguration'],
      '#ajax' => [
        'callback' => '::updateConfigurations',
        'wrapper' => 'email-configs',
      ],
      '#attributes' => ['class' => ['button--danger']],
    ];

    return $field;
  }

  /**
   * Ajax callback to test the API key.
   */
  public function testApiKey(array &$form, FormStateInterface $form_state): array {
    $api_key = $form_state->getValue('api_key');
    try {
      $client = \Drupal::httpClient();
      $response = $client->post('https://api.encryptmail.io/v1/verify', [
        'headers' => [
          'Authorization' => 'Bearer ' . $api_key,
          'Content-Type' => 'application/json',
        ],
        'json' => [
          'domain' => \Drupal::request()->getHost(),
        ],
      ]);

      $result = json_decode((string) $response->getBody(), TRUE);
      if (!empty($result['valid'])) {
        $message = $this->t('API key is valid');
        if (!empty($result['plan'])) {
          $message .= ' (' . $result['plan'] . ' plan)';
        }
        $class = 'valid';
      }
      else {
        $message = $this->t('Invalid API key');
        $class = 'invalid';
      }
    }
    catch (RequestException $e) {
      $message = $this->t('Could not verify API key');
      $class = 'error';
    }

    return [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#value' => $message,
      '#attributes' => [
        'class' => ['api-status', $class],
      ],
    ];
  }

  /**
   * Submit handler for adding a new configuration.
   */
  public function addConfiguration(array &$form, FormStateInterface $form_state): array {
    $configs = $form_state->getValue('configs', []);
    $configs[] = [
      'email' => '',
      'type' => 'smime',
      'obscure_subject' => FALSE,
      'key' => '',
    ];

    // Store the updated configs in form state storage instead of just setValue.
    $form_state->set('configs', $configs);
    $form_state->setRebuild(TRUE);

    // Return the entire form to ensure proper AJAX rebuild.
    return $form;
  }

  /**
   * Submit handler for removing a configuration.
   */
  public function removeConfiguration(array &$form, FormStateInterface $form_state): array {
    $trigger = $form_state->getTriggeringElement();
    $delta = (int) str_replace('remove_', '', $trigger['#name']);

    $configs = $form_state->getValue('configs', []);
    unset($configs[$delta]);
    // Re-index the array to ensure sequential keys.
    $configs = array_values($configs);

    $form_state->setValue('configs', $configs);
    $form_state->setRebuild();
    return $form['configs'];
  }

  /**
   * Ajax callback for updating configurations.
   */
  public function updateConfigurations(array &$form, FormStateInterface $form_state): array {
    // Return the entire configs container with its wrapper.
    return $form['configs'];
  }

  /**
   * Submit handler for sending test email.
   */
  public function sendTestEmail(array &$form, FormStateInterface $form_state): void {
    $recipient = $form_state->getValue('recipient');

    if (empty($recipient)) {
      $form_state->setError($form['test_email']['recipient'], $this->t('Please enter a recipient email address.'));
      return;
    }

    // Create the email body as a simple array of strings.
    $params = [
      'subject' => 'EncryptMail.io Test Email',
      'body' => [
        'This is a test email from your Drupal site using EncryptMail.io encryption.',
        '',
        'If you can read this message and it appears to be encrypted correctly, your email encryption is working properly.',
        '',
        'Sent: ' . date('Y-m-d H:i:s'),
      ],
    ];

    try {
      $result = $this->mailManager->mail(
        'encryptmailio',
        'test',
        $recipient,
        \Drupal::currentUser()->getPreferredLangcode(),
        $params,
        NULL,
        TRUE
      );

      if ($result['result']) {
        $this->messenger()->addStatus($this->t('Test email sent successfully to @email.', ['@email' => $recipient]));
      }
      else {
        $this->messenger()->addError($this->t('Failed to send test email to @email.', ['@email' => $recipient]));
      }
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Error sending test email: @error', ['@error' => $e->getMessage()]));
    }
  }

  /**
   * Ajax callback for updating test result.
   */
  public function updateTestResult(array &$form, FormStateInterface $form_state): array {
    $messenger = \Drupal::messenger();
    $output = [];

    // Get all message types.
    $message_types = ['status', 'warning', 'error'];

    foreach ($message_types as $type) {
      $messages = $messenger->messagesByType($type);
      foreach ($messages as $message) {
        $class = match ($type) {
          'status' => 'valid',
          'error' => 'invalid',
          default => 'error',
        };

        $output[] = [
          '#type' => 'html_tag',
          '#tag' => 'div',
          '#value' => $message,
          '#attributes' => [
            'class' => ['api-status', $class],
          ],
        ];
      }
    }

    $messenger->deleteAll();

    return [
      '#type' => 'container',
      'messages' => $output,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config('encryptmailio.settings');
    $config->set('api_key', $form_state->getValue('api_key'));
    $config->set('configs', $form_state->getValue('configs'));
    $config->save();

    parent::submitForm($form, $form_state);
  }

}
