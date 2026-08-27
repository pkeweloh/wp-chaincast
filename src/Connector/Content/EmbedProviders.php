<?php
/**
 * Video providers Hive and Steem recognize.
 *
 * Both chains only build a player out of a NAKED URL sitting on its own line,
 * and only for a handful of known hosts. A labelled link, `[text](url)`, never
 * becomes a player. So these hosts get special treatment while converting: the
 * URL has to reach the chain untouched.
 *
 * @package Chaincast\Connector\Content
 */

declare(strict_types=1);

namespace Chaincast\Connector\Content;

final class EmbedProviders {

    /** Registrable domains; any subdomain of them counts (play.3speak.tv, www.youtube.com). */
    private const HOSTS = [
        '3speak.tv',
        'youtube.com',
        'youtu.be',
        'vimeo.com',
        'rumble.com',
    ];

    public static function isEmbeddable( string $url ): bool {
        $host = parse_url( self::withScheme( $url ), PHP_URL_HOST );
        if ( ! is_string( $host ) || '' === $host ) {
            return false;
        }

        $host = strtolower( $host );
        foreach ( self::HOSTS as $known ) {
            if ( $host === $known || str_ends_with( $host, '.' . $known ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * A protocol-relative src is valid in the browser but not on the chain, where
     * the URL travels alone with no page around it.
     */
    public static function withScheme( string $url ): string {
        return str_starts_with( $url, '//' ) ? 'https:' . $url : $url;
    }
}
