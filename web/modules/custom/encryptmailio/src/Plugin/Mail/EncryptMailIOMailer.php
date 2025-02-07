<?php

namespace Drupal\encryptmailio\Plugin\Mail;

use Drupal\phpmailer_smtp\Plugin\Mail\PhpMailerSmtp;
use PHPMailer\PHPMailer\PHPMailer;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Extends PHPMailer SMTP to handle S/MIME encryption.
 *
 * @Mail(
 *   id = "encryptmailio_mailer",
 *   label = @Translation("EncryptMail.io Mailer"),
 *   description = @Translation("Sends emails with S/MIME and PGP encryption support.")
 * )
 */
class EncryptMailIOMailer extends PhpMailerSmtp {

  /**
   * The logger channel factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->loggerFactory = $container->get('logger.factory');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function mail(array $message): bool {
    if (!empty($message['params']['smime'])) {
      $this->loggerFactory->get('encryptmailio')->debug('Processing S/MIME message');

      try {
        // Create PHPMailer instance.
        $mailer = new PHPMailer(TRUE);
        $mailer->isSMTP();
        $mailer->SMTPDebug = 2;
        $mailer->Debugoutput = function ($str, $level) {
          $this->loggerFactory->get('encryptmailio')->debug('PHPMailer: @message', ['@message' => $str]);
        };

        // Configure SMTP settings from parent.
        $this->setSmtpConfig($mailer);

        // Set message parameters.
        $mailer->Subject = $message['subject'];

        // Create proper MIME structure for S/MIME.
        $mailer->ContentType = 'application/x-pkcs7-mime';
        $mailer->CharSet = PHPMailer::CHARSET_UTF8;
        $mailer->Encoding = 'base64';
        $mailer->isHTML(FALSE);

        // Get SMTP username as default sender.
        $smtp_config = $this->configFactory->get('phpmailer_smtp.settings');
        $smtp_username = $smtp_config->get('smtp_username');
        $site_config = $this->configFactory->get('system.site');
        $site_name = $site_config->get('name');

        // Set From and Sender to match SMTP authentication.
        $mailer->setFrom($smtp_username, $site_name);
        $mailer->Sender = $smtp_username;

        if (empty($message['to'])) {
          throw new \Exception('To address is required');
        }
        $mailer->addAddress($message['to']);

        // Set up S/MIME message structure.
        $mailer->Body = $message['body'][0];

        // Generate Message-ID using SMTP host domain.
        $host_domain = parse_url($smtp_config->get('smtp_host'), PHP_URL_HOST) ?? 'encryptmail.io';
        $mailer->MessageID = sprintf('<%s@%s>', uniqid(bin2hex(random_bytes(16))), $host_domain);

        // Set additional required properties.
        $mailer->XMailer = $site_name;
        $mailer->WordWrap = 0;
        $mailer->Timeout = 30;

        // Set single set of MIME headers.
        $mailer->addCustomHeader('Content-Type', 'application/x-pkcs7-mime; smimetype=enveloped-data; name=smime.p7m');
        $mailer->addCustomHeader('Content-Disposition', 'attachment; filename="smime.p7m"');

        $result = $mailer->send();

        if ($result) {
          $this->loggerFactory->get('encryptmailio')->info(
            'Successfully sent S/MIME encrypted email to @recipient',
            ['@recipient' => $message['to']]
          );
        }
        else {
          $this->loggerFactory->get('encryptmailio')->error(
            'Failed to send S/MIME encrypted email: @error',
            ['@error' => $mailer->ErrorInfo]
          );
        }

        return $result;
      }
      catch (\Exception $e) {
        $this->loggerFactory->get('encryptmailio')->error(
          'Error sending S/MIME email: @error',
          ['@error' => $e->getMessage()]
        );
        return FALSE;
      }
    }

    return parent::mail($message);
  }

  /**
   * Configures SMTP settings on the mailer instance.
   */
  protected function setSmtpConfig(PHPMailer $mailer): void {
    // Get SMTP settings from configuration.
    $config = $this->configFactory->get('phpmailer_smtp.settings');
    $site_config = $this->configFactory->get('system.site');

    $mailer->Host = $config->get('smtp_host');
    $mailer->Port = $config->get('smtp_port');

    // Set encryption.
    if ($config->get('smtp_encryption') === 'tls') {
      $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    }
    elseif ($config->get('smtp_encryption') === 'ssl') {
      $mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    }

    // Always enable SMTP authentication.
    $mailer->SMTPAuth = TRUE;
    $mailer->Username = $config->get('smtp_username');
    $mailer->Password = $config->get('smtp_password');

    // Set default sender with site name fallback.
    $default_from = $config->get('smtp_username');
    $default_from_name = $config->get('smtp_from_name') ?: $site_config->get('name');

    // Set From and Sender addresses.
    $mailer->From = $default_from;
    $mailer->FromName = $default_from_name;
    // MAIL FROM should match SMTP authentication.
    $mailer->Sender = $default_from;

    // Enable debug output.
    $mailer->SMTPDebug = 2;
    $mailer->Debugoutput = function ($str, $level) {
      $this->loggerFactory->get('encryptmailio')->debug('PHPMailer: @message', ['@message' => $str]);
    };

    // Log SMTP configuration.
    $this->loggerFactory->get('encryptmailio')->debug('SMTP settings configured: @settings', [
      '@settings' => json_encode([
        'host' => $mailer->Host,
        'port' => $mailer->Port,
        'secure' => $mailer->SMTPSecure,
        'auth' => $mailer->SMTPAuth,
        'username' => $mailer->Username,
        'from' => $mailer->From,
        'from_name' => $mailer->FromName,
        'sender' => $mailer->Sender,
      ]),
    ]);
  }

}
