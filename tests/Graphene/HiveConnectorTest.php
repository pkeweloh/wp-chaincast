<?php
/**
 * HiveConnector publish dry-run: exercises publish() end to end against a fake
 * transport (no network) and checks that the transaction it would broadcast is
 * well-formed and validly signed.
 *
 * @package Chaincast\Tests\Graphene
 */

declare(strict_types=1);

namespace Chaincast\Tests\Graphene;

use PHPUnit\Framework\TestCase;
use Chaincast\Connector\Content\JsonMetadata;
use Chaincast\Connector\Content\PermlinkGenerator;
use Chaincast\Connector\Graphene\GrapheneConfig;
use Chaincast\Connector\Graphene\HiveConnector;
use Chaincast\Connector\Graphene\PublicKey;
use Chaincast\Connector\Graphene\Serializer;
use Chaincast\Connector\PostPayload;
use Chaincast\Core\Crypto\Secp256k1;
use Chaincast\Core\Crypto\Vault;
use Chaincast\Core\Rpc\RpcClient;
use Chaincast\Tests\Support\FakeTransport;

final class HiveConnectorTest extends TestCase {

    private const CHAIN_ID = 'beeab0de00000000000000000000000000000000000000000000000000000000';
    private const NODE     = 'https://node';

    /** @var array<string,mixed> */
    private static array $meta;

    public static function setUpBeforeClass(): void {
        $v          = json_decode( (string) file_get_contents( __DIR__ . '/../fixtures/golden-vectors.json' ), true, 512, JSON_THROW_ON_ERROR );
        self::$meta = $v['meta'];
    }

    public function testPublishProducesValidSignedTransaction(): void {
        // Arrange
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
        $connector = new HiveConnector(
            new GrapheneConfig(
                author: 'demo-author',
                encryptedPostingKey: $vault->encrypt( self::$meta['test_priv_wif'] ),
                defaultTag: 'blog',
                nodes: [ self::NODE ],
            ),
            new RpcClient( [ self::NODE ], $transport ),
            $vault,
            new Secp256k1(),
            new PermlinkGenerator(),
            new JsonMetadata(),
        );

        $payload = new PostPayload(
            title: 'Hola mundo',
            body: "# Hola\n\nCuerpo en markdown.",
            tags: [ 'Hive-167922', 'wordpress' ],
            images: [ 'https://example.com/img.png' ],
            author: 'demo-author',
            canonicalUrl: 'https://example.com/hola-mundo',
            wpPostId: 42,
        );

        // Act
        $result = $connector->publish( $payload );

        // Assert
        $this->assertTrue( $result->success, $result->error ?? '' );
        $this->assertSame( 'hola-mundo', $result->ref );
        $this->assertSame( 'https://hive.blog/@demo-author/hola-mundo', $result->url );
        $this->assertMatchesRegularExpression( '/^[0-9a-f]{40}$/', (string) $result->txId );

        // Captured transaction
        $this->assertNotNull( $captured, 'No transaction was emitted.' );
        $op = $captured['operations'][0];
        $this->assertSame( 'comment', $op[0] );
        $this->assertSame( 'demo-author', $op[1]['author'] );
        $this->assertSame( 'hola-mundo', $op[1]['permlink'] );
        $this->assertSame( 'hive-167922', $op[1]['parent_permlink'] );
        $this->assertSame( '', $op[1]['parent_author'] );
        $this->assertCount( 1, $captured['signatures'] );

        // The signature is valid for the reconstructed digest
        $serializer = new Serializer();
        $serializer->transaction(
            $captured['ref_block_num'],
            $captured['ref_block_prefix'],
            $captured['expiration'],
            [ [ 'comment', $op[1] ] ]
        );
        $digest = hash( 'sha256', hex2bin( self::CHAIN_ID . $serializer->hex() ) );
        $pubHex = PublicKey::fromPrivateHex( self::$meta['test_priv_hex'] )->compressedHex();

        $this->assertSame(
            $pubHex,
            ( new Secp256k1() )->recoverPublic( $digest, $captured['signatures'][0] ),
            'The emitted signature does not recover the signer key.'
        );
    }

