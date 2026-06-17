<?php
/**
 * Clave pública graphene (formato textual `STM<base58(pubkey+checksum)>`).
 *
 * El checksum son los primeros 4 bytes de RIPEMD-160 de la clave comprimida
 * (33 bytes). El prefijo de dirección depende de la cadena: 'STM' tanto en Hive
 * como en Steem (difieren en chain_id y nodos, no en el prefijo).
 *
 * @package Chaincast\Connector\Graphene
 */

declare(strict_types=1);

namespace Chaincast\Connector\Graphene;

use Elliptic\EC;
use StephenHill\Base58;

final class PublicKey {

    public function __construct(
        private string $compressedHex,
        private string $prefix = 'STM',
    ) {
    }

    public static function fromPrivateHex( string $privHex, string $prefix = 'STM' ): self {
        $ec  = new EC( 'secp256k1' );
        $key = $ec->keyFromPrivate( $privHex, 'hex' );

        return new self( $key->getPublic( true, 'hex' ), $prefix );
    }

    public function compressedHex(): string {
        return $this->compressedHex;
    }

    /**
     * Representación textual: STM + base58(pubkey(33) + ripemd160(pubkey)[:4]).
     */
    public function toString(): string {
        $raw      = hex2bin( $this->compressedHex );
        $checksum = substr( hash( 'ripemd160', $raw, true ), 0, 4 );

        return $this->prefix . ( new Base58() )->encode( $raw . $checksum );
    }

    public function __toString(): string {
        return $this->toString();
    }
}
