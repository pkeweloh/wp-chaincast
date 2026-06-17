<?php
/**
 * Cola asíncrona de publicación sobre Action Scheduler.
 *
 * Cada (entrada, conector) se procesa en un job independiente, con reintentos
 * exponenciales ante fallos de nodo. El job construye el PostPayload, llama a
 * `publish()` del conector y registra el resultado en PostState.
 *
 * @package Chaincast\Core
 */

declare(strict_types=1);

namespace Chaincast\Core;

use Chaincast\Core\State\PostState;

final class Queue {

    /** Hook de Action Scheduler para procesar un job de publicación. */
    public const HOOK = 'chaincast_job';

    /** Grupo de Action Scheduler (para agrupar/limpiar). */
    public const GROUP = 'chaincast';

    /** Reintentos máximos antes de marcar como fallo definitivo. */
    public const MAX_ATTEMPTS = 5;

    private PostState $state;

    public function __construct(
        private PublishService $publisher,
    ) {
        $this->state = new PostState();
    }

    public function register(): void {
        add_action( self::HOOK, [ $this, 'process' ], 10, 3 );
    }

    /**
     * Encola la publicación de una entrada en una cadena.
     */
    public function enqueue( int $postId, string $connectorId, int $attempt = 1 ): void {
        if ( ! function_exists( 'as_enqueue_async_action' ) ) {
            return; // Action Scheduler no disponible (faltan dependencias).
        }

        as_enqueue_async_action(
            self::HOOK,
            [
                'post_id'      => $postId,
                'connector_id' => $connectorId,
                'attempt'      => $attempt,
            ],
            self::GROUP
        );
    }

    /**
     * Reencola con backoff exponencial tras un fallo reintentable.
     */
    public function retry( int $postId, string $connectorId, int $attempt ): bool {
        if ( ! function_exists( 'as_schedule_single_action' ) || $attempt >= self::MAX_ATTEMPTS ) {
            return false;
        }

        $delay = (int) ( 60 * ( 2 ** ( $attempt - 1 ) ) ); // 60s, 120s, 240s, ...

        as_schedule_single_action(
            time() + $delay,
            self::HOOK,
            [
                'post_id'      => $postId,
                'connector_id' => $connectorId,
                'attempt'      => $attempt + 1,
            ],
            self::GROUP
        );

        return true;
    }

    /**
     * Callback del job: publica vía el servicio y gestiona reintentos.
     */
    public function process( int $postId, string $connectorId, int $attempt = 1 ): void {
        $result = $this->publisher->publishNow( $postId, $connectorId );

        if ( $result->success ) {
            return;
        }

        if ( $result->retryable && $this->retry( $postId, $connectorId, $attempt ) ) {
            return; // Reencolado; el estado sigue en "queued".
        }

        $this->state->markFailed( $postId, $connectorId, (string) $result->error );
    }
}
