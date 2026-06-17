<?php
/**
 * Error de nivel RPC: todos los nodos fallaron, o un nodo devolvió un error
 * JSON-RPC (p. ej. transacción rechazada por la red).
 *
 * @package Chaincast\Core\Rpc
 */

declare(strict_types=1);

namespace Chaincast\Core\Rpc;

use RuntimeException;

final class RpcException extends RuntimeException {

    /** @var array<int,string> Errores acumulados por nodo, para diagnóstico. */
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
