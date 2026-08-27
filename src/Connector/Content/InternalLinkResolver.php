<?php
/**
 * Points a link to one of the site's own posts at that same post ON THE CHAIN.
 *
 * A single body goes out to WordPress, Hive and Steem. When it links to another
 * article of the blog, the chain reader is sent out of the ecosystem, which is
 * exactly what curators read as extractive. So per destination chain, a link to
 * a post already published there becomes its chain URL; a post NOT published
 * there keeps the link to the blog, which is a common case, not an edge one.
 *
 * The permlink is never derived from the WP slug: old posts carry permlinks that
 * no longer match it, so the one recorded when publishing is the only truth.
 *
 * @package Chaincast\Connector\Content
 */

declare(strict_types=1);

namespace Chaincast\Connector\Content;

use Chaincast\Core\State\PostState;

final class InternalLinkResolver {

    /**
     * Uploads live under the site's own host too, and rewriting one of those would
     * break every image in the body.
     */
    private const SKIPPED_PATHS = [ '/wp-content/', '/wp-admin/', '/wp-includes/', '/wp-json/' ];

    private ?string $siteHost;

    public function __construct(
        private string $connectorId,
        private PostState $state = new PostState(),
        ?string $siteHost = null,
    ) {
        $this->siteHost = $siteHost;
    }

    /**
     * @return string Chain URL, or '' to leave the link as it is.
     */
    public function __invoke( string $url ): string {
        if ( ! $this->isInternal( $url ) ) {
            return '';
        }

        $postId = (int) url_to_postid( $url );
        if ( $postId <= 0 ) {
            return '';
        }

        $state = $this->state->get( $postId, $this->connectorId );
        if ( PostState::STATUS_PUBLISHED !== ( $state['status'] ?? '' ) ) {
            return '';
        }

        $chainUrl = $state['url'] ?? '';

        return is_string( $chainUrl ) ? $chainUrl : '';
    }

    private function isInternal( string $url ): bool {
        $path = (string) parse_url( $url, PHP_URL_PATH );
        foreach ( self::SKIPPED_PATHS as $skipped ) {
            if ( str_contains( $path, $skipped ) ) {
                return false;
            }
        }

        $host = parse_url( $url, PHP_URL_HOST );
        if ( ! is_string( $host ) || '' === $host ) {
            // A root-relative href can only be internal.
            return str_starts_with( $url, '/' );
        }

        return $this->normalize( $host ) === $this->normalize( $this->siteHost() );
    }

    private function siteHost(): string {
        if ( null === $this->siteHost ) {
            $host             = parse_url( (string) home_url(), PHP_URL_HOST );
            $this->siteHost = is_string( $host ) ? $host : '';
        }

        return $this->siteHost;
    }

    private function normalize( string $host ): string {
        return (string) preg_replace( '/^www\./', '', strtolower( $host ) );
    }
}
