<?php
/**
 * Clave privada graphene en formato WIF (posting key de Hive/Steem).
 *
 * WIF = base58check( 0x80 || clave(32) ), con checksum de doble SHA-256 (4 bytes).
 * Esta clase no firma directamente: delega en Secp256k1, manteniendo separada la
 * criptografía pura del formato de clave de la cadena.
 *
 * @package Chaincast\Connector\Graphene
 */

declare(strict_types=1);

namespace Chaincast\Connector\Graphene;

use InvalidArgumentException;
use StephenHill\Base58;
use Chaincast\Core\Crypto\Secp256k1;

final class PrivateKey {

    /** Clave privada en hex (32 bytes). */
    private string $hex;

    private function __construct( string $hex ) {
        $this->hex = $hex;
    }

    /** Crea desde clave hex de 32 bytes (64 caracteres). */
    public static function fromHex( string $hex ): self {
        if ( ! preg_match( '/^[0-9a-fA-F]{64}$/', $hex ) ) {
            throw new InvalidArgumentException( 'La clave privada hex debe tener 64 caracteres.' );
        }
        return new self( strtolower( $hex ) );
    }

    /** Crea desde una WIF (la posting key tal cual la da el monedero). */
    public static function fromWif( string $wif ): self {
        $decoded = ( new Base58() )->decode( $wif );

        if ( strlen( $decoded ) !== 37 ) {
            throw new InvalidArgumentException( 'WIF inválida: longitud inesperada.' );
        }

        $payload  = substr( $decoded, 0, 33 );   // 0x80 + clave(32).
        $checksum = substr( $decoded, 33, 4 );

        $expected = substr( hex2bin( hash( 'sha256', hex2bin( hash( 'sha256', $payload ) ) ) ), 0, 4 );
        if ( ! hash_equals( $expected, $checksum ) ) {
            throw new InvalidArgumentException( 'WIF inválida: checksum incorrecto.' );
        }

        if ( "\x80" !== $payload[0] ) {
            throw new InvalidArgumentException( 'WIF inválida: prefijo de red incorrecto.' );
        }

        return new self( bin2hex( substr( $payload, 1, 32 ) ) );
    }

    public function hex(): string {
        return $this->hex;
    }

    public function publicKey( string $prefix = 'STM' ): PublicKey {
        return PublicKey::fromPrivateHex( $this->hex, $prefix );
    }

    /**
     * Firma un digest (hex de 32 bytes) y devuelve la firma compacta (hex, 65 bytes).
     */
    public function signDigest( string $digestHex, ?Secp256k1 $signer = null ): string {
        return ( $signer ?? new Secp256k1() )->signCompact( $digestHex, $this->hex );
    }
}
