<?php
/**
 * Parseo y validación de beneficiaries para graphene (Hive/Steem).
 *
 * Formato de entrada (texto del usuario): "cuenta:porcentaje, cuenta2:porcentaje".
 * El porcentaje admite decimales (p. ej. 2.5). Internamente se convierte a "weight"
 * en puntos base (centésimas de %): 100% = 10000, 5% = 500, 2.5% = 250.
 *
 * Reglas de la cadena que validamos aquí:
 *  - máximo 8 beneficiaries,
 *  - sin cuentas repetidas,
 *  - suma de pesos <= 10000 (el resto se queda para el autor),
 *  - lista ORDENADA por cuenta ascendente (la cadena lo exige).
 *
 * @package Chaincast\Connector\Content
 */

declare(strict_types=1);

namespace Chaincast\Connector\Content;

use InvalidArgumentException;

final class Beneficiaries {

    /** Límite de la cadena. */
    private const MAX_BENEFICIARIES = 8;

    /** 100% en puntos base. */
    private const TOTAL_WEIGHT = 10000;

    /**
     * Convierte el texto del usuario en una lista normalizada y validada.
     *
     * @return array<int,array{account:string,weight:int}> Ordenada por cuenta. Vacía si la entrada está vacía.
     *
     * @throws InvalidArgumentException Si algún campo es inválido (cuenta, porcentaje, suma o límite).
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

        ksort( $result ); // La cadena exige orden ascendente por cuenta.

        $list = [];
        foreach ( $result as $account => $weight ) {
            $list[] = [ 'account' => $account, 'weight' => $weight ];
        }
        return $list;
    }

    /**
     * Como parse() pero nunca lanza: devuelve [] si la entrada es inválida.
     * Para el camino de publicación (no debe romper por una mala config).
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

    /** Nombre de cuenta graphene: 3–16 chars, segmentos a-z0-9- separados por puntos. */
    private static function isValidAccount( string $account ): bool {
        return 1 === preg_match( '/^(?=.{3,16}$)[a-z][a-z0-9\-]*(\.[a-z][a-z0-9\-]*)*$/', $account );
    }
}
