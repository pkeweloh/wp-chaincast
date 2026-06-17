<?php
/**
 * Registro de conectores activos.
 *
 * El núcleo se mantiene agnóstico: los conectores concretos se añaden aquí
 * (normalmente desde el hook `chaincast_register_connectors`).
 *
 * @package Chaincast\Core
 */

declare(strict_types=1);

namespace Chaincast\Core;

use Chaincast\Connector\ConnectorInterface;

final class ConnectorRegistry {

    /** @var array<string,ConnectorInterface> */
    private array $connectors = [];

    public function add( ConnectorInterface $connector ): void {
        $this->connectors[ $connector->id() ] = $connector;
    }

    public function has( string $id ): bool {
        return isset( $this->connectors[ $id ] );
    }

    public function get( string $id ): ?ConnectorInterface {
        return $this->connectors[ $id ] ?? null;
    }

    /**
     * @return array<string,ConnectorInterface>
     */
    public function all(): array {
        return $this->connectors;
    }

    /**
     * Conectores que están configurados y listos para operar.
     *
     * @return array<string,ConnectorInterface>
     */
    public function configured(): array {
        return array_filter(
            $this->connectors,
            static fn( ConnectorInterface $c ): bool => $c->isConfigured()
        );
    }
}
