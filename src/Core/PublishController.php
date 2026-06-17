<?php
/**
 * Decide qué y cuándo publicar.
 *
 * Escucha la transición de estado de las entradas (* -> publish) y, respetando
 * la idempotencia (PostState), encola un job por cada conector activo elegido
 * para esa entrada. La construcción del PostPayload y la traducción a Markdown
 * llegan en fases posteriores.
 *
 * @package Chaincast\Core
 */

declare(strict_types=1);

namespace Chaincast\Core;

use Chaincast\Core\State\PostState;
use WP_Post;

final class PublishController {

    private PostState $state;

    public function __construct(
        private ConnectorRegistry $connectors,
        private Queue $queue,
        private Settings $settings,
    ) {
        $this->state = new PostState();
    }

    public function register(): void {
        add_action( 'transition_post_status', [ $this, 'onTransition' ], 10, 3 );
    }

    /**
     * @param string  $new Estado nuevo.
     * @param string  $old Estado anterior.
     * @param WP_Post $post
     */
    public function onTransition( string $new, string $old, WP_Post $post ): void {
        // Solo nos interesa la transición a "publish".
        if ( 'publish' !== $new ) {
            return;
        }

        // De momento solo entradas (post). Tipos adicionales: configurable en Fase 4.
        if ( 'post' !== $post->post_type ) {
            return;
        }

        $postId = (int) $post->ID;

        foreach ( $this->targetsFor( $postId ) as $connectorId ) {
            if ( $this->state->isHandled( $postId, $connectorId ) ) {
                continue; // Idempotencia: ya encolado o publicado.
            }

            $this->state->markQueued( $postId, $connectorId );
            $this->queue->enqueue( $postId, $connectorId );
        }
    }

    /**
     * Conectores destino para auto-publicación: solo los configurados que tienen
     * la auto-publicación activada en ajustes. Si ninguno la tiene, no se publica
     * nada automáticamente (el usuario usa el botón manual del editor).
     *
     * @return string[]
     */
    private function targetsFor( int $postId ): array {
        $ids = [];
        foreach ( $this->connectors->configured() as $id => $connector ) {
            if ( $this->settings->autoPublish( $id ) ) {
                $ids[] = $id;
            }
        }

        /**
         * Permite filtrar a qué cadenas se auto-publica una entrada concreta.
         *
         * @param string[] $ids    IDs de conector destino.
         * @param int      $postId
         */
        return (array) apply_filters( 'chaincast_targets', $ids, $postId );
    }
}
