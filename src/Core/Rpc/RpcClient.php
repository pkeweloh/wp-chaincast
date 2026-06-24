<?php
/**
 * JSON-RPC client for graphene nodes (Hive/Steem) with failover.
 *
 * Walks the node list in order; on a network failure or node error it moves to
 * the next. Only if all fail does it throw RpcException with the per-node detail.
 * Chain-agnostic: the node list and the method are supplied by the connector.
 *
 * @package Chaincast\Core\Rpc
 */

declare(strict_types=1);

namespace Chaincast\Core\Rpc;

final class RpcClient {

    /**
     * @param string[]      $nodes     Node URLs, in order of preference.
     * @param HttpTransport $transport HTTP transport (WP by default in production).
     * @param int           $timeout   Per-node timeout in seconds.
     */
    public function __construct(
        private array $nodes,
        private HttpTransport $transport,
        private int $timeout = 10,
    ) {
        if ( empty( $this->nodes ) ) {
            throw new \InvalidArgumentException( 'RpcClient requiere al menos un nodo.' );
        }
    }

    /**
     * Calls a JSON-RPC method with failover. Returns the `result` field.
     *
     * @param array<int|string,mixed> $params
     *
     * @return mixed
     *
     * @throws RpcException If all nodes fail.
     */
    public function call( string $method, array $params = [] ): mixed {
        $payload = wp_json_encode_compat(
            [
                'jsonrpc' => '2.0',
                'method'  => $method,
                'params'  => $params,
                'id'      => 1,
            ]
        );

        $errors = [];

        foreach ( $this->nodes as $node ) {
            try {
                $response = $this->transport->postJson( $node, $payload, $this->timeout );
            } catch ( TransportException $e ) {
                $errors[ $node ] = 'transporte: ' . $e->getMessage();
                continue;
            }

            if ( $response['status'] < 200 || $response['status'] >= 300 ) {
                $errors[ $node ] = 'HTTP ' . $response['status'];
                continue;
            }

            $decoded = json_decode( $response['body'], true );
            if ( ! is_array( $decoded ) ) {
                $errors[ $node ] = 'respuesta no-JSON';
                continue;
            }

            if ( isset( $decoded['error'] ) ) {
                // A network-level error (e.g. invalid tx). Trying another node
                // rarely helps, but we accumulate it and try the next one in case
                // the problem was a specific node.
                $errors[ $node ] = 'RPC: ' . $this->stringifyError( $decoded['error'] );
                continue;
            }

            return $decoded['result'] ?? null;
        }

        throw new RpcException(
            sprintf( 'Todos los nodos fallaron para "%s".', $method ),
            $errors
        );
    }

    /**
     * Dynamic global properties (for ref_block_num / prefix / expiration).
     *
     * @return array<string,mixed>
     */
    public function getDynamicGlobalProperties(): array {
        $props = $this->call( 'condenser_api.get_dynamic_global_properties' );

        return is_array( $props ) ? $props : [];
    }

    /**
     * Broadcasts a signed transaction.
     *
     * @param array<string,mixed> $signedTx
     *
     * @return mixed
     */
    public function broadcastTransaction( array $signedTx ): mixed {
        return $this->call( 'condenser_api.broadcast_transaction', [ $signedTx ] );
    }

    /**
     * Computes ref_block_num and ref_block_prefix from the global properties,
     * like the other graphene clients.
     *
     * @param array<string,mixed> $props
     *
     * @return array{ref_block_num:int,ref_block_prefix:int}
     */
    public static function referenceBlock( array $props ): array {
        $headNum = (int) ( $props['head_block_number'] ?? 0 );
        $headId  = (string) ( $props['head_block_id'] ?? '' );

        $prefix = 0;
        if ( strlen( $headId ) >= 16 ) {
            // Bytes 4..7 of the block id, read as little-endian uint32.
            $bytes  = hex2bin( substr( $headId, 8, 8 ) );
            $prefix = false !== $bytes ? unpack( 'V', $bytes )[1] : 0;
        }

        return [
            'ref_block_num'    => $headNum & 0xFFFF,
            'ref_block_prefix' => $prefix,
        ];
    }

    private function stringifyError( mixed $error ): string {
        if ( is_array( $error ) ) {
            return (string) ( $error['message'] ?? wp_json_encode_compat( $error ) );
        }
        return (string) $error;
    }
}

/**
 * `json_encode` that works inside and outside WordPress.
 *
 * @param mixed $data
 */
function wp_json_encode_compat( $data ): string {
    if ( function_exists( 'wp_json_encode' ) ) {
        return (string) wp_json_encode( $data );
    }
    return (string) json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
}
