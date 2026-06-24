<?php
/**
 * Decides what to publish and when.
 *
 * Listens to the posts' status transition (* to publish) and, honoring
 * idempotency (PostState), enqueues one job per active connector chosen for that
 * post.
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
     * @param string  $new New status.
     * @param string  $old Previous status.
     * @param WP_Post $post
     */
    public function onTransition( string $new, string $old, WP_Post $post ): void {
        // We only care about the transition to "publish".
        if ( 'publish' !== $new ) {
            return;
        }

        // Posts only for now.
        if ( 'post' !== $post->post_type ) {
            return;
        }

        $postId = (int) $post->ID;

        foreach ( $this->targetsFor( $postId ) as $connectorId ) {
            if ( $this->state->isHandled( $postId, $connectorId ) ) {
                continue; // Idempotency: already queued or published.
            }

            $this->state->markQueued( $postId, $connectorId );
            $this->queue->enqueue( $postId, $connectorId );
        }
    }

    /**
     * Target connectors for auto-publishing: only the configured ones with
     * auto-publish enabled in settings. If none have it, nothing is published
     * automatically (the user uses the editor's manual button).
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
         * Lets callers filter which chains a specific post is auto-published to.
         *
         * @param string[] $ids    Target connector IDs.
         * @param int      $postId
         */
        return (array) apply_filters( 'chaincast_targets', $ids, $postId );
    }
}
