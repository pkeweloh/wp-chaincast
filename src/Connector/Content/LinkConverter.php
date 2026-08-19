<?php
/**
 * Link conversion with two WordPress-specific adjustments.
 *
 * The file block renders its link twice, once with the descriptive text and once
 * as a "Download" button, which on the chain is the same URL twice in a row: the
 * button goes. And when the SHORTEN_OPTION is on, a link whose visible text is
 * the bare URL is relabelled with its domain, which reads better and saves bytes
 * in a list of sources. Everything else converts as usual.
 *
 * @package Chaincast\Connector\Content
 */

declare(strict_types=1);

namespace Chaincast\Connector\Content;

use League\HTMLToMarkdown\Converter\LinkConverter as BaseLinkConverter;
use League\HTMLToMarkdown\ElementInterface;

final class LinkConverter extends BaseLinkConverter {

    /** Per-conversion option, so it can be decided post by post. */
    public const SHORTEN_OPTION = 'chaincast_shorten_bare_urls';

    private const FILE_BUTTON_CLASS = 'wp-block-file__button';

    public function convert( ElementInterface $element ): string {
        if ( str_contains( $element->getAttribute( 'class' ), self::FILE_BUTTON_CLASS ) ) {
            return '';
        }

        if ( $this->config->getOption( self::SHORTEN_OPTION, false ) ) {
            $href = $element->getAttribute( 'href' );
            $host = $this->isBareUrl( trim( $element->getValue() ) ) ? $this->host( $href ) : '';
            if ( '' !== $host ) {
                return '[' . $host . '](' . $href . ')';
            }
        }

        return parent::convert( $element );
    }

    private function isBareUrl( string $text ): bool {
        return 1 === preg_match( '#^https?://\S+$#', $text );
    }

    private function host( string $url ): string {
        $host = parse_url( $url, PHP_URL_HOST );
        if ( ! is_string( $host ) || '' === $host ) {
            return '';
        }
        return (string) preg_replace( '/^www\./', '', $host );
    }
}
