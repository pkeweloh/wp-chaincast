<?php
/**
 * Resultado de un intento de publicación en una cadena.
 *
 * Es un value object inmutable: en éxito lleva la referencia on-chain
 * (permlink/id), la URL pública y el tx_id; en fallo lleva el mensaje y si
 * conviene reintentar (errores de nodo) o no (errores definitivos).
 *
 * @package Chaincast\Connector
 */

declare(strict_types=1);

namespace Chaincast\Connector;

final class PublishResult {

    private function __construct(
        public readonly bool $success,
        public readonly ?string $ref = null,        // permlink u otra referencia estable.
        public readonly ?string $url = null,        // URL pública del contenido.
        public readonly ?string $txId = null,       // id de transacción on-chain.
        public readonly ?string $error = null,
        public readonly bool $retryable = false,
    ) {
    }

    public static function success( string $ref, string $url, ?string $txId = null ): self {
        return new self( true, $ref, $url, $txId );
    }

    public static function failure( string $error, bool $retryable = false ): self {
        return new self( false, null, null, null, $error, $retryable );
    }

    /**
     * Representación serializable para guardar en post meta.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array {
        return [
            'success'   => $this->success,
            'ref'       => $this->ref,
            'url'       => $this->url,
            'tx_id'     => $this->txId,
            'error'     => $this->error,
            'retryable' => $this->retryable,
        ];
    }
}
