<?php
/**
 * Transporte HTTP falso para tests (sin red).
 *
 * A cada URL se le asocia una respuesta fija o un callable que recibe el cuerpo
 * de la petición y devuelve la respuesta — útil para enrutar por método JSON-RPC.
 *
 * @package Chaincast\Tests\Support
 */

declare(strict_types=1);

namespace Chaincast\Tests\Support;

use Chaincast\Core\Rpc\HttpTransport;

final class FakeTransport implements HttpTransport {

    /** @var array<string,callable|array{status:int,body:string}> */
    private array $responses;

    /** @var string[] URLs realmente contactadas, en orden. */
    public array $hits = [];

    /**
     * @param array<string,callable|array{status:int,body:string}> $responses
     */
    public function __construct( array $responses ) {
        $this->responses = $responses;
    }

    public function postJson( string $url, string $body, int $timeout ): array {
        $this->hits[] = $url;
        $r            = $this->responses[ $url ];

        return is_callable( $r ) ? $r( $body ) : $r;
    }

    /** Construye un cuerpo de respuesta JSON-RPC con éxito. */
    public static function okBody( mixed $result ): array {
        return [
            'status' => 200,
            'body'   => (string) json_encode( [ 'jsonrpc' => '2.0', 'id' => 1, 'result' => $result ] ),
        ];
    }
}
