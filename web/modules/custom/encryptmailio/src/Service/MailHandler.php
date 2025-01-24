<?php

namespace Drupal\encryptmailio\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Handles mail encryption and processing.
 */
class MailHandler {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The encryptor service.
   *
   * @var \Drupal\encryptmailio\Service\Encryptor
   */
  protected $encryptor;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * Constructs a new MailHandler object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\encryptmailio\Service\Encryptor $encryptor
   *   The encryptor service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    Encryptor $encryptor,
    LoggerChannelFactoryInterface $logger_factory,
  ) {
    $this->configFactory = $config_factory;
    $this->encryptor = $encryptor;
    $this->loggerFactory = $logger_factory;
  }

  /**
   * Alters the mail message to encrypt it if needed.
   *
   * @param array $message
   *   The message array to alter.
   */
  public function alterMessage(array &$message): void {
    $config = $this->configFactory->get('encryptmailio.settings');
    $configs = $config->get('configs') ?: [];

    foreach ($configs as $mail_config) {
      if ($message['to'] === $mail_config['email']) {
        try {
          // Join body array into a single string for encryption.
          $body_text = implode("\n", $message['body']);

          // Encrypt the message body.
          $encrypted = $this->encryptor->encrypt(
            $body_text,
            $mail_config['key'],
            $mail_config['type']
          );

          // Set the encrypted content as the message body.
          $message['body'] = [$encrypted];

          // Set headers for S/MIME.
          if ($mail_config['type'] === 'smime') {
            $message['headers']['Content-Type'] = 'application/x-pkcs7-mime; protocol="application/x-pkcs7-mime"; smimetype=enveloped-data; name="smime.p7m"';
            $message['headers']['Content-Transfer-Encoding'] = 'base64';
            $message['headers']['Content-Disposition'] = 'attachment; filename="smime.p7m"';
          }

          // Obscure subject if configured.
          if (!empty($mail_config['obscure_subject'])) {
            $message['subject'] = '[Encrypted] ' . $message['subject'];
          }

          $this->loggerFactory->get('encryptmailio')->info(
            'Successfully encrypted email to @recipient',
            ['@recipient' => $message['to']]
          );
        }
        catch (\Exception $e) {
          $this->loggerFactory->get('encryptmailio')->error(
            'Failed to encrypt email to @recipient: @error',
            [
              '@recipient' => $message['to'],
              '@error' => $e->getMessage(),
            ]
          );
        }
        break;
      }
    }
  }

}
