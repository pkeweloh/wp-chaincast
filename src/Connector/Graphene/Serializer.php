<?php
/**
 * Serialización binaria graphene (Hive/Steem).
 *
 * Implementación propia de los primitivos y de la transacción + operaciones que
 * necesita el plugin. Se valida byte-a-byte contra vectores golden generados con
 * el oráculo `mahdiyari/hive-php` (ver tests/fixtures/golden-vectors.json).
 *
 * El buffer interno son bytes crudos (string binario de PHP); `hex()` los expone
 * en hexadecimal para depuración y comparación con los vectores.
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
     * Definición de operaciones soportadas: nombre => [op_id, [ [campo, tipo], ... ]].
     * El orden de los campos es significativo (es el orden de bytes en la cadena).
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
                // El nombre del campo difiere entre cadenas (percent_hbd en Hive,
                // percent_steem_dollars en Steem) pero los bytes son idénticos; la
                // serialización es posicional, así que el conector pasa el valor con
                // esta clave fija para serializar y la clave real solo en el broadcast.
                [ 'percent_hbd', 'uint16' ],
                [ 'allow_votes', 'bool' ],
                [ 'allow_curation_rewards', 'bool' ],
                [ 'extensions', 'comment_options_extensions' ],
            ],
        ],
    ];

    /** Bytes crudos acumulados. */
    public function bytes(): string {
        return $this->buffer;
    }

    /** Representación hexadecimal del buffer. */
    public function hex(): string {
        return bin2hex( $this->buffer );
    }

    // ---- Primitivos ----

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

    /** Entero de 64 bits sin signo, little-endian (importes de asset). */
    public function uint64( int $value ): self {
        $this->buffer .= pack( 'P', $value ); // little-endian 64-bit.
        return $this;
    }

    /** Booleano: 1 byte (0/1). */
    public function boolean( bool $value ): self {
        return $this->uint8( $value ? 1 : 0 );
    }

    /**
     * Asset graphene: importe int64 LE + precisión uint8 + símbolo en 7 bytes
     * (rellenado con ceros). Entrada: "1000000.000 HBD".
     */
    public function asset( string $asset ): self {
        $parts  = explode( ' ', trim( $asset ) );
        $amount = $parts[0] ?? '0';
        $symbol = $parts[1] ?? '';

        // Serialización legacy: Hive conserva los símbolos antiguos por
        // compatibilidad binaria. "HBD" se serializa como "SBD" y "HIVE" como
        // "STEEM" (igual en ambas cadenas). El JSON del broadcast sí usa HBD/HIVE.
        $symbol = match ( $symbol ) {
            'HBD'  => 'SBD',
            'HIVE' => 'STEEM',
            default => $symbol,
        };

        $frac      = explode( '.', $amount );
        $precision = isset( $frac[1] ) ? strlen( $frac[1] ) : 0;
        // Importe entero exacto sin pasar por float: "1000000.000" -> 1000000000.
        $raw = (int) ( ( $frac[0] ?? '0' ) . ( $frac[1] ?? '' ) );

        $this->uint64( $raw );
        $this->uint8( $precision );
        for ( $i = 0; $i < 7; $i++ ) {
            $this->uint8( $i < strlen( $symbol ) ? ord( $symbol[ $i ] ) : 0 );
        }
        return $this;
    }

    /**
     * Extensiones de comment_options. Solo soportamos la extensión de
     * beneficiaries (variant tag 0). Estructura de entrada (igual que el broadcast):
     *   [] o [ [ 0, [ 'beneficiaries' => [ ['account'=>..,'weight'=>..], ... ] ] ] ].
     * Los beneficiaries deben venir ordenados por cuenta (lo exige la cadena).
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
     * Entero de longitud variable (LEB128 sin signo), como el writeVariant32 de graphene.
     */
    public function varuint32( int $value ): self {
        if ( $value < 0 ) {
            throw new InvalidArgumentException( 'varuint32 no admite negativos.' );
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
     * Cadena: prefijo varint con la longitud EN BYTES + los bytes crudos.
     */
    public function string( string $value ): self {
        $this->varuint32( strlen( $value ) );
        $this->buffer .= $value;
        return $this;
    }

    /**
     * Fecha de cabecera de transacción: timestamp UTC como uint32 little-endian.
     * Formato de entrada: 'Y-m-d\TH:i:s' (sin zona, se interpreta como UTC).
     */
    public function date( string $iso ): self {
        $dt = DateTimeImmutable::createFromFormat(
            'Y-m-d\TH:i:s',
            $iso,
            new DateTimeZone( 'UTC' )
        );
        if ( false === $dt ) {
            throw new InvalidArgumentException( "Fecha inválida: $iso" );
        }
        return $this->uint32( $dt->getTimestamp() );
    }

    // ---- Estructuras de alto nivel ----

    /**
     * Serializa una operación: varint(op_id) + campos en orden.
     *
     * @param string              $name   Nombre de la operación (p. ej. 'comment').
     * @param array<string,mixed> $fields Valores por nombre de campo.
     */
    public function operation( string $name, array $fields ): self {
        if ( ! isset( self::OPERATIONS[ $name ] ) ) {
            throw new InvalidArgumentException( "Operación no soportada: $name" );
        }

        [ $opId, $schema ] = self::OPERATIONS[ $name ];
        $this->varuint32( $opId );

        foreach ( $schema as [ $field, $type ] ) {
            if ( ! array_key_exists( $field, $fields ) ) {
                throw new InvalidArgumentException( "Falta el campo '$field' en la operación '$name'." );
            }
            $this->writeTyped( $type, $fields[ $field ] );
        }

        return $this;
    }

    /**
     * Serializa una transacción completa lista para firmar.
     *
     * @param int                                        $refBlockNum
     * @param int                                        $refBlockPrefix
     * @param string                                     $expiration   ISO 'Y-m-d\TH:i:s' UTC.
     * @param array<int,array{0:string,1:array<string,mixed>}> $operations Lista de [nombre, campos].
     */
    public function transaction( int $refBlockNum, int $refBlockPrefix, string $expiration, array $operations ): self {
        $this->uint16( $refBlockNum );
        $this->uint32( $refBlockPrefix );
        $this->date( $expiration );

        $this->varuint32( count( $operations ) );
        foreach ( $operations as [ $name, $fields ] ) {
            $this->operation( $name, $fields );
        }

        // extensions: lista vacía.
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
            default  => throw new InvalidArgumentException( "Tipo no soportado: $type" ),
        };
    }
}
