<?php
/**
 * Converts <figcaption> to an italic paragraph of its own, the usual caption
 * convention on Hive/Steem.
 *
 * @package Chaincast\Connector\Content
 */

declare(strict_types=1);

namespace Chaincast\Connector\Content;

use League\HTMLToMarkdown\Converter\ConverterInterface;
use League\HTMLToMarkdown\ElementInterface;

final class FigcaptionConverter implements ConverterInterface {

    public function convert( ElementInterface $element ): string {
        $value = trim( $element->getValue() );
        if ( '' === $value ) {
            return '';
        }

        // Emphasis cannot span a blank line, so keep the caption as one paragraph.
        $value = preg_replace( '/\n{2,}/', "\n", $value );

        return "\n\n*" . $value . "*\n\n";
    }

    /**
     * @return string[]
     */
    public function getSupportedTags(): array {
        return [ 'figcaption' ];
    }
}
