<?php
/**
 * Offline integration test ("dry-run"): exercises the whole signing pipeline
 * without touching the network: build tx -> serialize -> digest -> sign -> verify.
 *
 * Shows that the pieces fit together and produce a transaction consistent with
 * the oracle's golden vectors.
 *
 * @package Chaincast\Tests
 */

declare(strict_types=1);

namespace Chaincast\Tests;

use PHPUnit\Framework\TestCase;
use Chaincast\Connector\Graphene\PrivateKey;
use Chaincast\Connector\Graphene\Serializer;
use Chaincast\Core\Crypto\Secp256k1;

final class IntegrationTest extends TestCase {

    public function testFullOfflineSigningPipeline(): void {
        $v       = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/golden-vectors.json' ), true, 512, JSON_THROW_ON_ERROR );
        $vector  = $v['comment_post'];
        $chainId = $v['meta']['chain_id'];

        // 1) Serialize the comment transaction with our implementation.
        $op  = $vector['operation'];
        $ser = new Serializer();
        $ser->transaction(
            $vector['tx']['ref_block_num'],
            $vector['tx']['ref_block_prefix'],
            $vector['tx']['expiration'],
            [ [ $op[0], $op[1] ] ]
        );

        $this->assertSame( $vector['serialized'], $ser->hex(), 'Serialization diverges from the vector.' );

        // 2) Digest = sha256(chain_id + tx).
        $digest = hash( 'sha256', hex2bin( $chainId . $ser->hex() ) );
        $this->assertSame( $vector['digest'], $digest, 'Digest diverges from the vector.' );

        // 3) Sign with the test WIF.
        $key     = PrivateKey::fromWif( $v['meta']['test_priv_wif'] );
        $compact = $key->signDigest( $digest );

        // 4) The signature must verify and recover the right public key.
        $signer = new Secp256k1();
        $pubHex = $key->publicKey()->compressedHex();

        $this->assertSame( 130, strlen( $compact ) );
        $this->assertTrue( $signer->verifyCompact( $digest, $compact, $pubHex ) );
        $this->assertSame( $pubHex, $signer->recoverPublic( $digest, $compact ) );

        // 5) The textual public key matches the oracle's.
        $this->assertSame( $v['meta']['test_pub_key'], $key->publicKey()->toString() );
    }
}
