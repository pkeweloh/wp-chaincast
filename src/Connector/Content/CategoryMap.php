<?php
/**
 * Maps WordPress categories/tags to the chain's tags or communities.
 *
 * In Settings (per chain) the user associates each WordPress category with a
 * target, e.g. "news: hive-167922", so posts in that category land in that
 * community. Applied when building the automatic tags (when the post has no tags
 * of its own). If a category is not mapped, its slug is used as-is (the default,
 * unconfigured behavior).
 *
 * Pure class (no WordPress dependencies) so it can be validated in tests.
 *
 * @package Chaincast\Connector\Content
 */

declare(strict_types=1);

namespace Chaincast\Connector\Content;

final class CategoryMap {

    /**
     * Translates a list of slugs through the map. Unmapped ones are kept.
     * Returns the list without empties or duplicates, preserving order.
     *
     * @param array<string,string> $map
     * @param string[]             $slugs
     *
     * @return string[]
     */
    public static function apply( array $map, array $slugs ): array {
        $out = [];
        foreach ( $slugs as $slug ) {
            $out[] = $map[ strtolower( $slug ) ] ?? $slug;
        }
        return array_values( array_unique( array_filter( $out ) ) );
    }
}
