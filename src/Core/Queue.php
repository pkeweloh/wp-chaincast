<?php
/**
 * Asynchronous publishing queue on top of Action Scheduler.
 *
 * Each (post, connector) is processed in its own job, with exponential retries
 * on node failures. The job builds the PostPayload, calls the connector's
 * `publish()` and records the result in PostState.
 *
 * @package Chaincast\Core
 */

declare(strict_types=1);

namespace Chaincast\Core;

use Chaincast\Core\State\PostState;

final class Queue {

    /** Action Scheduler hook to process a publishing job. */
    public const HOOK = 'chaincast_job';

    /** Action Scheduler group (for grouping/cleanup). */
    public const GROUP = 'chaincast';

    /** Max retries before marking a definitive failure. */
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
     * Enqueues publishing a post to a chain.
     */
    public function enqueue( int $postId, string $connectorId, int $attempt = 1 ): void {
        if ( ! function_exists( 'as_enqueue_async_action' ) ) {
            return; // Action Scheduler unavailable (dependencies missing).
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
     * Re-enqueues with exponential backoff after a retryable failure.
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
     * Job callback: publishes via the service and handles retries.
     */
    public function process( int $postId, string $connectorId, int $attempt = 1 ): void {
        $result = $this->publisher->publishNow( $postId, $connectorId );

        if ( $result->success ) {
            return;
        }

        if ( $result->retryable && $this->retry( $postId, $connectorId, $attempt ) ) {
            return; // Re-enqueued; the state stays "queued".
        }

        $this->state->markFailed( $postId, $connectorId, (string) $result->error );
    }
}
