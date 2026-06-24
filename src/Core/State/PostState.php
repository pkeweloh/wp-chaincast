<?php
/**
 * Per-post, per-chain publishing state, persisted in post meta.
 *
 * It is the basis of idempotency: before queueing/publishing we check here to
 * avoid publishing twice, and on completion we store the result per chain.
 *
 * @package Chaincast\Core\State
 */

declare(strict_types=1);

namespace Chaincast\Core\State;

use Chaincast\Connector\PublishResult;

final class PostState {

    /** Meta key prefix: _chaincast_state_{connectorId}. */
    private const META_PREFIX = '_chaincast_state_';

    /** Possible states. */
    public const STATUS_NONE      = 'none';
    public const STATUS_QUEUED    = 'queued';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_FAILED    = 'failed';

    private static function metaKey( string $connectorId ): string {
        return self::META_PREFIX . $connectorId;
    }

    /**
     * @return array<string,mixed>
     */
    public function get( int $postId, string $connectorId ): array {
        $state = get_post_meta( $postId, self::metaKey( $connectorId ), true );

        return is_array( $state ) ? $state : [ 'status' => self::STATUS_NONE ];
    }

    public function status( int $postId, string $connectorId ): string {
        return (string) ( $this->get( $postId, $connectorId )['status'] ?? self::STATUS_NONE );
    }

    /**
     * Already published (or in progress) on this chain? Guards against double sends.
     */
    public function isHandled( int $postId, string $connectorId ): bool {
        return in_array(
            $this->status( $postId, $connectorId ),
            [ self::STATUS_QUEUED, self::STATUS_PUBLISHED ],
            true
        );
    }

    public function markQueued( int $postId, string $connectorId ): void {
        $this->save(
            $postId,
            $connectorId,
            [
                'status'    => self::STATUS_QUEUED,
                'queued_at' => time(),
            ]
        );
    }

    public function markPublished( int $postId, string $connectorId, PublishResult $result ): void {
        $this->save(
            $postId,
            $connectorId,
            [
                'status'       => self::STATUS_PUBLISHED,
                'ref'          => $result->ref,
                'url'          => $result->url,
                'tx_id'        => $result->txId,
                'broadcast_at' => time(),
            ]
        );
    }

    public function markFailed( int $postId, string $connectorId, string $error ): void {
        $this->save(
            $postId,
            $connectorId,
            [
                'status'    => self::STATUS_FAILED,
                'error'     => $error,
                'failed_at' => time(),
            ]
        );
    }

    /**
     * @param array<string,mixed> $state
     */
    private function save( int $postId, string $connectorId, array $state ): void {
        update_post_meta( $postId, self::metaKey( $connectorId ), $state );
    }
}
