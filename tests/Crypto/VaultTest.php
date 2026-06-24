<?php
/**
 * Validates the Vault's AES-256-GCM encryption: round-trip, IV randomness,
 * authentication (tamper detection) and per-key isolation.
 *
 * @package Chaincast\Tests\Crypto
 */

declare(strict_types=1);

namespace Chaincast\Tests\Crypto;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Chaincast\Core\Crypto\Vault;

final class VaultTest extends TestCase {

    private const SECRET = 'una-constante-larga-de-wp-config-32+chars-xyz';
    private const PLAIN  = '5KTkhhHjNnRi4LtyFTYKRPCehoDfSHUsaxNXzE4ASYxH6KQGybM'; // an example WIF.

    public function testRoundTrip(): void {
        $vault = new Vault( self::SECRET );
        $this->assertSame( self::PLAIN, $vault->decrypt( $vault->encrypt( self::PLAIN ) ) );
    }

    public function testCiphertextIsRandomizedPerEncryption(): void {
        $vault = new Vault( self::SECRET );
        $this->assertNotSame( $vault->encrypt( self::PLAIN ), $vault->encrypt( self::PLAIN ) );
    }

    public function testTamperedPayloadFails(): void {
        $vault   = new Vault( self::SECRET );
        $payload = $vault->encrypt( self::PLAIN );

        // Alter one byte of the ciphertext (decode, mutate, re-encode).
        $raw            = base64_decode( $payload, true );
        $raw[ strlen( $raw ) - 1 ] = $raw[ strlen( $raw ) - 1 ] === 'A' ? 'B' : 'A';
        $tampered       = base64_encode( $raw );

        $this->expectException( RuntimeException::class );
        $vault->decrypt( $tampered );
    }

    public function testWrongKeyCannotDecrypt(): void {
        $payload = ( new Vault( self::SECRET ) )->encrypt( self::PLAIN );

        $this->expectException( RuntimeException::class );
        ( new Vault( 'otro-secreto-distinto' ) )->decrypt( $payload );
    }

    public function testEmptySecretRejected(): void {
        $this->expectException( RuntimeException::class );
        new Vault( '' );
    }
}
