<?php
/**
 * Genera permlinks válidos para Hive/Steem.
 *
 * Reglas de la cadena: minúsculas, solo [a-z0-9-], sin guiones dobles ni al
 * inicio/fin, longitud máxima 256. El permlink debe ser estable para que una
 * re-publicación de la misma entrada EDITE el post en vez de crear uno nuevo;
 * por eso se ata al ID de la entrada de WordPress.
 *
 * @package Chaincast\Connector\Content
 */

declare(strict_types=1);

namespace Chaincast\Connector\Content;

final class PermlinkGenerator {

    private const MAX_LENGTH = 256;

    /**
     * Permlink determinista a partir del título y el ID de la entrada.
     * El sufijo con el ID garantiza unicidad por autor (dos entradas con el
     * mismo título no colisionan) y estabilidad frente a ediciones del cuerpo.
     */
    public function generate( string $title, int $postId ): string {
        $slug = $this->slugify( $title );
        $suffix = (string) $postId;

        if ( '' === $slug ) {
            return 'post-' . $suffix;
        }

        // Reserva espacio para el sufijo "-{id}".
        $maxSlug = self::MAX_LENGTH - strlen( $suffix ) - 1;
        if ( strlen( $slug ) > $maxSlug ) {
            $slug = rtrim( substr( $slug, 0, $maxSlug ), '-' );
        }

        return $slug . '-' . $suffix;
    }

    /**
     * Convierte un texto arbitrario en un slug compatible con la cadena.
     */
    public function slugify( string $text ): string {
        $text = $this->transliterate( $text );
        $text = strtolower( $text );
        $text = preg_replace( '/[^a-z0-9]+/', '-', $text ) ?? '';
        return trim( $text, '-' );
    }

    /** Mapa de transliteración de caracteres latinos comunes a ASCII. */
    private const TRANSLIT = [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ñ' => 'n', 'ç' => 'c', 'ß' => 'ss', 'ý' => 'y', 'ÿ' => 'y',
    ];

    /**
     * Translitera acentos latinos a ASCII de forma determinista (sin depender de
     * iconv, cuyo //TRANSLIT varía entre plataformas). Lo no mapeable se descarta.
     */
    private function transliterate( string $text ): string {
        $text = strtr( mb_strtolower( $text, 'UTF-8' ), self::TRANSLIT );
        return preg_replace( '/[^\x20-\x7E]/', '', $text ) ?? '';
    }
}
