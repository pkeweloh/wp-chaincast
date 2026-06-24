<?php
/**
 * Per-post publishing log (post meta).
 *
 * Records each publish/edit/delete attempt on any chain and via any path (queue,
 * manual button, Keychain), with a timestamp, result, tx_id and error detail.
 * The metabox consumes it to show the history.
 *
 * It is informational: it does NOT replace PostState (which holds the current
 * state and idempotency). Kept bounded to the latest entries so it does not grow
 * without limit.
 *
 * @package Chaincast\Core\State
 */

declare(strict_types=1);

namespace Chaincast\Core\State;

final class PublishLog {

    private const META_KEY    = '_chaincast_log';
    private const MAX_ENTRIES = 30;

    /**
     * Appends an entry to the post's log (most recent last).
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
     * All log entries (most recent last).
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
