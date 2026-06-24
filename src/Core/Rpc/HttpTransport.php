<?php
/**
 * HTTP transport abstraction for the RPC client.
 *
 * Allows using the WordPress HTTP API in production and a fake transport in
 * tests (no network). Always returns [status, body]; a network failure is
 * signaled by throwing TransportException.
 *
 * @package Chaincast\Core\Rpc
 */

declare(strict_types=1);

namespace Chaincast\Core\Rpc;

interface HttpTransport {

    /**
     * Sends a POST with a JSON body.
     *
     * @return array{status:int,body:string}
     *
     * @throws TransportException On network failure/timeout.
     */
    public function postJson( string $url, string $body, int $timeout ): array;
}
