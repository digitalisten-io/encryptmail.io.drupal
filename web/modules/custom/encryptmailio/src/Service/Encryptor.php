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
   * Constructs a new Encryptor object.
   *
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(LoggerChannelFactoryInterface $logger_factory) {
    $this->loggerFactory = $logger_factory;
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
    // Create a temporary file for the message.
    $messageFile = $this->createTempFile($message);
    $certFile = $this->createTempFile($certificate);
    $outputFile = tempnam(sys_get_temp_dir(), 'encrypted_');

    try {
      // Encrypt using OpenSSL.
      $command = sprintf(
        'openssl smime -encrypt -aes256 -in %s -out %s %s',
        escapeshellarg($messageFile),
        escapeshellarg($outputFile),
        escapeshellarg($certFile)
      );

      $output = [];
      $returnVar = 0;
      exec($command, $output, $returnVar);

      if ($returnVar !== 0) {
        throw new \Exception('S/MIME encryption failed: ' . implode("\n", $output));
      }

      $encrypted = file_get_contents($outputFile);
      if ($encrypted === FALSE) {
        throw new \Exception('Failed to read encrypted message');
      }

      return $encrypted;
    }
    finally {
      // Clean up temporary files.
      @unlink($messageFile);
      @unlink($certFile);
      @unlink($outputFile);
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
    // Create temporary files for the message and key.
    $messageFile = $this->createTempFile($message);
    $keyFile = $this->createTempFile($public_key);
    $outputFile = tempnam(sys_get_temp_dir(), 'encrypted_');

    try {
      // Import the public key.
      $importCommand = sprintf(
        'gpg --batch --import %s 2>&1',
        escapeshellarg($keyFile)
      );
      exec($importCommand);

      // Get the key ID from the imported key.
      $listCommand = sprintf(
        'gpg --with-colons --list-keys --with-fingerprint < %s',
        escapeshellarg($keyFile)
      );
      $keyInfo = [];
      exec($listCommand, $keyInfo);

      // Extract the key ID from the output.
      $keyId = '';
      foreach ($keyInfo as $line) {
        if (strpos($line, 'pub:') === 0) {
          $parts = explode(':', $line);
          $keyId = $parts[4];
          break;
        }
      }

      if (empty($keyId)) {
        throw new \Exception('Could not determine PGP key ID');
      }

      // Encrypt the message.
      $command = sprintf(
        'gpg --batch --trust-model always --encrypt --recipient %s --output %s %s 2>&1',
        escapeshellarg($keyId),
        escapeshellarg($outputFile),
        escapeshellarg($messageFile)
      );

      $output = [];
      $returnVar = 0;
      exec($command, $output, $returnVar);

      if ($returnVar !== 0) {
        throw new \Exception('PGP encryption failed: ' . implode("\n", $output));
      }

      $encrypted = file_get_contents($outputFile);
      if ($encrypted === FALSE) {
        throw new \Exception('Failed to read encrypted message');
      }

      return $encrypted;
    }
    finally {
      // Clean up temporary files.
      @unlink($messageFile);
      @unlink($keyFile);
      @unlink($outputFile);
    }
  }

  /**
   * Creates a temporary file with the given content.
   *
   * @param string $content
   *   The content to write to the file.
   *
   * @return string
   *   The path to the temporary file.
   *
   * @throws \Exception
   *   If the file cannot be created.
   */
  protected function createTempFile(string $content): string {
    $tempFile = tempnam(sys_get_temp_dir(), 'encrypt_');
    if ($tempFile === FALSE) {
      throw new \Exception('Failed to create temporary file');
    }

    if (file_put_contents($tempFile, $content) === FALSE) {
      throw new \Exception('Failed to write to temporary file');
    }

    return $tempFile;
  }

}
