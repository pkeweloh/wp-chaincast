<?php
/**
 * Bitácora de publicaciones por entrada (post meta).
 *
 * Registra cada intento de publicación/edición/borrado en cualquier cadena y por
 * cualquier vía (cola, botón manual, Keychain), con marca de tiempo, resultado,
 * tx_id y detalle de error. Lo consume el metabox para mostrar el historial.
 *
 * Es informativo: NO sustituye a PostState (que guarda el estado actual y la
 * idempotencia). Se guarda acotado a las últimas entradas para no crecer sin fin.
 *
 * @package Chaincast\Core\State
 */

declare(strict_types=1);

namespace Chaincast\Core\State;

final class PublishLog {

    private const META_KEY    = '_chaincast_log';
    private const MAX_ENTRIES = 30;

    /**
     * Añade una entrada al log de la entrada (las más recientes al final).
     */
    public function record( int $postId, string $connectorId, string $action, bool $success, string $detail = '', ?string $txId = null ): void {
        $entries   = $this->all( $postId );
        $entries[] = [
            'time'      => time(),
            'connector' => $connectorId,
            'action'    => $action,
            'success'   => $success,
            'detail'    => $detail,
            'tx_id'     => $txId,
        ];

        if ( count( $entries ) > self::MAX_ENTRIES ) {
            $entries = array_slice( $entries, -self::MAX_ENTRIES );
        }

        update_post_meta( $postId, self::META_KEY, $entries );
    }

    /**
     * Todas las entradas del log (más recientes al final).
     *
     * @return array<int,array<string,mixed>>
     */
    public function all( int $postId ): array {
        $log = get_post_meta( $postId, self::META_KEY, true );
        return is_array( $log ) ? $log : [];
    }

    public function clear( int $postId ): void {
        delete_post_meta( $postId, self::META_KEY );
    }
}
