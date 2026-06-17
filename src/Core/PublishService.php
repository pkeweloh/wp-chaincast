<?php
/**
 * Servicio de publicación: construye el payload de una entrada y la publica en
 * un conector, actualizando el estado. Lo usan tanto la cola (async) como el
 * botón manual del editor (síncrono).
 *
 * En éxito marca el estado como publicado; en fallo NO marca nada (lo decide
 * quien llama: la cola puede reintentar, el botón manual marca fallo).
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

        // Reutiliza el permlink ya asignado (idempotencia en ediciones).
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
     * Modo asistido (Keychain): prepara la operación a firmar en el navegador.
     * No toca la posting key ni la red.
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
     * Modo asistido: registra un broadcast hecho en el navegador (Keychain).
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
