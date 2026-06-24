<?php
/**
 * Canonical secp256k1 ECDSA signing for graphene (Hive/Steem).
 *
 * The curve math comes from `simplito/elliptic-php` (pure PHP, requires
 * ext-gmp); this holds ONLY the orchestration: loop until a canonical signature
 * is found (low-S + graphene's is_canonical predicate) and the 65-byte compact
 * packing (recovery header + r + s).
 *
 * The nonce is varied deterministically (personalization = sha256(digest||counter)),
 * so the signature is reproducible for tests without relying on system randomness.
 *
 * @package Chaincast\Core\Crypto
 */

declare(strict_types=1);

namespace Chaincast\Core\Crypto;

use Elliptic\EC;
use RuntimeException;

final class Secp256k1 {

    /** Max attempts to find a canonical signature (in practice, 1-3). */
    private const MAX_ATTEMPTS = 64;

    private EC $ec;

    public function __construct() {
        $this->ec = new EC( 'secp256k1' );
    }

    /**
     * Signs a digest (32-byte hex) with the private key (32-byte hex) and returns
     * the 65-byte compact signature in hex: header(1) + r(32) + s(32).
     *
     * The header = recoveryParam + 31 (27 base + 4 for a compressed key), as
     * Hive/Steem expect.
     */
    public function signCompact( string $digestHex, string $privHex ): string {
        $key = $this->ec->keyFromPrivate( $privHex, 'hex' );

        for ( $attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++ ) {
            $pers = hash( 'sha256', hex2bin( $digestHex . $this->counterHex( $attempt ) ) );

            $sig = $key->sign(
                $digestHex,
                [
                    'canonical' => true, // forces low-S.
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
     * Verifies a compact signature against a public key (hex, compressed or not).
     */
    public function verifyCompact( string $digestHex, string $compactHex, string $pubHex ): bool {
        [ 'r' => $r, 's' => $s ] = $this->splitCompact( $compactHex );
        $key = $this->ec->keyFromPublic( $pubHex, 'hex' );

        return $key->verify( $digestHex, [ 'r' => $r, 's' => $s ] );
    }

    /**
     * Recovers the public key (compressed hex) that produced a compact signature.
     */
    public function recoverPublic( string $digestHex, string $compactHex ): string {
        [ 'r' => $r, 's' => $s, 'recovery' => $recovery ] = $this->splitCompact( $compactHex );

        $pub = $this->ec->recoverPubKey( $digestHex, [ 'r' => $r, 's' => $s ], $recovery );

        return $pub->encode( 'hex', true ); // compressed.
    }

    /**
     * Graphene's is_canonical predicate over the 64 bytes r||s.
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
