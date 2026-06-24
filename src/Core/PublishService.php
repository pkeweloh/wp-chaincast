<?php
/**
 * Publishing service: builds a post's payload and publishes it through a
 * connector, updating the state. Used by both the queue (async) and the
 * editor's manual button (sync).
 *
 * On success it marks the state as published; on failure it marks nothing (the
 * caller decides: the queue may retry, the manual button marks failure).
 *
 * @package Chaincast\Core
 */

declare(strict_types=1);

namespace Chaincast\Core;

use Throwable;
use Chaincast\Connector\PayloadFactory;
use Chaincast\Connector\PublishResult;
use Chaincast\Core\State\PostState;
use Chaincast\Core\State\PublishLog;

final class PublishService {

    public function __construct(
        private ConnectorRegistry $connectors,
        private PayloadFactory $payloads,
        private PostState $state,
        private Settings $settings,
        private PublishLog $log = new PublishLog(),
    ) {
    }

    public function publishNow( int $postId, string $connectorId ): PublishResult {
        $connector = $this->connectors->get( $connectorId );
        if ( null === $connector ) {
            return PublishResult::failure( 'Conector no registrado: ' . $connectorId );
        }

        $post = get_post( $postId );
        if ( null === $post ) {
            return PublishResult::failure( 'La entrada ya no existe.' );
        }

        // Reuse the already-assigned permlink (idempotency on edits).
        $existing = $this->state->get( $postId, $connectorId );
        $permlink = is_string( $existing['ref'] ?? null ) ? $existing['ref'] : '';

        $footer = $this->settings->footerEnabled() ? $this->settings->footerText() : '';
        $action = '' !== $permlink ? 'update' : 'publish';

        try {
            $payload = $this->payloads->fromPost( $post, (string) get_bloginfo( 'name' ), $permlink, $footer, $this->settings->beneficiaries( $connectorId ), $this->settings->categoryMapFor( $connectorId ) );
            $result  = $connector->publish( $payload );
        } catch ( Throwable $e ) {
            $message = 'Excepción al publicar: ' . $e->getMessage();
            $this->log->record( $postId, $connectorId, $action, false, $message );
            return PublishResult::failure( $message );
        }

        if ( $result->success ) {
            $this->state->markPublished( $postId, $connectorId, $result );
            $this->log->record( $postId, $connectorId, $action, true, (string) $result->url, $result->txId );
        } else {
            $this->log->record( $postId, $connectorId, $action, false, (string) $result->error );
        }

        return $result;
    }

    /**
     * Assisted mode (Keychain): prepares the operation to sign in the browser.
     * Does not touch the posting key or the network.
     *
     * @return array{account:string,operations:array<int,array{0:string,1:array<string,mixed>}>,permlink:string}|null
     */
    public function buildSigningRequest( int $postId, string $connectorId ): ?array {
        $connector = $this->connectors->get( $connectorId );
        $post      = get_post( $postId );
        if ( null === $connector || null === $post ) {
            return null;
        }

        $existing = $this->state->get( $postId, $connectorId );
        $permlink = is_string( $existing['ref'] ?? null ) ? $existing['ref'] : '';
        $footer   = $this->settings->footerEnabled() ? $this->settings->footerText() : '';

        $payload = $this->payloads->fromPost( $post, (string) get_bloginfo( 'name' ), $permlink, $footer, $this->settings->beneficiaries( $connectorId ), $this->settings->categoryMapFor( $connectorId ) );
        $req     = $connector->buildSigningRequest( $payload );

        return [
            'account'    => (string) $req['account'],
            'operations' => $req['operations'],
            'permlink'   => (string) $req['permlink'],
        ];
    }

    /**
     * Assisted mode: records a broadcast made in the browser (Keychain).
     */
    public function confirmExternal( int $postId, string $connectorId, string $permlink, string $txId ): PublishResult {
        $connector = $this->connectors->get( $connectorId );
        if ( null === $connector ) {
            return PublishResult::failure( 'Conector no registrado: ' . $connectorId );
        }

        $base   = $connector->confirmExternalBroadcast( $permlink );
        $result = PublishResult::success( (string) $base->ref, (string) $base->url, '' !== $txId ? $txId : null );

        $this->state->markPublished( $postId, $connectorId, $result );
        $this->log->record( $postId, $connectorId, 'keychain', true, (string) $result->url, $result->txId );

        return $result;
    }
}
