<?php
/**
 * Prueba de integración offline ("dry-run"): ejercita toda la tubería de firma
 * sin tocar la red — construir tx -> serializar -> digest -> firmar -> verificar.
 *
 * Demuestra que las piezas de la Fase 1 encajan y producen una transacción
 * coherente con los vectores golden del oráculo.
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

        // 1) Serializar la transacción comment con nuestra implementación.
        $op  = $vector['operation'];
        $ser = new Serializer();
        $ser->transaction(
            $vector['tx']['ref_block_num'],
            $vector['tx']['ref_block_prefix'],
            $vector['tx']['expiration'],
            [ [ $op[0], $op[1] ] ]
        );

        $this->assertSame( $vector['serialized'], $ser->hex(), 'Serialización divergente del vector.' );

        // 2) Digest = sha256(chain_id + tx).
        $digest = hash( 'sha256', hex2bin( $chainId . $ser->hex() ) );
        $this->assertSame( $vector['digest'], $digest, 'Digest divergente del vector.' );

        // 3) Firmar con la WIF de prueba.
        $key     = PrivateKey::fromWif( $v['meta']['test_priv_wif'] );
        $compact = $key->signDigest( $digest );

        // 4) La firma debe verificar y recuperar la clave pública correcta.
        $signer = new Secp256k1();
        $pubHex = $key->publicKey()->compressedHex();

        $this->assertSame( 130, strlen( $compact ) );
        $this->assertTrue( $signer->verifyCompact( $digest, $compact, $pubHex ) );
        $this->assertSame( $pubHex, $signer->recoverPublic( $digest, $compact ) );

        // 5) La clave pública textual coincide con la del oráculo.
        $this->assertSame( $v['meta']['test_pub_key'], $key->publicKey()->toString() );
    }
}
