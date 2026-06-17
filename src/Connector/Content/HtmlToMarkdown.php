<?php
/**
 * Convierte el HTML de WordPress a Markdown (lo que esperan Hive/Steem).
 *
 * Envuelve league/html-to-markdown con opciones sensatas y, opcionalmente,
 * añade un pie de atribución con el enlace canónico de vuelta a la web.
 *
 * @package Chaincast\Connector\Content
 */

declare(strict_types=1);

namespace Chaincast\Connector\Content;

use League\HTMLToMarkdown\HtmlConverter;

final class HtmlToMarkdown {

    private HtmlConverter $converter;

    public function __construct() {
        $this->converter = new HtmlConverter(
            [
                'strip_tags'      => true,   // descarta tags no convertibles en vez de dejar HTML crudo.
                'remove_nodes'    => 'script style',
                'hard_break'      => true,
                'use_autolinks'   => false,
                'header_style'    => 'atx',  // '# H1' en vez de subrayado.
            ]
        );
    }

    public function convert( string $html ): string {
        return trim( $this->converter->convert( $html ) );
    }

    /**
     * Añade un pie ya renderizado al final del Markdown, separado por una línea.
     * Si el pie está vacío, devuelve el cuerpo sin cambios.
     */
    public function appendFooter( string $markdown, string $footerLine ): string {
        $footerLine = trim( $footerLine );
        if ( '' === $footerLine ) {
            return $markdown;
        }
        return $markdown . "\n\n---\n\n" . $footerLine;
    }
}
