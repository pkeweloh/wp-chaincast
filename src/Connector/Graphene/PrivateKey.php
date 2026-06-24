<?php
/**
 * Graphene private key in WIF format (Hive/Steem posting key).
 *
 * WIF = base58check( 0x80 || key(32) ), with a double SHA-256 checksum (4 bytes).
 * This class does not sign directly: it delegates to Secp256k1, keeping the pure
 * cryptography separate from the chain's key format.
 *
 * @package Chaincast\Connector\Graphene
 */

declare(strict_types=1);

namespace Chaincast\Connector\Graphene;

use InvalidArgumentException;
use StephenHill\Base58;
use Chaincast\Core\Crypto\Secp256k1;

final class PrivateKey {

    /** Private key in hex (32 bytes). */
    private string $hex;

    private function __construct( string $hex ) {
        $this->hex = $hex;
    }

    /** Creates from a 32-byte hex key (64 characters). */
    public static function fromHex( string $hex ): self {
        if ( ! preg_match( '/^[0-9a-fA-F]{64}$/', $hex ) ) {
            throw new InvalidArgumentException( 'The hex private key must be 64 characters.' );
        }
        return new self( strtolower( $hex ) );
    }

    /** Creates from a WIF (the posting key as the wallet provides it). */
    public static function fromWif( string $wif ): self {
        $decoded = ( new Base58() )->decode( $wif );

        if ( strlen( $decoded ) !== 37 ) {
            throw new InvalidArgumentException( 'Invalid WIF: unexpected length.' );
        }

        $payload  = substr( $decoded, 0, 33 );   // 0x80 + key(32).
        $checksum = substr( $decoded, 33, 4 );

        $expected = substr( hex2bin( hash( 'sha256', hex2bin( hash( 'sha256', $payload ) ) ) ), 0, 4 );
        if ( ! hash_equals( $expected, $checksum ) ) {
            throw new InvalidArgumentException( 'Invalid WIF: bad checksum.' );
        }

        if ( "\x80" !== $payload[0] ) {
            throw new InvalidArgumentException( 'Invalid WIF: wrong network prefix.' );
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
     * Signs a digest (32-byte hex) and returns the compact signature (hex, 65 bytes).
     */
    public function signDigest( string $digestHex, ?Secp256k1 $signer = null ): string {
        return ( $signer ?? new Secp256k1() )->signCompact( $digestHex, $this->hex );
    }
}
