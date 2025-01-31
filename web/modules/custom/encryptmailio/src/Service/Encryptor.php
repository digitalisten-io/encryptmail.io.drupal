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
   * @var \GnuPG|null
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
    // Initialize GnuPG if available.
    if (extension_loaded('gnupg') && class_exists('GnuPG')) {
      $this->loggerFactory->get('encryptmailio')->info('GnuPG extension is available');
      $this->gnupg = new \GnuPG();
    }
    else {
      $this->loggerFactory->get('encryptmailio')->warning('GnuPG extension is not available');
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
    $this->loggerFactory->get('encryptmailio')->info(
      'Starting encryption process with type: @type',
      ['@type' => $type]
    );

    try {
      $result = match ($type) {
        'smime' => $this->encryptSmime($message, $key),
        'pgp' => $this->encryptPgp($message, $key),
        default => throw new \InvalidArgumentException("Unsupported encryption type: $type"),
      };

      $this->loggerFactory->get('encryptmailio')->info(
        'Successfully encrypted message using @type',
        ['@type' => $type]
      );

      return $result;
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('encryptmailio')->error(
        'Encryption failed with type @type: @error',
        [
          '@type' => $type,
          '@error' => $e->getMessage(),
        ]
      );
      throw $e;
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
    $this->loggerFactory->get('encryptmailio')->debug(
      'Starting S/MIME encryption with message length: @length',
      ['@length' => strlen($message)]
    );

    try {
      // Verify certificate.
      $cert = openssl_x509_read($certificate);
      if (!$cert) {
        $error = openssl_error_string();
        $this->loggerFactory->get('encryptmailio')->error(
          'Invalid certificate: @error',
          ['@error' => $error]
        );
        throw new \Exception('Invalid certificate: ' . $error);
      }

      $this->loggerFactory->get('encryptmailio')->debug('Certificate validated successfully');

      // Create temporary files.
      $infile = tempnam(sys_get_temp_dir(), 'msg');
      $outfile = tempnam(sys_get_temp_dir(), 'enc');

      $this->loggerFactory->get('encryptmailio')->debug(
        'Created temporary files: @in, @out',
        [
          '@in' => $infile,
          '@out' => $outfile,
        ]
      );

      // Write message to temporary file.
      file_put_contents($infile, $message);

      // Encrypt using S/MIME.
      $result = openssl_pkcs7_encrypt(
        $infile,
        $outfile,
        $certificate,
      // Empty headers array.
        [],
      // Use binary encoding.
        PKCS7_BINARY,
        OPENSSL_CIPHER_AES_256_CBC
      );

      if (!$result) {
        $error = openssl_error_string();
        $this->loggerFactory->get('encryptmailio')->error(
          'S/MIME encryption failed: @error',
          ['@error' => $error]
        );
        throw new \Exception('Encryption failed: ' . $error);
      }

      $this->loggerFactory->get('encryptmailio')->debug('S/MIME encryption completed successfully');

      // Read encrypted content.
      $encrypted = file_get_contents($outfile);
      if ($encrypted === FALSE) {
        throw new \Exception('Failed to read encrypted content');
      }

      $this->loggerFactory->get('encryptmailio')->debug(
        'Read encrypted content, length: @length',
        ['@length' => strlen($encrypted)]
      );

      // Clean up.
      unlink($infile);
      unlink($outfile);
      if ($cert) {
        // PHP 8.0+ doesn't require explicit freeing of the certificate.
        if (version_compare(PHP_VERSION, '8.0.0', '<')) {
          @openssl_x509_free($cert);
        }
      }

      // Return base64 encoded encrypted content.
      $encoded = base64_encode($encrypted);
      $this->loggerFactory->get('encryptmailio')->debug(
        'Returning base64 encoded content, length: @length',
        ['@length' => strlen($encoded)]
      );

      return $encoded;
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
    $this->loggerFactory->get('encryptmailio')->debug(
      'Starting PGP encryption with message length: @length',
      ['@length' => strlen($message)]
    );

    if (!extension_loaded('gnupg')) {
      $this->loggerFactory->get('encryptmailio')->error('GnuPG extension is not installed');
      throw new \Exception('GnuPG extension is not installed');
    }

    try {
      // Import the public key.
      $keyInfo = $this->gnupg->import($public_key);
      if (empty($keyInfo['fingerprint'])) {
        $this->loggerFactory->get('encryptmailio')->error('Failed to import PGP public key');
        throw new \Exception('Failed to import PGP public key');
      }

      $this->loggerFactory->get('encryptmailio')->debug(
        'Imported PGP key with fingerprint: @fingerprint',
        ['@fingerprint' => $keyInfo['fingerprint']]
      );

      // Add the key for encryption.
      $this->gnupg->addencryptkey($keyInfo['fingerprint']);

      // Encrypt the message.
      $encrypted = $this->gnupg->encrypt($message);
      if ($encrypted === FALSE) {
        $this->loggerFactory->get('encryptmailio')->error('PGP encryption failed');
        throw new \Exception('PGP encryption failed');
      }

      $this->loggerFactory->get('encryptmailio')->debug(
        'PGP encryption completed, result length: @length',
        ['@length' => strlen($encrypted)]
      );

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
