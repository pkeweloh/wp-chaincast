<?php
/**
 * Valida nuestra serialización graphene byte-a-byte contra los vectores golden
 * generados por el oráculo mahdiyari/hive-php.
 *
 * @package Chaincast\Tests\Graphene
 */

declare(strict_types=1);

namespace Chaincast\Tests\Graphene;

use PHPUnit\Framework\TestCase;
use Chaincast\Connector\Graphene\Serializer;

final class SerializerTest extends TestCase {

    /** @var array<string,mixed> */
    private static array $vectors;

    public static function setUpBeforeClass(): void {
        $json = file_get_contents( __DIR__ . '/../fixtures/golden-vectors.json' );
        self::assertNotFalse( $json, 'No se pudo leer golden-vectors.json' );
        self::$vectors = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
    }

    // ---- Primitivos hand-verified ----

    public function testVaruint32KnownValues(): void {
        $this->assertSame( '00', ( new Serializer() )->varuint32( 0 )->hex() );
        $this->assertSame( '7f', ( new Serializer() )->varuint32( 127 )->hex() );
        $this->assertSame( '8001', ( new Serializer() )->varuint32( 128 )->hex() );
        $this->assertSame( 'ac02', ( new Serializer() )->varuint32( 300 )->hex() );
        $this->assertSame( '9e01', ( new Serializer() )->varuint32( 158 )->hex() ); // longitud del json_metadata del vector.
    }

    public function testUintEndianness(): void {
        $this->assertSame( '2513', ( new Serializer() )->uint16( 4901 )->hex() );
        $this->assertSame( '89865678', ( new Serializer() )->uint32( 2018936457 )->hex() );
    }

    public function testStringIsBytePrefixed(): void {
        $this->assertSame( '0568656c6c6f', ( new Serializer() )->string( 'hello' )->hex() );
        // UTF-8: "ñ" son 2 bytes -> longitud 2.
        $this->assertSame( '02c3b1', ( new Serializer() )->string( 'ñ' )->hex() );
    }

    public function testDateIsUtcUint32(): void {
        // 2026-06-14T18:00:00 UTC.
        $expected = bin2hex( pack( 'V', strtotime( '2026-06-14T18:00:00 UTC' ) ) );
        $this->assertSame( $expected, ( new Serializer() )->date( '2026-06-14T18:00:00' )->hex() );
    }

    // ---- Vectores golden completos ----

    /**
     * @dataProvider commentVectors
     */
    public function testTransactionMatchesGolden( string $key ): void {
        $vector = self::$vectors[ $key ];
        $op     = $vector['operation'];

        $serializer = new Serializer();
        $serializer->transaction(
            $vector['tx']['ref_block_num'],
            $vector['tx']['ref_block_prefix'],
            $vector['tx']['expiration'],
            [ [ $op[0], $op[1] ] ]
        );

        $this->assertSame(
            $vector['serialized'],
            $serializer->hex(),
            "La serialización de '$key' no coincide con el vector golden."
        );
    }

    /**
     * El digest de firma debe ser sha256(chain_id_bytes + tx_serializada).
     *
     * @dataProvider commentVectors
     */
    public function testSigningDigestMatchesGolden( string $key ): void {
        $vector  = self::$vectors[ $key ];
        $chainId = self::$vectors['meta']['chain_id'];

        $digest = hash( 'sha256', hex2bin( $chainId . $vector['serialized'] ) );

        $this->assertSame( $vector['digest'], $digest, "Digest de '$key' incorrecto." );
    }

    /**
     * @return array<int,array{0:string}>
     */
    public static function commentVectors(): array {
        return [
            'comment_post'          => [ 'comment_post' ],
            'comment_utf8'          => [ 'comment_utf8' ],
            'comment_options_benef' => [ 'comment_options_benef' ],
        ];
    }
}
