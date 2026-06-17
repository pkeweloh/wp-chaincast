<?php
/**
 * Value object genérico ok/error para operaciones que no devuelven un
 * resultado de publicación (p. ej. validar credenciales).
 *
 * @package Chaincast\Connector
 */

declare(strict_types=1);

namespace Chaincast\Connector;

final class Result {

    /**
     * @param array<string,mixed> $data
     */
    private function __construct(
        public readonly bool $ok,
        public readonly ?string $error = null,
        public readonly array $data = [],
    ) {
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function ok( array $data = [] ): self {
        return new self( true, null, $data );
    }

    public static function fail( string $error ): self {
        return new self( false, $error );
    }
}