    public function testPublishWithBeneficiariesAppendsCommentOptions(): void {
        // Arrange
        $captured  = null;
        $connector = $this->connectorCapturing( $captured );

        $payload = new PostPayload(
            title: 'Con reparto',
            body: 'Cuerpo.',
            tags: [ 'blog' ],
            images: [],
            author: 'demo-author',
            canonicalUrl: 'https://example.com/con-reparto',
            wpPostId: 77,
            beneficiaries: [
                [ 'account' => 'algun-proyecto', 'weight' => 1000 ],
                [ 'account' => 'un-curador', 'weight' => 500 ],
            ],
        );

        // Act
        $result = $connector->publish( $payload );

        // Assert
        $this->assertTrue( $result->success, $result->error ?? '' );

        $ops = $captured['operations'];
        $this->assertCount( 2, $ops, 'Must emit comment + comment_options.' );
        $this->assertSame( 'comment', $ops[0][0] );
        $this->assertSame( 'comment_options', $ops[1][0] );

        $co = $ops[1][1];
        $this->assertSame( 'demo-author', $co['author'] );
        $this->assertSame( $result->ref, $co['permlink'] );
        $this->assertSame( '1000000.000 HBD', $co['max_accepted_payout'] );
        $this->assertSame( 10000, $co['percent_hbd'] );
        $this->assertTrue( $co['allow_votes'] );
        $this->assertSame(
            [ [ 'account' => 'algun-proyecto', 'weight' => 1000 ], [ 'account' => 'un-curador', 'weight' => 500 ] ],
            $co['extensions'][0][1]['beneficiaries']
        );

        // The signature must be valid over the TWO-op transaction.
        $serializer = new Serializer();
        $serializer->transaction(
            $captured['ref_block_num'],
            $captured['ref_block_prefix'],
            $captured['expiration'],
            [ [ 'comment', $ops[0][1] ], [ 'comment_options', $co ] ]
        );
        $digest = hash( 'sha256', hex2bin( self::CHAIN_ID . $serializer->hex() ) );
        $pubHex = PublicKey::fromPrivateHex( self::$meta['test_priv_hex'] )->compressedHex();
        $this->assertSame(
            $pubHex,
            ( new Secp256k1() )->recoverPublic( $digest, $captured['signatures'][0] ),
            'The beneficiaries tx signature does not recover the signer.'
        );
    }

    public function testEditWithBeneficiariesOmitsCommentOptions(): void {
        // Arrange
        $captured  = null;
        $connector = $this->connectorCapturing( $captured );

        // extra['permlink'] present => it is an edit of an existing post.
        $payload = new PostPayload(
            title: 'Editado',
            body: 'Cuerpo corregido.',
            tags: [ 'blog' ],
            images: [],
            author: 'demo-author',
            canonicalUrl: 'https://example.com/ya-existe',
            wpPostId: 77,
            beneficiaries: [ [ 'account' => 'algun-proyecto', 'weight' => 1000 ] ],
            extra: [ 'permlink' => 'ya-existe' ],
        );

        // Act
        $result = $connector->publish( $payload );

        // Assert
        $this->assertTrue( $result->success, $result->error ?? '' );
        $this->assertCount( 1, $captured['operations'], 'An edit must not include comment_options.' );
        $this->assertSame( 'comment', $captured['operations'][0][0] );
        $this->assertSame( 'ya-existe', $result->ref );
    }

    /**
     * Hive connector whose broadcast captures the emitted transaction in $captured.
     *
     * @param array<string,mixed>|null $captured
     */
    private function connectorCapturing( mixed &$captured ): HiveConnector {
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

        $vault = new Vault( 'test-secret' );
        return new HiveConnector(
            new GrapheneConfig(
                author: 'demo-author',
                encryptedPostingKey: $vault->encrypt( self::$meta['test_priv_wif'] ),
                defaultTag: 'blog',
                nodes: [ self::NODE ],
            ),
            new RpcClient( [ self::NODE ], $transport ),
            $vault,
            new Secp256k1(),
            new PermlinkGenerator(),
            new JsonMetadata(),
        );
    }

    public function testPublishFailsGracefullyWithoutPostingKey(): void {
        $connector = new HiveConnector(
            new GrapheneConfig( author: 'demo-author' ), // no posting key.
            new RpcClient( [ self::NODE ], new FakeTransport( [ self::NODE => FakeTransport::okBody( null ) ] ) ),
            new Vault( 'test-secret' ),
            new Secp256k1(),
            new PermlinkGenerator(),
            new JsonMetadata(),
        );

        $this->assertFalse( $connector->supportsAutomatic() );
        $result = $connector->publish( new PostPayload( 'T', 'B', [ 'blog' ], [], 'demo-author', '', 1 ) );
        $this->assertFalse( $result->success );
    }
}
