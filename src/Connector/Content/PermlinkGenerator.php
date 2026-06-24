<?php
/**
 * Genera permlinks válidos para Hive/Steem.
 *
 * Reglas de la cadena: minúsculas, solo [a-z0-9-], sin guiones dobles ni al
 * inicio/fin, longitud máxima 256. El permlink solo se genera en la primera
 * publicación; después se persiste y se reutiliza, de modo que una edición
 * EDITA el post en vez de crear uno nuevo (la estabilidad la da el guardado,
 * no el contenido del slug).
 *
 * @package Chaincast\Connector\Content
 */

declare(strict_types=1);

namespace Chaincast\Connector\Content;

final class PermlinkGenerator {

    private const MAX_LENGTH = 256;

    /**
     * Permlink limpio a partir del título. El ID de la entrada solo se usa como
     * fallback cuando el título no produce slug (p. ej. título vacío o sin
     * caracteres ASCII), para garantizar un permlink no vacío.
     */
    public function generate( string $title, int $postId ): string {
        $slug = $this->slugify( $title );

        if ( '' === $slug ) {
            return 'post-' . $postId;
        }

        if ( strlen( $slug ) > self::MAX_LENGTH ) {
            $slug = rtrim( substr( $slug, 0, self::MAX_LENGTH ), '-' );
        }

        return $slug;
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
