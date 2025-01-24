<?php

namespace Drupal\encryptmailio\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use GuzzleHttp\Exception\RequestException;

/**
 * Configure EncryptMail.io settings.
 */
class EncryptMailIOSettingsForm extends ConfigFormBase {

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
