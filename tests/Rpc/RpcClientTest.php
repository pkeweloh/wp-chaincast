<?php
/**
 * Valida el failover del cliente JSON-RPC y el cálculo del bloque de referencia,
 * usando un transporte HTTP falso (sin red).
 *
 * @package Chaincast\Tests\Rpc
 */

declare(strict_types=1);

namespace Chaincast\Tests\Rpc;

use PHPUnit\Framework\TestCase;
use Chaincast\Core\Rpc\RpcClient;
use Chaincast\Core\Rpc\RpcException;
use Chaincast\Core\Rpc\TransportException;
use Chaincast\Tests\Support\FakeTransport;

final class RpcClientTest extends TestCase {

    private static function okBody( mixed $result ): array {
        return FakeTransport::okBody( $result );
    }

    public function testReturnsResultFromFirstNode(): void {
        $transport = new FakeTransport( [ 'https://a' => self::okBody( [ 'ok' => true ] ) ] );
        $client    = new RpcClient( [ 'https://a' ], $transport );

        $this->assertSame( [ 'ok' => true ], $client->call( 'x' ) );
        $this->assertSame( [ 'https://a' ], $transport->hits );
    }

    public function testFailsOverToNextNodeOnTransportError(): void {
        $transport = new FakeTransport(
            [
                'https://a' => static fn() => throw new TransportException( 'timeout' ),
                'https://b' => self::okBody( 'desde-b' ),
            ]
        );
        $client = new RpcClient( [ 'https://a', 'https://b' ], $transport );

        $this->assertSame( 'desde-b', $client->call( 'x' ) );
        $this->assertSame( [ 'https://a', 'https://b' ], $transport->hits );
    }

    public function testFailsOverOnHttpErrorStatus(): void {
        $transport = new FakeTransport(
            [
                'https://a' => [ 'status' => 503, 'body' => 'unavailable' ],
                'https://b' => self::okBody( 'ok' ),
            ]
        );
        $client = new RpcClient( [ 'https://a', 'https://b' ], $transport );

        $this->assertSame( 'ok', $client->call( 'x' ) );
    }

    public function testThrowsWhenAllNodesFail(): void {
        $transport = new FakeTransport(
            [
                'https://a' => static fn() => throw new TransportException( 'timeout' ),
                'https://b' => [ 'status' => 500, 'body' => '' ],
            ]
        );
        $client = new RpcClient( [ 'https://a', 'https://b' ], $transport );

        try {
            $client->call( 'x' );
            $this->fail( 'Se esperaba RpcException.' );
        } catch ( RpcException $e ) {
            $this->assertCount( 2, $e->nodeErrors() );
        }
    }

    public function testRpcErrorInBodyTriggersFailover(): void {
        $transport = new FakeTransport(
            [
                'https://a' => [ 'status' => 200, 'body' => json_encode( [ 'error' => [ 'message' => 'bad tx' ] ] ) ],
                'https://b' => self::okBody( 'recuperado' ),
            ]
        );
        $client = new RpcClient( [ 'https://a', 'https://b' ], $transport );

        $this->assertSame( 'recuperado', $client->call( 'x' ) );
    }

    public function testReferenceBlockComputation(): void {
        // head_block_number = 4901 (0x1325); bytes 4..7 del id = 89 86 56 78 -> 2018936457.
        $props = [
            'head_block_number' => 4901,
            'head_block_id'     => '0000132589865678' . str_repeat( '0', 24 ),
        ];

        $ref = RpcClient::referenceBlock( $props );

        $this->assertSame( 4901, $ref['ref_block_num'] );
        $this->assertSame( 2018936457, $ref['ref_block_prefix'] );
    }
}
