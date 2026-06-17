<?php
/**
 * Transporte HTTP basado en la API de WordPress (`wp_remote_post`).
 *
 * @package Chaincast\Core\Rpc
 */

declare(strict_types=1);

namespace Chaincast\Core\Rpc;

final class WpHttpTransport implements HttpTransport {

    public function postJson( string $url, string $body, int $timeout ): array {
        $response = wp_remote_post(
            $url,
            [
                'timeout' => $timeout,
                'headers' => [ 'Content-Type' => 'application/json' ],
                'body'    => $body,
            ]
        );

        if ( is_wp_error( $response ) ) {
            throw new TransportException( $response->get_error_message() );
        }

        return [
            'status' => (int) wp_remote_retrieve_response_code( $response ),
            'body'   => (string) wp_remote_retrieve_body( $response ),
        ];
    }
}
