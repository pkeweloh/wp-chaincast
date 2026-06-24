<?php
/**
 * Generates valid Hive/Steem permlinks.
 *
 * Chain rules: lowercase, only [a-z0-9-], no double or leading/trailing hyphens,
 * max length 256. The permlink is generated only on the first publish; afterwards
 * it is persisted and reused, so an edit EDITS the post instead of creating a new
 * one (stability comes from persistence, not from the slug contents).
 *
 * @package Chaincast\Connector\Content
 */

declare(strict_types=1);

namespace Chaincast\Connector\Content;

final class PermlinkGenerator {

    private const MAX_LENGTH = 256;

    /**
     * Clean permlink from the title. The post ID is only used as a fallback when
     * the title yields no slug (e.g. empty title or no ASCII characters), to
     * guarantee a non-empty permlink.
     */
    public function generate( string $title, int $postId ): string {
        $slug = $this->slugify( $title );

        if ( '' === $slug ) {
            return 'post-' . $postId;
        }

        if ( strlen( $slug ) > self::MAX_LENGTH ) {
            $slug = rtrim( substr( $slug, 0, self::MAX_LENGTH ), '-' );
        }

        return $slug;
    }

    /**
     * Converts arbitrary text into a chain-compatible slug.
     */
    public function slugify( string $text ): string {
        $text = $this->transliterate( $text );
        $text = strtolower( $text );
        $text = preg_replace( '/[^a-z0-9]+/', '-', $text ) ?? '';
        return trim( $text, '-' );
    }

    /** Transliteration map of common Latin characters to ASCII. */
    private const TRANSLIT = [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ñ' => 'n', 'ç' => 'c', 'ß' => 'ss', 'ý' => 'y', 'ÿ' => 'y',
    ];

    /**
     * Transliterates Latin accents to ASCII deterministically (without relying on
     * iconv, whose //TRANSLIT varies across platforms). Anything unmappable is dropped.
     */
    private function transliterate( string $text ): string {
        $text = strtr( mb_strtolower( $text, 'UTF-8' ), self::TRANSLIT );
        return preg_replace( '/[^\x20-\x7E]/', '', $text ) ?? '';
    }
}
