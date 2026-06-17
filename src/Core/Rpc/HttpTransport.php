<?php
/**
 * Abstracción de transporte HTTP para el cliente RPC.
 *
 * Permite usar la API HTTP de WordPress en producción y un transporte falso en
 * los tests (sin red). Devuelve siempre [status, body]; un fallo de red se
 * señala lanzando TransportException.
 *
 * @package Chaincast\Core\Rpc
 */

declare(strict_types=1);

namespace Chaincast\Core\Rpc;

interface HttpTransport {

    /**
     * Envía un POST con cuerpo JSON.
     *
     * @return array{status:int,body:string}
     *
     * @throws TransportException En fallo de red/timeout.
     */
    public function postJson( string $url, string $body, int $timeout ): array;
}
