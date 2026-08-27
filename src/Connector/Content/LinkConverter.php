<?php
/**
 * Link conversion with the WordPress-specific adjustments.
 *
 * The file block renders its link twice, once with the descriptive text and once
 * as a "Download" button, which on the chain is the same URL twice in a row: the
 * button goes. A bare link to a video provider is emitted naked, because that is
 * the only form the chains turn into a player. An internal link can be pointed at
 * the same article on the destination chain, when a resolver is given. And when
 * the SHORTEN_OPTION is on, a link whose visible text is the bare URL is
 * relabelled with its domain, which reads better and saves bytes in a list of
 * sources. Everything else converts as usual.
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

    /** @var null|callable(string):string Href in, replacement href out ('' to keep it). */
    private $resolver = null;

    /**
     * @param null|callable(string):string $resolver
     */
    public function resolveHrefWith( ?callable $resolver ): void {
        $this->resolver = $resolver;
    }

    public function convert( ElementInterface $element ): string {
        if ( str_contains( $element->getAttribute( 'class' ), self::FILE_BUTTON_CLASS ) ) {
            return '';
        }

        $original = $element->getAttribute( 'href' );
        $href     = $original;
        $text     = trim( $element->getValue() );

        $replacement = $this->resolve( $href );
        if ( '' !== $replacement ) {
            // The text was the URL itself, so it has to travel to the new target too.
            if ( $this->isBareUrl( $text ) ) {
                $text = $replacement;
            }
            $href = $replacement;
        }

        // Naked and alone in its paragraph: the chains build the player from this
        // and from nothing else. Shortening it here would kill the video.
        if ( $this->isBareUrl( $text ) && EmbedProviders::isEmbeddable( $href ) ) {
            return EmbedProviders::withScheme( $href );
        }

        if ( $this->config->getOption( self::SHORTEN_OPTION, false ) && $this->isBareUrl( $text ) ) {
            $host = $this->host( $href );
            if ( '' !== $host ) {
                return '[' . $host . '](' . $href . ')';
            }
        }

        if ( $href === $original ) {
            return parent::convert( $element );
        }

        return '[' . $text . '](' . $href . ')';
    }

    private function resolve( string $href ): string {
        if ( '' === $href || null === $this->resolver ) {
            return '';
        }
        return (string) ( $this->resolver )( $href );
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
