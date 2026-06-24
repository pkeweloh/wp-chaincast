<?php
/**
 * Builds a chain-neutral PostPayload from a WordPress post.
 *
 * Renders the content (Gutenberg blocks included) to HTML, converts it to
 * Markdown with a canonical footer, and gathers tags (primary category first),
 * images (featured plus inline) and the canonical URL.
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
     * @param string                $footerTemplate       Footer template with {site}/{url} placeholders; empty: no footer.
     * @param string                $beneficiariesDefault Global beneficiaries (text) used when the post has no override.
     * @param array<string,string>  $categoryMap          WP slug => chain tag/community map for the automatic tags.
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
     * Chain tags. On Hive/Steem the list is flat and the first entry is the
     * community (parent_permlink); the rest are discovery keywords:
     *  - First tag: the WP primary category, mapped per chain (the community is
     *    the only part that differs between chains). Just one, as Hive requires.
     *  - Remaining tags: the WP tags as-is. They are universal (same on Hive and
     *    Steem), so they are NOT mapped.
     * No category: the connector's defaultTag supplies the community later on.
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
     * The post's "primary" category (the one that sets the community/parent_permlink).
     * WordPress core has no such concept: with several categories they are all equal.
     * We honor Yoast SEO or Rank Math's primary category when defined (both very
     * common plugins); otherwise we use the first one WordPress returns.
     *
     * @return string|null Category slug, or null if the post has no categories.
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
     * Featured image plus images embedded in the rendered content.
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
