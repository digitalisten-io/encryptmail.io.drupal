<?php

namespace Drupal\encryptmailio\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Provides encryption functionality for emails.
 */
class Encryptor {

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * The GnuPG instance.
   *
   * @var \gnupg|null
   */
  protected $gnupg = NULL;

  /**
   * Constructs a new Encryptor object.
   *
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(LoggerChannelFactoryInterface $logger_factory) {
    $this->loggerFactory = $logger_factory;
    // Initialize GnuPG if the extension is available.
    if (extension_loaded('gnupg')) {
      $this->gnupg = new \gnupg();
    }
  }

  /**
   * Encrypts a message using the specified encryption type and key.
   *
   * @param string $message
   *   The message to encrypt.
   * @param string $key
   *   The encryption key.
   * @param string $type
   *   The encryption type (smime or pgp).
   *
   * @return string
   *   The encrypted message.
   *
   * @throws \Exception
   *   If encryption fails.
   */
  public function encrypt(string $message, string $key, string $type): string {
    switch ($type) {
      case 'smime':
        return $this->encryptSmime($message, $key);

      case 'pgp':
        return $this->encryptPgp($message, $key);

      default:
        throw new \InvalidArgumentException("Unsupported encryption type: $type");
    }
  }

  /**
   * Encrypts a message using S/MIME.
   *
   * @param string $message
   *   The message to encrypt.
   * @param string $certificate
   *   The S/MIME certificate.
   *
   * @return string
   *   The encrypted message.
   *
   * @throws \Exception
   *   If encryption fails.
   */
  protected function encryptSmime(string $message, string $certificate): string {
    try {
      // Verify certificate.
      $cert = openssl_x509_read($certificate);
      if (!$cert) {
        throw new \Exception('Invalid certificate: ' . openssl_error_string());
      }

      // Create temporary files.
      $infile = tempnam(sys_get_temp_dir(), 'msg');
      $outfile = tempnam(sys_get_temp_dir(), 'enc');

      // Create MIME message.
      $mime_message = "MIME-Version: 1.0\n";
      $mime_message .= "Content-Type: text/plain; charset=UTF-8\n";
      $mime_message .= "Content-Transfer-Encoding: 7bit\n\n";
      $mime_message .= $message;

      // Write message to temporary file.
      file_put_contents($infile, $mime_message);

      // Encrypt using S/MIME.
      $result = openssl_pkcs7_encrypt(
        $infile,
        $outfile,
        $certificate,
      // Empty headers array.
        [],
      // Use both flags.
        PKCS7_BINARY | PKCS7_DETACHED,
        OPENSSL_CIPHER_AES_256_CBC
      );

      if (!$result) {
        throw new \Exception('Encryption failed: ' . openssl_error_string());
      }

      // Read encrypted content.
      $encrypted = file_get_contents($outfile);
      if ($encrypted === FALSE) {
        throw new \Exception('Failed to read encrypted content');
      }

      // Clean up.
      unlink($infile);
      unlink($outfile);
      if ($cert) {
        @openssl_x509_free($cert);
      }

      // Format the encrypted message with proper MIME headers.
      $formatted = "MIME-Version: 1.0\n";
      $formatted .= "Content-Type: application/x-pkcs7-mime; protocol=\"application/x-pkcs7-mime\"; smimetype=enveloped-data; name=\"smime.p7m\"\n";
      $formatted .= "Content-Transfer-Encoding: base64\n";
      $formatted .= "Content-Disposition: attachment; filename=\"smime.p7m\"\n\n";
      $formatted .= chunk_split(base64_encode($encrypted));

      return $formatted;
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('encryptmailio')->error(
        'S/MIME encryption error: @error',
        ['@error' => $e->getMessage()]
      );
      throw $e;
    }
  }

  /**
   * Encrypts a message using PGP.
   *
   * @param string $message
   *   The message to encrypt.
   * @param string $public_key
   *   The PGP public key.
   *
   * @return string
   *   The encrypted message.
   *
   * @throws \Exception
   *   If encryption fails.
   */
  protected function encryptPgp(string $message, string $public_key): string {
    if (!extension_loaded('gnupg')) {
      throw new \Exception('GnuPG extension is not installed');
    }

    try {
      // Import the public key.
      $keyInfo = $this->gnupg->import($public_key);
      if (empty($keyInfo['fingerprint'])) {
        throw new \Exception('Failed to import PGP public key');
      }

      // Add the key for encryption.
      $this->gnupg->addencryptkey($keyInfo['fingerprint']);

      // Encrypt the message.
      $encrypted = $this->gnupg->encrypt($message);
      if ($encrypted === FALSE) {
        throw new \Exception('PGP encryption failed');
      }

      // Clear the encryption keys.
      $this->gnupg->clearencryptkeys();

      return $encrypted;
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('encryptmailio')->error(
        'PGP encryption error: @error',
        ['@error' => $e->getMessage()]
      );
      throw $e;
    }
  }

  /**
   * Removes MIME headers from content, keeping only the body.
   *
   * @param string $content
   *   The content containing MIME headers.
   *
   * @return string
   *   The content without MIME headers.
   */
  protected function stripMimeHeaders(string $content): string {
    // Find the empty line that separates headers from content.
    $parts = explode("\n\n", $content, 2);
    if (count($parts) == 2) {
      // Return only the content part.
      return trim($parts[1]);
    }
    return $content;
  }

}
