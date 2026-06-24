<?php
/**
 * SteemConnector dry-run: confirms it uses Steem's chain_id and URL and that the
 * emitted transaction is validly signed (no network).
 *
 * @package Chaincast\Tests\Graphene
 */

declare(strict_types=1);

namespace Chaincast\Tests\Graphene;

use PHPUnit\Framework\TestCase;
use Chaincast\Connector\Content\JsonMetadata;
use Chaincast\Connector\Content\PermlinkGenerator;
use Chaincast\Connector\Graphene\GrapheneConfig;
use Chaincast\Connector\Graphene\PublicKey;
use Chaincast\Connector\Graphene\Serializer;
use Chaincast\Connector\Graphene\SteemConnector;
use Chaincast\Connector\PostPayload;
use Chaincast\Core\Crypto\Secp256k1;
use Chaincast\Core\Crypto\Vault;
use Chaincast\Core\Rpc\RpcClient;
use Chaincast\Tests\Support\FakeTransport;

final class SteemConnectorTest extends TestCase {

    private const STEEM_CHAIN_ID = '0000000000000000000000000000000000000000000000000000000000000000';
    private const NODE           = 'https://steem-node';

    /** @var array<string,mixed> */
    private static array $meta;

    public static function setUpBeforeClass(): void {
        $v          = json_decode( (string) file_get_contents( __DIR__ . '/../fixtures/golden-vectors.json' ), true, 512, JSON_THROW_ON_ERROR );
        self::$meta = $v['meta'];
    }

    public function testPublishUsesSteemChainAndUrl(): void {
        $captured = null;

        $transport = new FakeTransport(
            [
                self::NODE => static function ( string $body ) use ( &$captured ): array {
                    $req = json_decode( $body, true );
                    return match ( $req['method'] ) {
                        'condenser_api.get_dynamic_global_properties' => FakeTransport::okBody(
                            [
                                'head_block_number' => 4901,
                                'head_block_id'     => '0000132589865678' . str_repeat( '0', 24 ),
                                'time'              => '2026-06-14T18:00:00',
                            ]
                        ),
                        'condenser_api.broadcast_transaction' => ( function () use ( $req, &$captured ): array {
                            $captured = $req['params'][0];
                            return FakeTransport::okBody( null );
                        } )(),
                        default => FakeTransport::okBody( null ),
                    };
                },
            ]
        );

        $vault     = new Vault( 'test-secret' );
        $connector = new SteemConnector(
            new GrapheneConfig( author: 'skunk1', encryptedPostingKey: $vault->encrypt( self::$meta['test_priv_wif'] ), defaultTag: 'blog', nodes: [ self::NODE ] ),
            new RpcClient( [ self::NODE ], $transport ),
            $vault,
            new Secp256k1(),
            new PermlinkGenerator(),
            new JsonMetadata(),
        );

        $payload = new PostPayload(
            title: 'Hola Steem',
            body: 'Cuerpo.',
            tags: [ 'blog' ],
            images: [],
            author: 'skunk1',
            canonicalUrl: '',
            wpPostId: 5,
        );

        $result = $connector->publish( $payload );

        $this->assertTrue( $result->success, $result->error ?? '' );
        $this->assertSame( 'https://steemit.com/@skunk1/hola-steem', $result->url );

        // The signature must validate against STEEM's chain_id (not Hive's).
        $op         = $captured['operations'][0];
        $serializer = new Serializer();
        $serializer->transaction( $captured['ref_block_num'], $captured['ref_block_prefix'], $captured['expiration'], [ [ 'comment', $op[1] ] ] );
        $digest = hash( 'sha256', hex2bin( self::STEEM_CHAIN_ID . $serializer->hex() ) );
        $pubHex = PublicKey::fromPrivateHex( self::$meta['test_priv_hex'] )->compressedHex();

        $this->assertSame(
            $pubHex,
            ( new Secp256k1() )->recoverPublic( $digest, $captured['signatures'][0] ),
            'The signature does not validate against Steem chain_id.'
        );
    }
}
