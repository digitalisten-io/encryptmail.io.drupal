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
    $this->loggerFactory->get('encryptmailio')->info(
      'Processing mail message to: @to',
      ['@to' => $message['to']]
    );

    $config = $this->configFactory->get('encryptmailio.settings');
    $configs = $config->get('configs') ?: [];

    $this->loggerFactory->get('encryptmailio')->debug(
      'Found @count encryption configurations',
      ['@count' => count($configs)]
    );

    foreach ($configs as $mail_config) {
      if ($message['to'] === $mail_config['email']) {
        $this->loggerFactory->get('encryptmailio')->info(
          'Found matching configuration for @email using @type encryption',
          [
            '@email' => $mail_config['email'],
            '@type' => $mail_config['type'],
          ]
        );

        try {
          // Join body array into a single string for encryption.
          $body_text = implode("\n", $message['body']);

          $this->loggerFactory->get('encryptmailio')->debug(
            'Preparing to encrypt message body of length: @length',
            ['@length' => strlen($body_text)]
          );

          // Encrypt the message body.
          $encrypted = $this->encryptor->encrypt(
            $body_text,
            $mail_config['key'],
            $mail_config['type']
          );

          $this->loggerFactory->get('encryptmailio')->debug(
            'Message encrypted successfully, encrypted length: @length',
            ['@length' => strlen($encrypted)]
          );

          if ($mail_config['type'] === 'smime') {
            // Set up S/MIME specific parameters.
            $message['params']['smime'] = TRUE;

            // Set the encrypted content as the body.
            $message['body'] = [base64_encode($encrypted)];

            // Set minimal headers to avoid conflicts.
            $message['headers'] = [
              'MIME-Version' => '1.0',
              'Content-Type' => 'application/x-pkcs7-mime; smimetype=enveloped-data; name=smime.p7m',
              'Content-Transfer-Encoding' => 'base64',
              'Content-Disposition' => 'attachment; filename="smime.p7m"',
            ];

            $this->loggerFactory->get('encryptmailio')->debug('Added S/MIME parameters');
          }
          elseif ($mail_config['type'] === 'pgp') {
            $message['headers'] = [
              'MIME-Version' => '1.0',
              'Content-Type' => 'text/plain; charset=utf-8',
              'Content-Transfer-Encoding' => '7bit',
            ];
            $message['body'] = [$encrypted];
          }

          // Obscure subject if configured.
          if (!empty($mail_config['obscure_subject'])) {
            $message['subject'] = '[Encrypted] ' . $message['subject'];
            $this->loggerFactory->get('encryptmailio')->debug('Subject obscured as configured');
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

    $this->loggerFactory->get('encryptmailio')->debug('Mail message processing completed');
  }

}
