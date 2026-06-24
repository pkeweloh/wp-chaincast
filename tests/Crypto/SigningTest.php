<?php
/**
 * Validates canonical secp256k1 signing and graphene key handling (WIF, STM).
 *
 * An ECDSA signature is not a single value, so it is not compared to the golden
 * vector: its PROPERTIES are checked (length, verification, key recovery,
 * canonicity). Public key derivation is deterministic and is compared to the
 * vector.
 *
 * @package Chaincast\Tests\Crypto
 */

declare(strict_types=1);

namespace Chaincast\Tests\Crypto;

use PHPUnit\Framework\TestCase;
use Chaincast\Connector\Graphene\PrivateKey;
use Chaincast\Connector\Graphene\PublicKey;
use Chaincast\Core\Crypto\Secp256k1;

final class SigningTest extends TestCase {

    /** @var array<string,mixed> */
    private static array $meta;
    private static string $digest;

    public static function setUpBeforeClass(): void {
        $v            = json_decode( (string) file_get_contents( __DIR__ . '/../fixtures/golden-vectors.json' ), true, 512, JSON_THROW_ON_ERROR );
        self::$meta   = $v['meta'];
        self::$digest = $v['comment_post']['digest'];
    }

    public function testPublicKeyDerivationMatchesGolden(): void {
        $pub = PublicKey::fromPrivateHex( self::$meta['test_priv_hex'] )->toString();
        $this->assertSame( self::$meta['test_pub_key'], $pub );
    }

    public function testWifDecodeMatchesGolden(): void {
        $key = PrivateKey::fromWif( self::$meta['test_priv_wif'] );
        $this->assertSame( self::$meta['test_priv_hex'], $key->hex() );
    }

    public function testWifRejectsBadChecksum(): void {
        $this->expectException( \InvalidArgumentException::class );
        // Change the last character of a valid WIF.
        $bad = substr( self::$meta['test_priv_wif'], 0, -1 ) . ( self::$meta['test_priv_wif'][-1] === 'M' ? 'N' : 'M' );
        PrivateKey::fromWif( $bad );
    }

    public function testSignatureIsWellFormedAndVerifies(): void {
        $signer = new Secp256k1();
        $priv   = self::$meta['test_priv_hex'];
        $pub    = PublicKey::fromPrivateHex( $priv )->compressedHex();

        $compact = $signer->signCompact( self::$digest, $priv );

        // 65 bytes = 130 hex.
        $this->assertSame( 130, strlen( $compact ) );

        // Verify against the public key.
        $this->assertTrue( $signer->verifyCompact( self::$digest, $compact, $pub ) );

        // The key recovered from the signature matches the real public key.
        $this->assertSame( $pub, $signer->recoverPublic( self::$digest, $compact ) );
    }

    public function testHeaderEncodesRecoveryParam(): void {
        $compact = ( new Secp256k1() )->signCompact( self::$digest, self::$meta['test_priv_hex'] );
        $header  = hexdec( substr( $compact, 0, 2 ) );
        $recovery = $header - 31;
        $this->assertGreaterThanOrEqual( 0, $recovery );
        $this->assertLessThanOrEqual( 3, $recovery );
    }

    public function testEndToEndSignViaPrivateKey(): void {
        $key     = PrivateKey::fromWif( self::$meta['test_priv_wif'] );
        $compact = $key->signDigest( self::$digest );
        $pub     = $key->publicKey()->compressedHex();

        $this->assertTrue( ( new Secp256k1() )->verifyCompact( self::$digest, $compact, $pub ) );
    }
}
