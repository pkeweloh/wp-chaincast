<?php
/**
 * Añade al final de la entrada los enlaces a sus copias en las cadenas.
 *
 * Es el reflejo del pie de atribución que viaja a Hive/Steem: aquí, en el lado
 * de WordPress, se enlaza a las versiones publicadas en cada cadena. Solo se
 * muestran las cadenas en las que la entrada está efectivamente publicada.
 *
 * @package Chaincast\Frontend
 */

declare(strict_types=1);

namespace Chaincast\Frontend;

use Chaincast\Core\ConnectorRegistry;
use Chaincast\Core\State\PostState;

final class ChainLinks {

    public function __construct(
        private ConnectorRegistry $connectors,
        private PostState $state,
    ) {
    }

    public function register(): void {
        add_filter( 'the_content', [ $this, 'append' ], 20 );
    }

    /**
     * Engancha en `the_content`. Solo actúa en la vista individual de la entrada
     * dentro del bucle principal, para no contaminar feeds, extractos ni listados.
     */
    public function append( string $content ): string {
        if ( ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
            return $content;
        }

        $postId = get_the_ID();
        if ( ! $postId ) {
            return $content;
        }

        $lines = [];
        foreach ( $this->connectors->all() as $connector ) {
            $state = $this->state->get( (int) $postId, $connector->id() );

            if ( PostState::STATUS_PUBLISHED !== ( $state['status'] ?? '' ) ) {
                continue;
            }

            $url = (string) ( $state['url'] ?? '' );
            if ( '' === $url ) {
                continue;
            }

            $link = sprintf(
                '<a href="%s" target="_blank" rel="noopener">%s</a>',
                esc_url( $url ),
                esc_html( $connector->label() )
            );

            $lines[] = '<p>' . sprintf(
                /* translators: %s: chain name, rendered as a link (e.g. Hive). */
                __( 'Also published on %s.', 'chaincast' ),
                $link
            ) . '</p>';
        }

        if ( empty( $lines ) ) {
            return $content;
        }

        return $content . '<div class="chaincast-links">' . implode( '', $lines ) . '</div>';
    }
}
