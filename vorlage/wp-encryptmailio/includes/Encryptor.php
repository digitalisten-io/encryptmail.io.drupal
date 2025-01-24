<?php

/**
 * Class WPEncryptMailioEncryptor
 * Handles email encryption using both S/MIME and PGP methods.
 */
class WPEncryptMailioEncryptor {
    private $gnupg = null;

    /**
     * Constructor initializes GnuPG if the extension is available.
     */
    public function __construct() {
        // Initialize GnuPG if the extension is available
        if (extension_loaded('gnupg')) {
            $this->gnupg = new gnupg();
        }
    }

    /**
     * Logs error messages to a debug file.
     *
     * @param string $message The message to log
     */
    private function log($message) {
        error_log($message . "\n", 3, WP_CONTENT_DIR . '/smime-debug.log');
    }

    /**
     * Removes MIME headers from content, keeping only the body.
     *
     * @param string $content The content containing MIME headers
     * @return string The content without MIME headers
     */
    private function strip_mime_headers($content) {
        // Find the empty line that separates headers from content
        $parts = explode("\n\n", $content, 2);
        if (count($parts) == 2) {
            return trim($parts[1]); // Return only the content part
        }
        return $content;
    }

    /**
     * Encrypts a message using S/MIME encryption.
     *
     * @param string $message The message to encrypt
     * @param string $certificate The X.509 certificate to use for encryption
     * @return string The encrypted message
     * @throws Exception If encryption fails or certificate is invalid
     */
    public function encrypt_smime($message, $certificate) {
        try {
            // Verify certificate
            $cert = openssl_x509_read($certificate);
            if (!$cert) {
                throw new Exception('Invalid certificate: ' . openssl_error_string());
            }

            // Create temporary files
            $infile = tempnam(sys_get_temp_dir(), 'msg');
            $outfile = tempnam(sys_get_temp_dir(), 'enc');

            // Write message to temporary file
            file_put_contents($infile, $message);

            // Encrypt using S/MIME
            $result = openssl_pkcs7_encrypt(
                $infile,
                $outfile,
                $certificate,
                array(), // Empty headers array
                PKCS7_BINARY, // Only use PKCS7_BINARY flag
                OPENSSL_CIPHER_AES_256_CBC
            );

            if (!$result) {
                throw new Exception('Encryption failed: ' . openssl_error_string());
            }

            // Read encrypted content and strip MIME headers
            $encrypted = file_get_contents($outfile);
            $encrypted = $this->strip_mime_headers($encrypted);

            // Clean up
            unlink($infile);
            unlink($outfile);
            openssl_x509_free($cert);

            return $encrypted;

        } catch (Exception $e) {
            $this->log('encryptmail.io S/MIME encryption error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Encrypts a message using PGP encryption.
     *
     * @param string $message The message to encrypt
     * @param string $publicKey The PGP public key to use for encryption
     * @return string The encrypted message
     * @throws Exception If GnuPG extension is not installed or encryption fails
     */
    public function encrypt_pgp($message, $publicKey) {
        if (!extension_loaded('gnupg')) {
            throw new Exception('GnuPG extension is not installed');
        }

        try {
            // Import the public key
            $keyInfo = $this->gnupg->import($publicKey);
            if (empty($keyInfo['fingerprint'])) {
                throw new Exception('Failed to import PGP public key');
            }

            // Add the key for encryption
            $this->gnupg->addencryptkey($keyInfo['fingerprint']);

            // Encrypt the message
            $encrypted = $this->gnupg->encrypt($message);
            if ($encrypted === false) {
                throw new Exception('PGP encryption failed');
            }

            // Clear the encryption keys
            $this->gnupg->clearencryptkeys();

            return $encrypted;

        } catch (Exception $e) {
            $this->log('encryptmail.io PGP encryption error: ' . $e->getMessage());
            throw $e;
        }
    }
}
