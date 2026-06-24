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

use League\HTMLToMarkdown\HtmlConverter;

final class HtmlToMarkdown {

    private HtmlConverter $converter;

    public function __construct() {
        $this->converter = new HtmlConverter(
            [
                'strip_tags'      => true,   // drop non-convertible tags instead of leaving raw HTML.
                'remove_nodes'    => 'script style',
                'hard_break'      => true,
                'use_autolinks'   => false,
                'header_style'    => 'atx',  // '# H1' instead of underline.
            ]
        );
    }

    public function convert( string $html ): string {
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
