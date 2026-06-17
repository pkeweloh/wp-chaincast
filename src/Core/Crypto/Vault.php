<?php
/**
 * Cifrado en reposo de secretos (la posting key) con AES-256-GCM.
 *
 * La clave de cifrado NO vive en la base de datos: se deriva de una constante
 * definida en `wp-config.php` (`CHAINCAST_KEY`). Sin esa constante,
 * el modo automático queda deshabilitado y el plugin cae al modo asistido
 * (firma en el navegador, sin claves en el servidor).
 *
 * Formato del payload (base64): versión(1) || iv(12) || tag(16) || ciphertext.
 * GCM aporta autenticación: cualquier manipulación del dato hace fallar el descifrado.
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

    /** Clave de 32 bytes derivada del secreto maestro. */
    private string $key;

    public function __construct( string $masterSecret ) {
        if ( '' === $masterSecret ) {
            throw new RuntimeException( 'El secreto maestro del Vault no puede estar vacío.' );
        }
        // Derivación a 32 bytes; admite un secreto de cualquier longitud.
        $this->key = hash( 'sha256', $masterSecret, true );
    }

    /**
     * ¿Está definida la constante de `wp-config.php` para habilitar el modo automático?
     */
    public static function isConfigured(): bool {
        return defined( self::CONST_NAME ) && '' !== (string) constant( self::CONST_NAME );
    }

    /**
     * Crea el Vault desde la constante de `wp-config.php`, o null si no está disponible.
     */
    public static function fromWpConfig(): ?self {
        if ( ! self::isConfigured() ) {
            return null;
        }
        return new self( (string) constant( self::CONST_NAME ) );
    }

    /**
     * Cifra un texto plano y devuelve el payload en base64.
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
     * Descifra un payload generado por encrypt(). Lanza excepción si está
     * manipulado, truncado o cifrado con otra clave.
     */
    public function decrypt( string $payload ): string {
        $raw = base64_decode( $payload, true );
        if ( false === $raw || strlen( $raw ) < 1 + self::IV_LEN + self::TAG_LEN ) {
            throw new RuntimeException( 'Payload del Vault inválido o truncado.' );
        }

        $version = $raw[0];
        if ( self::VERSION !== $version ) {
            throw new RuntimeException( 'Versión de payload del Vault no soportada.' );
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
            throw new RuntimeException( 'Fallo al descifrar en el Vault (¿dato manipulado o clave incorrecta?).' );
        }

        return $plaintext;
    }
}
