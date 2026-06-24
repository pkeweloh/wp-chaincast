<?php
/**
 * HTTP transport based on cURL.
 *
 * Fallback for environments outside WordPress (CLI, vector generation, manual
 * integration testing).
 *
 * @package Chaincast\Core\Rpc
 */

declare(strict_types=1);

namespace Chaincast\Core\Rpc;

final class CurlHttpTransport implements HttpTransport {

    public function postJson( string $url, string $body, int $timeout ): array {
        $ch = curl_init( $url );
        if ( false === $ch ) {
            throw new TransportException( 'Could not initialize cURL.' );
        }

        curl_setopt_array(
            $ch,
            [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_HTTPHEADER     => [ 'Content-Type: application/json' ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => $timeout,
            ]
        );

        $responseBody = curl_exec( $ch );
        if ( false === $responseBody ) {
            $error = curl_error( $ch );
            curl_close( $ch );
            throw new TransportException( 'cURL: ' . $error );
        }

        $status = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
        curl_close( $ch );

        return [
            'status' => $status,
            'body'   => (string) $responseBody,
        ];
    }
}
