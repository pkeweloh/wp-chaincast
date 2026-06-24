<?php
/**
 * Graphene binary serialization (Hive/Steem).
 *
 * Our own implementation of the primitives and of the transaction plus
 * operations the plugin needs. Validated byte by byte against golden vectors
 * generated with the `mahdiyari/hive-php` oracle (see
 * tests/fixtures/golden-vectors.json).
 *
 * The internal buffer is raw bytes (PHP binary string); `hex()` exposes them in
 * hexadecimal for debugging and comparison with the vectors.
 *
 * @package Chaincast\Connector\Graphene
 */

declare(strict_types=1);

namespace Chaincast\Connector\Graphene;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class Serializer {

    private string $buffer = '';

    /**
     * Supported operation definitions: name => [op_id, [ [field, type], ... ]].
     * Field order is significant (it is the byte order on the chain).
     *
     * @var array<string,array{0:int,1:array<int,array{0:string,1:string}>}>
     */
    private const OPERATIONS = [
        'comment' => [
            1,
            [
                [ 'parent_author', 'string' ],
                [ 'parent_permlink', 'string' ],
                [ 'author', 'string' ],
                [ 'permlink', 'string' ],
                [ 'title', 'string' ],
                [ 'body', 'string' ],
                [ 'json_metadata', 'string' ],
            ],
        ],
        'vote' => [
            0,
            [
                [ 'voter', 'string' ],
                [ 'author', 'string' ],
                [ 'permlink', 'string' ],
                [ 'weight', 'int16' ],
            ],
        ],
        'delete_comment' => [
            17,
            [
                [ 'author', 'string' ],
                [ 'permlink', 'string' ],
            ],
        ],
        'comment_options' => [
            19,
            [
                [ 'author', 'string' ],
                [ 'permlink', 'string' ],
                [ 'max_accepted_payout', 'asset' ],
                // The field name differs between chains (percent_hbd on Hive,
                // percent_steem_dollars on Steem) but the bytes are identical; the
                // serialization is positional, so the connector passes the value with
                // this fixed key for serialization and the real key only in the broadcast.
                [ 'percent_hbd', 'uint16' ],
                [ 'allow_votes', 'bool' ],
                [ 'allow_curation_rewards', 'bool' ],
                [ 'extensions', 'comment_options_extensions' ],
            ],
        ],
    ];

    /** Accumulated raw bytes. */
    public function bytes(): string {
        return $this->buffer;
    }

    /** Hexadecimal representation of the buffer. */
    public function hex(): string {
        return bin2hex( $this->buffer );
    }

    // Primitives

    public function uint8( int $value ): self {
        $this->buffer .= chr( $value & 0xFF );
        return $this;
    }

    public function uint16( int $value ): self {
        $this->buffer .= pack( 'v', $value & 0xFFFF ); // little-endian.
        return $this;
    }

    public function int16( int $value ): self {
        $this->buffer .= pack( 'v', $value & 0xFFFF );
        return $this;
    }

    public function uint32( int $value ): self {
        $this->buffer .= pack( 'V', $value & 0xFFFFFFFF ); // little-endian.
        return $this;
    }

    /** Unsigned 64-bit integer, little-endian (asset amounts). */
    public function uint64( int $value ): self {
        $this->buffer .= pack( 'P', $value ); // little-endian 64-bit.
        return $this;
    }

    /** Boolean: 1 byte (0/1). */
    public function boolean( bool $value ): self {
        return $this->uint8( $value ? 1 : 0 );
    }

    /**
     * Graphene asset: int64 LE amount + uint8 precision + symbol in 7 bytes
     * (zero-padded). Input: "1000000.000 HBD".
     */
    public function asset( string $asset ): self {
        $parts  = explode( ' ', trim( $asset ) );
        $amount = $parts[0] ?? '0';
        $symbol = $parts[1] ?? '';

        // Legacy serialization: Hive keeps the old symbols for binary
        // compatibility. "HBD" serializes as "SBD" and "HIVE" as "STEEM" (same on
        // both chains). The broadcast JSON does use HBD/HIVE.
        $symbol = match ( $symbol ) {
            'HBD'  => 'SBD',
            'HIVE' => 'STEEM',
            default => $symbol,
        };

        $frac      = explode( '.', $amount );
        $precision = isset( $frac[1] ) ? strlen( $frac[1] ) : 0;
        // Exact integer amount without going through float: "1000000.000" -> 1000000000.
        $raw = (int) ( ( $frac[0] ?? '0' ) . ( $frac[1] ?? '' ) );

        $this->uint64( $raw );
        $this->uint8( $precision );
        for ( $i = 0; $i < 7; $i++ ) {
            $this->uint8( $i < strlen( $symbol ) ? ord( $symbol[ $i ] ) : 0 );
        }
        return $this;
    }

