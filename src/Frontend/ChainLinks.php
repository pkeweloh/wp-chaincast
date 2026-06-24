<?php
/**
 * Appends links to the post's blockchain copies (Hive/Steem) after the content.
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

    public function append( string $content ): string {
        // Main-loop full content only: skip feeds and secondary queries.
        if ( is_feed() || ! in_the_loop() || ! is_main_query() ) {
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
