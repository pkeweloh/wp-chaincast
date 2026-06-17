<?php
/**
 * Mapeo de categorías/etiquetas de WordPress a tags o comunidades de la cadena.
 *
 * El usuario asocia en Ajustes (por cada cadena) cada categoría de WordPress a un
 * destino, p. ej. "noticias → hive-167922", para que las entradas de esa
 * categoría aterricen en esa comunidad. Se aplica al construir los tags
 * automáticos (cuando la entrada no tiene tags propios). Si una categoría no está
 * mapeada, se usa su slug tal cual (comportamiento por defecto, sin configurar).
 *
 * Clase pura (sin dependencias de WordPress) para poder validarla en tests.
 *
 * @package Chaincast\Connector\Content
 */

declare(strict_types=1);

namespace Chaincast\Connector\Content;

final class CategoryMap {

    /**
     * Traduce una lista de slugs aplicando el mapa. Los no mapeados se conservan.
     * Devuelve la lista sin vacíos ni duplicados, preservando el orden.
     *
     * @param array<string,string> $map
     * @param string[]             $slugs
     *
     * @return string[]
     */
    public static function apply( array $map, array $slugs ): array {
        $out = [];
        foreach ( $slugs as $slug ) {
            $out[] = $map[ strtolower( $slug ) ] ?? $slug;
        }
        return array_values( array_unique( array_filter( $out ) ) );
    }
}
