<?php
/**
 * Construye el `json_metadata` de la operación comment.
 *
 * Incluye tags (normalizadas, máx. 5), la firma de la app, el formato, las
 * imágenes y el enlace canónico de vuelta a la web (atribución/SEO).
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
            // Las tags de Hive solo admiten [a-z0-9-] (y empezar por letra para la principal).
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