    /**
     * comment_options extensions. We only support the beneficiaries extension
     * (variant tag 0). Input structure (same as the broadcast):
     *   [] or [ [ 0, [ 'beneficiaries' => [ ['account'=>..,'weight'=>..], ... ] ] ] ].
     * Beneficiaries must come sorted by account (the chain requires it).
     *
     * @param array<int,mixed> $extensions
     */
    public function commentOptionsExtensions( array $extensions ): self {
        $this->varuint32( count( $extensions ) );
        foreach ( $extensions as $extension ) {
            [ $tag, $value ] = $extension;
            $this->varuint32( (int) $tag );
            $beneficiaries = is_array( $value ) ? ( $value['beneficiaries'] ?? [] ) : [];
            $this->varuint32( count( $beneficiaries ) );
            foreach ( $beneficiaries as $beneficiary ) {
                $this->string( (string) $beneficiary['account'] );
                $this->uint16( (int) $beneficiary['weight'] );
            }
        }
        return $this;
    }

    /**
     * Variable-length integer (unsigned LEB128), like graphene's writeVariant32.
     */
    public function varuint32( int $value ): self {
        if ( $value < 0 ) {
            throw new InvalidArgumentException( 'varuint32 does not accept negatives.' );
        }
        do {
            $byte  = $value & 0x7F;
            $value >>= 7;
            if ( $value > 0 ) {
                $byte |= 0x80;
            }
            $this->buffer .= chr( $byte );
        } while ( $value > 0 );

        return $this;
    }

    /**
     * String: varint prefix with the length IN BYTES + the raw bytes.
     */
    public function string( string $value ): self {
        $this->varuint32( strlen( $value ) );
        $this->buffer .= $value;
        return $this;
    }

    /**
     * Transaction header date: UTC timestamp as little-endian uint32.
     * Input format: 'Y-m-d\TH:i:s' (no zone, interpreted as UTC).
     */
    public function date( string $iso ): self {
        $dt = DateTimeImmutable::createFromFormat(
            'Y-m-d\TH:i:s',
            $iso,
            new DateTimeZone( 'UTC' )
        );
        if ( false === $dt ) {
            throw new InvalidArgumentException( "Invalid date: $iso" );
        }
        return $this->uint32( $dt->getTimestamp() );
    }

    // High-level structures

    /**
     * Serializes an operation: varint(op_id) + fields in order.
     *
     * @param string              $name   Operation name (e.g. 'comment').
     * @param array<string,mixed> $fields Values by field name.
     */
    public function operation( string $name, array $fields ): self {
        if ( ! isset( self::OPERATIONS[ $name ] ) ) {
            throw new InvalidArgumentException( "Unsupported operation: $name" );
        }

        [ $opId, $schema ] = self::OPERATIONS[ $name ];
        $this->varuint32( $opId );

        foreach ( $schema as [ $field, $type ] ) {
            if ( ! array_key_exists( $field, $fields ) ) {
                throw new InvalidArgumentException( "Missing field '$field' in operation '$name'." );
            }
            $this->writeTyped( $type, $fields[ $field ] );
        }

        return $this;
    }

    /**
     * Serializes a full transaction ready to sign.
     *
     * @param int                                        $refBlockNum
     * @param int                                        $refBlockPrefix
     * @param string                                     $expiration   ISO 'Y-m-d\TH:i:s' UTC.
     * @param array<int,array{0:string,1:array<string,mixed>}> $operations List of [name, fields].
     */
    public function transaction( int $refBlockNum, int $refBlockPrefix, string $expiration, array $operations ): self {
        $this->uint16( $refBlockNum );
        $this->uint32( $refBlockPrefix );
        $this->date( $expiration );

        $this->varuint32( count( $operations ) );
        foreach ( $operations as [ $name, $fields ] ) {
            $this->operation( $name, $fields );
        }

        // extensions: empty list.
        $this->varuint32( 0 );

        return $this;
    }

    private function writeTyped( string $type, mixed $value ): void {
        match ( $type ) {
            'string' => $this->string( (string) $value ),
            'uint8'  => $this->uint8( (int) $value ),
            'uint16' => $this->uint16( (int) $value ),
            'int16'  => $this->int16( (int) $value ),
            'uint32' => $this->uint32( (int) $value ),
            'asset'  => $this->asset( (string) $value ),
            'bool'   => $this->boolean( (bool) $value ),
            'comment_options_extensions' => $this->commentOptionsExtensions( is_array( $value ) ? $value : [] ),
            default  => throw new InvalidArgumentException( "Unsupported type: $type" ),
        };
    }
}
