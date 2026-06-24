<?php
/**
 * Result of a publish attempt on a chain.
 *
 * An immutable value object: on success it carries the on-chain reference
 * (permlink/id), the public URL and the tx_id; on failure it carries the message
 * and whether a retry makes sense (node errors) or not (definitive errors).
 *
 * @package Chaincast\Connector
 */

declare(strict_types=1);

namespace Chaincast\Connector;

final class PublishResult {

    private function __construct(
        public readonly bool $success,
        public readonly ?string $ref = null,        // permlink or another stable reference.
        public readonly ?string $url = null,        // public URL of the content.
        public readonly ?string $txId = null,       // on-chain transaction id.
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
     * Serializable representation for storing in post meta.
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
