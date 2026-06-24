<?php
/**
 * RPC-level error: all nodes failed, or a node returned a JSON-RPC error
 * (e.g. a transaction rejected by the network).
 *
 * @package Chaincast\Core\Rpc
 */

declare(strict_types=1);

namespace Chaincast\Core\Rpc;

use RuntimeException;

final class RpcException extends RuntimeException {

    /** @var array<int,string> Per-node accumulated errors, for diagnostics. */
    private array $nodeErrors;

    /**
     * @param array<int,string> $nodeErrors
     */
    public function __construct( string $message, array $nodeErrors = [] ) {
        parent::__construct( $message );
        $this->nodeErrors = $nodeErrors;
    }

    /**
     * @return array<int,string>
     */
    public function nodeErrors(): array {
        return $this->nodeErrors;
    }
}
