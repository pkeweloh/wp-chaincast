<?php
/**
 * Parsing and validation of graphene beneficiaries (Hive/Steem).
 *
 * Input format (user text): "account:percent, account2:percent". The percent
 * allows decimals (e.g. 2.5). Internally it is converted to a "weight" in basis
 * points (hundredths of a %): 100% = 10000, 5% = 500, 2.5% = 250.
 *
 * Chain rules we validate here:
 *  - at most 8 beneficiaries,
 *  - no repeated accounts,
 *  - sum of weights <= 10000 (the rest stays with the author),
 *  - list SORTED by account ascending (the chain requires it).
 *
 * @package Chaincast\Connector\Content
 */

declare(strict_types=1);

namespace Chaincast\Connector\Content;

use InvalidArgumentException;

final class Beneficiaries {

    /** Chain limit. */
    private const MAX_BENEFICIARIES = 8;

    /** 100% in basis points. */
    private const TOTAL_WEIGHT = 10000;

    /**
     * Converts the user text into a normalized, validated list.
     *
     * @return array<int,array{account:string,weight:int}> Sorted by account. Empty if the input is empty.
     *
     * @throws InvalidArgumentException If any field is invalid (account, percent, sum or limit).
     */
    public static function parse( string $spec ): array {
        $spec = trim( $spec );
        if ( '' === $spec ) {
            return [];
        }

        $result = [];
        $total  = 0;

        foreach ( explode( ',', $spec ) as $entry ) {
            $entry = trim( $entry );
            if ( '' === $entry ) {
                continue;
            }

            $parts = explode( ':', $entry );
            if ( 2 !== count( $parts ) ) {
                throw new InvalidArgumentException( "Formato inválido en '$entry' (usa cuenta:porcentaje)." );
            }

            $account = strtolower( trim( $parts[0] ) );
            $percent = trim( $parts[1] );

            if ( ! self::isValidAccount( $account ) ) {
                throw new InvalidArgumentException( "Cuenta inválida: '$account'." );
            }
            if ( isset( $result[ $account ] ) ) {
                throw new InvalidArgumentException( "Cuenta repetida: '$account'." );
            }
            if ( ! is_numeric( $percent ) ) {
                throw new InvalidArgumentException( "Porcentaje inválido para '$account': '$percent'." );
            }

            $weight = (int) round( (float) $percent * 100 );
            if ( $weight <= 0 || $weight > self::TOTAL_WEIGHT ) {
                throw new InvalidArgumentException( "Porcentaje fuera de rango (0–100) para '$account'." );
            }

            $result[ $account ] = $weight;
            $total             += $weight;
        }

        if ( count( $result ) > self::MAX_BENEFICIARIES ) {
            throw new InvalidArgumentException( 'Máximo ' . self::MAX_BENEFICIARIES . ' beneficiaries.' );
        }
        if ( $total > self::TOTAL_WEIGHT ) {
            throw new InvalidArgumentException( 'La suma de porcentajes no puede superar el 100%.' );
        }

        ksort( $result ); // The chain requires ascending order by account.

        $list = [];
        foreach ( $result as $account => $weight ) {
            $list[] = [ 'account' => $account, 'weight' => $weight ];
        }
        return $list;
    }

    /**
     * Like parse() but never throws: returns [] if the input is invalid.
     * For the publish path (a bad config must not break it).
     *
     * @return array<int,array{account:string,weight:int}>
     */
    public static function parseSafe( string $spec ): array {
        try {
            return self::parse( $spec );
        } catch ( InvalidArgumentException ) {
            return [];
        }
    }

    /** Graphene account name: 3 to 16 chars, a-z0-9- segments separated by dots. */
    private static function isValidAccount( string $account ): bool {
        return 1 === preg_match( '/^(?=.{3,16}$)[a-z][a-z0-9\-]*(\.[a-z][a-z0-9\-]*)*$/', $account );
    }
}
