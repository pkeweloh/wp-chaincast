<?php
/**
 * Converts <figure> to a standalone Markdown block.
 *
 * @package Chaincast\Connector\Content
 */

declare(strict_types=1);

namespace Chaincast\Connector\Content;

use League\HTMLToMarkdown\Converter\ConverterInterface;
use League\HTMLToMarkdown\ElementInterface;

final class FigureConverter implements ConverterInterface {

    public function convert( ElementInterface $element ): string {
        $value = trim( $element->getValue() );
        if ( '' === $value ) {
            return '';
        }

        // A block child (blockquote, table) already ends in a blank line, which
        // would stack with the caption's own leading one.
        $value = preg_replace( '/\n{3,}/', "\n\n", $value );

        // Block converters here only append the trailing break: a leading one
        // would double the blank line after the previous block.
        return $value . "\n\n";
    }

    /**
     * @return string[]
     */
    public function getSupportedTags(): array {
        return [ 'figure' ];
    }
}
