<?php
/**
 * Graphene public key (textual format `STM<base58(pubkey+checksum)>`).
 *
 * The checksum is the first 4 bytes of RIPEMD-160 of the compressed key
 * (33 bytes). The address prefix depends on the chain: 'STM' on both Hive and
 * Steem (they differ in chain_id and nodes, not in the prefix).
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
     * Textual representation: STM + base58(pubkey(33) + ripemd160(pubkey)[:4]).
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
