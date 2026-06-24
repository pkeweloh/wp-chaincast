<?php
/**
 * Fake HTTP transport for tests (no network).
 *
 * Each URL is mapped to a fixed response or a callable that receives the request
 * body and returns the response: handy for routing by JSON-RPC method.
 *
 * @package Chaincast\Tests\Support
 */

declare(strict_types=1);

namespace Chaincast\Tests\Support;

use Chaincast\Core\Rpc\HttpTransport;

final class FakeTransport implements HttpTransport {

    /** @var array<string,callable|array{status:int,body:string}> */
    private array $responses;

    /** @var string[] URLs actually contacted, in order. */
    public array $hits = [];

    /**
     * @param array<string,callable|array{status:int,body:string}> $responses
     */
    public function __construct( array $responses ) {
        $this->responses = $responses;
    }

    public function postJson( string $url, string $body, int $timeout ): array {
        $this->hits[] = $url;
        $r            = $this->responses[ $url ];

        return is_callable( $r ) ? $r( $body ) : $r;
    }

    /** Builds a successful JSON-RPC response body. */
    public static function okBody( mixed $result ): array {
        return [
            'status' => 200,
            'body'   => (string) json_encode( [ 'jsonrpc' => '2.0', 'id' => 1, 'result' => $result ] ),
        ];
    }
}
