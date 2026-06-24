<?php
/**
 * Builds the comment operation's `json_metadata`.
 *
 * Includes tags (normalized, max 5), the app signature, the format, the images
 * and the canonical link back to the site (attribution/SEO).
 *
 * @package Chaincast\Connector\Content
 */

declare(strict_types=1);

namespace Chaincast\Connector\Content;

use Chaincast\Connector\PostPayload;

final class JsonMetadata {

    private const MAX_TAGS = 5;

    public function __construct(
        private string $appVersion = 'chaincast/0.1.0',
    ) {
    }

    public function build( PostPayload $payload ): string {
        $meta = [
            'tags'   => $this->normalizeTags( $payload->tags ),
            'app'    => $this->appVersion,
            'format' => 'markdown',
        ];

        if ( ! empty( $payload->images ) ) {
            $meta['image'] = array_values( array_unique( $payload->images ) );
        }

        if ( '' !== $payload->canonicalUrl ) {
            $meta['canonical_url'] = $payload->canonicalUrl;
        }

        return (string) json_encode( $meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
    }

    /**
     * @param string[] $tags
     * @return string[]
     */
    private function normalizeTags( array $tags ): array {
        $normalized = [];
        foreach ( $tags as $tag ) {
            $clean = strtolower( trim( (string) $tag ) );
            // Hive tags only allow [a-z0-9-] (and must start with a letter for the main one).
            $clean = preg_replace( '/[^a-z0-9-]+/', '-', $clean ) ?? '';
            $clean = trim( $clean, '-' );
            if ( '' !== $clean && ! in_array( $clean, $normalized, true ) ) {
                $normalized[] = $clean;
            }
            if ( count( $normalized ) >= self::MAX_TAGS ) {
                break;
            }
        }
        return $normalized;
    }
}
