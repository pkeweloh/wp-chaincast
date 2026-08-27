<?php
/**
 * Turns a provider <iframe> into its naked URL, on a line of its own.
 *
 * The blog keeps the iframe and its real player; the chain gets the URL, which
 * is the only form Hive and Steem turn into a player. One single body serves
 * both. An iframe from anywhere else is dropped: the chain sanitizer would drop
 * it anyway, and its URL alone means nothing there.
 *
 * @package Chaincast\Connector\Content
 */

declare(strict_types=1);

namespace Chaincast\Connector\Content;

use League\HTMLToMarkdown\Converter\ConverterInterface;
use League\HTMLToMarkdown\ElementInterface;

final class IframeEmbedConverter implements ConverterInterface {

    public function convert( ElementInterface $element ): string {
        $src = trim( $element->getAttribute( 'src' ) );
        if ( '' === $src || ! EmbedProviders::isEmbeddable( $src ) ) {
            return '';
        }

        return "\n\n" . EmbedProviders::withScheme( $src ) . "\n\n";
    }

    /**
     * @return string[]
     */
    public function getSupportedTags(): array {
        return [ 'iframe' ];
    }
}
