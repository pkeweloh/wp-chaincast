<?php
/**
 * Firma ECDSA secp256k1 canónica para graphene (Hive/Steem).
 *
 * La matemática de curva la aporta `simplito/elliptic-php` (PHP puro, requiere
 * ext-gmp); aquí va SOLO la orquestación: bucle hasta obtener una firma
 * canónica (low-S + predicado is_canonical de graphene) y empaquetado compacto
 * de 65 bytes (cabecera de recuperación + r + s).
 *
 * El nonce se varía de forma determinista (personalización = sha256(digest||contador)),
 * así la firma es reproducible para los tests sin depender de aleatoriedad del sistema.
 *
 * @package Chaincast\Core\Crypto
 */

declare(strict_types=1);

namespace Chaincast\Core\Crypto;

use Elliptic\EC;
use RuntimeException;

final class Secp256k1 {

    /** Intentos máximos para encontrar una firma canónica (en la práctica, 1-3). */
    private const MAX_ATTEMPTS = 64;

    private EC $ec;

    public function __construct() {
        $this->ec = new EC( 'secp256k1' );
    }

    /**
     * Firma un digest (32 bytes en hex) con la clave privada (32 bytes en hex) y
     * devuelve la firma compacta de 65 bytes en hex: cabecera(1) + r(32) + s(32).
     *
     * La cabecera = recoveryParam + 31 (27 base + 4 por clave comprimida), tal y
     * como espera Hive/Steem.
     */
    public function signCompact( string $digestHex, string $privHex ): string {
        $key = $this->ec->keyFromPrivate( $privHex, 'hex' );

        for ( $attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++ ) {
            $pers = hash( 'sha256', hex2bin( $digestHex . $this->counterHex( $attempt ) ) );

            $sig = $key->sign(
                $digestHex,
                [
                    'canonical' => true, // fuerza low-S.
                    'pers'      => $pers,
                ]
            );

            $r = str_pad( $sig->r->toString( 16 ), 64, '0', STR_PAD_LEFT );
            $s = str_pad( $sig->s->toString( 16 ), 64, '0', STR_PAD_LEFT );

            $rs = hex2bin( $r . $s );
            if ( ! $this->isCanonical( $rs ) ) {
                continue;
            }

            $header = $sig->recoveryParam + 31;

            return bin2hex( chr( $header ) . $rs );
        }

        throw new RuntimeException( 'No se obtuvo una firma canónica tras ' . self::MAX_ATTEMPTS . ' intentos.' );
    }

    /**
     * Verifica una firma compacta contra una clave pública (hex, comprimida o no).
     */
    public function verifyCompact( string $digestHex, string $compactHex, string $pubHex ): bool {
        [ 'r' => $r, 's' => $s ] = $this->splitCompact( $compactHex );
        $key = $this->ec->keyFromPublic( $pubHex, 'hex' );

        return $key->verify( $digestHex, [ 'r' => $r, 's' => $s ] );
    }

    /**
     * Recupera la clave pública (hex comprimida) que produjo una firma compacta.
     */
    public function recoverPublic( string $digestHex, string $compactHex ): string {
        [ 'r' => $r, 's' => $s, 'recovery' => $recovery ] = $this->splitCompact( $compactHex );

        $pub = $this->ec->recoverPubKey( $digestHex, [ 'r' => $r, 's' => $s ], $recovery );

        return $pub->encode( 'hex', true ); // comprimida.
    }

    /**
     * Predicado is_canonical de graphene sobre los 64 bytes r||s.
     */
    private function isCanonical( string $rs ): bool {
        $c = array_values( unpack( 'C*', $rs ) ); // bytes 0..63.

        return 0 === ( $c[0] & 0x80 )
            && ! ( 0 === $c[0] && 0 === ( $c[1] & 0x80 ) )
            && 0 === ( $c[32] & 0x80 )
            && ! ( 0 === $c[32] && 0 === ( $c[33] & 0x80 ) );
    }

    /**
     * @return array{r:string,s:string,recovery:int}
     */
    private function splitCompact( string $compactHex ): array {
        $bin = hex2bin( $compactHex );
        if ( false === $bin || 65 !== strlen( $bin ) ) {
            throw new RuntimeException( 'Firma compacta inválida (se esperaban 65 bytes).' );
        }

        $header   = ord( $bin[0] );
        $recovery = $header - 31;

        return [
            'r'        => bin2hex( substr( $bin, 1, 32 ) ),
            's'        => bin2hex( substr( $bin, 33, 32 ) ),
            'recovery' => $recovery,
        ];
    }

    private function counterHex( int $n ): string {
        $hex = dechex( $n );
        return ( strlen( $hex ) % 2 !== 0 ) ? '0' . $hex : $hex;
    }
}
