<?php
/**
 * Encryption at rest of secrets (the posting key) with AES-256-GCM.
 *
 * The encryption key does NOT live in the database: it is derived from a
 * constant defined in `wp-config.php` (`CHAINCAST_KEY`). Without that constant,
 * automatic mode is disabled and the plugin falls back to assisted mode (signing
 * in the browser, no keys on the server).
 *
 * Payload format (base64): version(1) || iv(12) || tag(16) || ciphertext.
 * GCM provides authentication: any tampering makes decryption fail.
 *
 * @package Chaincast\Core\Crypto
 */

declare(strict_types=1);

namespace Chaincast\Core\Crypto;

use RuntimeException;

final class Vault {

    private const CIPHER     = 'aes-256-gcm';
    private const VERSION    = "\x01";
    private const IV_LEN     = 12;
    private const TAG_LEN    = 16;
    private const CONST_NAME = 'CHAINCAST_KEY';

    /** 32-byte key derived from the master secret. */
    private string $key;

    public function __construct( string $masterSecret ) {
        if ( '' === $masterSecret ) {
            throw new RuntimeException( 'The Vault master secret cannot be empty.' );
        }
        // Derive to 32 bytes; accepts a secret of any length.
        $this->key = hash( 'sha256', $masterSecret, true );
    }

    /**
     * Is the `wp-config.php` constant defined to enable automatic mode?
     */
    public static function isConfigured(): bool {
        return defined( self::CONST_NAME ) && '' !== (string) constant( self::CONST_NAME );
    }

    /**
     * Creates the Vault from the `wp-config.php` constant, or null if unavailable.
     */
    public static function fromWpConfig(): ?self {
        if ( ! self::isConfigured() ) {
            return null;
        }
        return new self( (string) constant( self::CONST_NAME ) );
    }

    /**
     * Encrypts a plaintext and returns the payload in base64.
     */
    public function encrypt( string $plaintext ): string {
        $iv  = random_bytes( self::IV_LEN );
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LEN
        );

        if ( false === $ciphertext ) {
            throw new RuntimeException( 'Fallo al cifrar en el Vault.' );
        }

        return base64_encode( self::VERSION . $iv . $tag . $ciphertext );
    }

    /**
     * Decrypts a payload created by encrypt(). Throws if it has been tampered
     * with, truncated, or encrypted with a different key.
     */
    public function decrypt( string $payload ): string {
        $raw = base64_decode( $payload, true );
        if ( false === $raw || strlen( $raw ) < 1 + self::IV_LEN + self::TAG_LEN ) {
            throw new RuntimeException( 'Invalid or truncated Vault payload.' );
        }

        $version = $raw[0];
        if ( self::VERSION !== $version ) {
            throw new RuntimeException( 'Unsupported Vault payload version.' );
        }

        $iv         = substr( $raw, 1, self::IV_LEN );
        $tag        = substr( $raw, 1 + self::IV_LEN, self::TAG_LEN );
        $ciphertext = substr( $raw, 1 + self::IV_LEN + self::TAG_LEN );

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ( false === $plaintext ) {
            throw new RuntimeException( 'Vault decryption failed (tampered data or wrong key?).' );
        }

        return $plaintext;
    }
}
