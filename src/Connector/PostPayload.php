<?php
/**
 * Chain-neutral DTO of the content to publish.
 *
 * It is independent of any chain: each connector translates it to its own
 * format (the graphene comment op, an EVM publication, etc.). The core builds
 * this object from the WordPress post.
 *
 * @package Chaincast\Connector
 */

declare(strict_types=1);

namespace Chaincast\Connector;

final class PostPayload {

    /**
     * @param string               $title         Post title.
     * @param string               $body          Body in Markdown.
     * @param string[]             $tags          Tags (the first is usually the primary category).
     * @param string[]             $images        Absolute image URLs.
     * @param string               $author        Author on the chain (e.g. Hive account).
     * @param string               $canonicalUrl  Canonical link back to the site (SEO/attribution).
     * @param int                  $wpPostId      WordPress post ID (idempotency).
     * @param array<int,array{account:string,weight:int}> $beneficiaries Validated, ordered reward
     *        split list (weight in basis points). Empty: 100% to the author.
     * @param array<string,mixed>  $extra         Extra per-connector data (tag override, etc.).
     */
    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly array $tags,
        public readonly array $images,
        public readonly string $author,
        public readonly string $canonicalUrl,
        public readonly int $wpPostId,
        public readonly array $beneficiaries = [],
        public readonly array $extra = [],
    ) {
    }
}
