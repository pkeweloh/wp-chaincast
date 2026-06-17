<?php
/**
 * Construye un PostPayload neutro a partir de una entrada de WordPress.
 *
 * Renderiza el contenido (incluye bloques Gutenberg) a HTML, lo convierte a
 * Markdown con pie canónico, y reúne tags (categoría principal primero), imágenes
 * (destacada + incrustadas) y el enlace canónico.
 *
 * @package Chaincast\Connector
 */

declare(strict_types=1);

namespace Chaincast\Connector;

use Chaincast\Connector\Content\Beneficiaries;
use Chaincast\Connector\Content\CategoryMap;
use Chaincast\Connector\Content\HtmlToMarkdown;
use WP_Post;

final class PayloadFactory {

    public function __construct(
        private HtmlToMarkdown $markdown,
    ) {
    }

    /**
     * @param string                $footerTemplate       Plantilla del pie con marcadores {site}/{url}; vacía = sin pie.
     * @param string                $beneficiariesDefault Beneficiaries globales (texto) usados si la entrada no tiene override.
     * @param array<string,string>  $categoryMap          Mapa slug WP => tag/comunidad para los tags automáticos.
     */
    public function fromPost( WP_Post $post, string $siteName, string $permlinkOverride = '', string $footerTemplate = '', string $beneficiariesDefault = '', array $categoryMap = [] ): PostPayload {
        $canonical = (string) get_permalink( $post );
        $html      = (string) apply_filters( 'the_content', $post->post_content );
        $body      = $this->markdown->convert( $html );

        if ( '' !== trim( $footerTemplate ) ) {
            $label      = '' !== $siteName ? $siteName : $canonical;
            $footerLine = strtr( $footerTemplate, [ '{site}' => $label, '{url}' => $canonical ] );
            $body       = $this->markdown->appendFooter( $body, $footerLine );
        }

        $extra = [];
        if ( '' !== $permlinkOverride ) {
            $extra['permlink'] = $permlinkOverride;
        }

        return new PostPayload(
            title: get_the_title( $post ),
            body: $body,
            tags: $this->tags( $post, $categoryMap ),
            images: $this->images( $post, $html ),
            author: (string) get_the_author_meta( 'display_name', (int) $post->post_author ),
            canonicalUrl: $canonical,
            wpPostId: (int) $post->ID,
            beneficiaries: Beneficiaries::parseSafe( $beneficiariesDefault ),
            extra: $extra,
        );
    }

    /**
     * Tags para la cadena. En Hive/Steem la lista es plana y el 1º es la comunidad
     * (parent_permlink); el resto son palabras clave de descubrimiento. El reparto:
     *  - 1er tag = la **categoría primaria** de WP, traducida por el mapa per-cadena
     *    (es lo único que difiere entre cadenas: la comunidad). Una sola, como exige Hive.
     *  - Tags siguientes = las **etiquetas** de WP tal cual. Son universales (valen igual
     *    en Hive y Steem), así que NO se mapean.
     * Sin categoría → la comunidad la pone el `defaultTag` del conector más adelante.
     *
     * @param array<string,string> $categoryMap
     *
     * @return string[]
     */
    private function tags( WP_Post $post, array $categoryMap ): array {
        $primary = $this->primaryCategory( $post );
        $tags    = null !== $primary ? CategoryMap::apply( $categoryMap, [ $primary ] ) : [];

        $postTags = get_the_tags( $post->ID );
        if ( is_array( $postTags ) ) {
            foreach ( $postTags as $tag ) {
                $tags[] = (string) $tag->slug;
            }
        }

        return array_values( array_unique( array_filter( $tags ) ) );
    }

    /**
     * Categoría "primaria" del post (la que marca la comunidad/parent_permlink).
     * WordPress *core* no tiene este concepto: si hay varias categorías, son iguales.
     * Respetamos la categoría primaria de Yoast SEO o Rank Math si está definida
     * (plugins muy extendidos); si no, usamos la primera que devuelve WordPress.
     *
     * @return string|null Slug de la categoría, o null si el post no tiene categorías.
     */
    private function primaryCategory( WP_Post $post ): ?string {
        $categories = get_the_category( $post->ID );
        if ( empty( $categories ) ) {
            return null;
        }

        $primaryId = (int) ( get_post_meta( $post->ID, '_yoast_wpseo_primary_category', true )
            ?: get_post_meta( $post->ID, 'rank_math_primary_category', true ) );
        if ( $primaryId > 0 ) {
            foreach ( $categories as $cat ) {
                if ( (int) $cat->term_id === $primaryId ) {
                    return (string) $cat->slug;
                }
            }
        }

        return (string) $categories[0]->slug;
    }

    /**
     * Imagen destacada + imágenes incrustadas en el contenido renderizado.
     *
     * @return string[]
     */
    private function images( WP_Post $post, string $html ): array {
        $images = [];

        $featured = get_the_post_thumbnail_url( $post, 'full' );
        if ( is_string( $featured ) && '' !== $featured ) {
            $images[] = $featured;
        }

        if ( preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $matches ) ) {
            foreach ( $matches[1] as $src ) {
                $images[] = $src;
            }
        }

        return array_values( array_unique( $images ) );
    }
}
