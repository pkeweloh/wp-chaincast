<?php
/**
 * Converts WordPress HTML to Markdown (what Hive/Steem expect).
 *
 * Wraps league/html-to-markdown with sensible options and, optionally, appends
 * an attribution footer with the canonical link back to the site.
 *
 * @package Chaincast\Connector\Content
 */

declare(strict_types=1);

namespace Chaincast\Connector\Content;

use League\HTMLToMarkdown\Converter\TableConverter;
use League\HTMLToMarkdown\HtmlConverter;

final class HtmlToMarkdown {

    private HtmlConverter $converter;
    private LinkConverter $links;

    public function __construct(
        MediaLinkConverter $media = new MediaLinkConverter(),
    ) {
        $this->converter = new HtmlConverter(
            [
                'strip_tags'                  => true,   // drop non-convertible tags instead of leaving raw HTML.
                'remove_nodes'                => 'script style',
                'hard_break'                  => false,  // counter-intuitive: false is the real hard break, true emits a soft one.
                'use_autolinks'               => false,
                'header_style'                => 'atx',  // '# H1' instead of underline.
                LinkConverter::SHORTEN_OPTION => false,
            ]
        );

        $this->links = new LinkConverter();

        $environment = $this->converter->getEnvironment();
        // Not part of the library defaults; without it table cells collapse into a
        // single run of text.
        $environment->addConverter( new TableConverter() );
        $environment->addConverter( $this->links );
        $environment->addConverter( $media );
        $environment->addConverter( new FigureConverter() );
        $environment->addConverter( new FigcaptionConverter() );
        $environment->addConverter( new IframeEmbedConverter() );
    }

    /**
     * Decided per post, not per site: on a long article every byte counts, on a
     * short one the author may prefer the URL as written.
     */
    public function shortenBareUrls( bool $enabled ): void {
        $this->converter->getConfig()->setOption( LinkConverter::SHORTEN_OPTION, $enabled );
    }

    /**
     * Rewrites the href of links the resolver claims, which is how a link to
     * another post of the site is pointed at that same post on the destination
     * chain. Null: every link travels as written.
     *
     * @param null|callable(string):string $resolver
     */
    public function rewriteLinks( ?callable $resolver ): void {
        $this->links->resolveHrefWith( $resolver );
    }

    public function convert( string $html ): string {
        // A CRLF source leaks its carriage returns as leading spaces on every line.
        $html = str_replace( [ "\r\n", "\r" ], "\n", $html );
        return trim( $this->converter->convert( $html ) );
    }

    /**
     * Appends an already-rendered footer to the end of the Markdown, separated by
     * a rule. If the footer is empty, returns the body unchanged.
     */
    public function appendFooter( string $markdown, string $footerLine ): string {
        $footerLine = trim( $footerLine );
        if ( '' === $footerLine ) {
            return $markdown;
        }
        return $markdown . "\n\n---\n\n" . $footerLine;
    }
}
