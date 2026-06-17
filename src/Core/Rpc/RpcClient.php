<?php
/**
 * Cliente JSON-RPC para nodos graphene (Hive/Steem) con failover.
 *
 * Recorre la lista de nodos en orden; ante fallo de red o error del nodo, pasa
 * al siguiente. Solo si todos fallan lanza RpcException con el detalle por nodo.
 * Es agnóstico de la cadena: la lista de nodos y el método los pone el conector.
 *
 * @package Chaincast\Core\Rpc
 */

declare(strict_types=1);

namespace Chaincast\Core\Rpc;

final class RpcClient {

    /**
     * @param string[]      $nodes     URLs de los nodos, por orden de preferencia.
     * @param HttpTransport $transport Transporte HTTP (WP por defecto en producción).
     * @param int           $timeout   Timeout por nodo en segundos.
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
     * Llama a un método JSON-RPC con failover. Devuelve el campo `result`.
     *
     * @param array<int|string,mixed> $params
     *
     * @return mixed
     *
     * @throws RpcException Si todos los nodos fallan.
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
                // Error de la propia red (p. ej. tx inválida). No suele resolverse
                // probando otro nodo, pero se acumula y se intenta el siguiente por
                // si fuese un nodo concreto el problemático.
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
     * Propiedades dinámicas globales (para ref_block_num / prefix / expiración).
     *
     * @return array<string,mixed>
     */
    public function getDynamicGlobalProperties(): array {
        $props = $this->call( 'condenser_api.get_dynamic_global_properties' );

        return is_array( $props ) ? $props : [];
    }

    /**
     * Emite una transacción firmada.
     *
     * @param array<string,mixed> $signedTx
     *
     * @return mixed
     */
    public function broadcastTransaction( array $signedTx ): mixed {
        return $this->call( 'condenser_api.broadcast_transaction', [ $signedTx ] );
    }

    /**
     * Calcula ref_block_num y ref_block_prefix a partir de las propiedades globales,
     * igual que el resto de clientes graphene.
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
            // Los bytes 4..7 del block id, leídos como uint32 little-endian.
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
 * `json_encode` que funciona dentro y fuera de WordPress.
 *
 * @param mixed $data
 */
function wp_json_encode_compat( $data ): string {
    if ( function_exists( 'wp_json_encode' ) ) {
        return (string) wp_json_encode( $data );
    }
    return (string) json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
}
