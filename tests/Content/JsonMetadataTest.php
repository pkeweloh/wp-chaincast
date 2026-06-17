<?php
/**
 * @package Chaincast\Tests\Content
 */

declare(strict_types=1);

namespace Chaincast\Tests\Content;

use PHPUnit\Framework\TestCase;
use Chaincast\Connector\Content\JsonMetadata;
use Chaincast\Connector\PostPayload;

final class JsonMetadataTest extends TestCase {

    private function payload( array $tags, array $images = [], string $canonical = '' ): PostPayload {
        return new PostPayload( 'T', 'B', $tags, $images, 'skunk1', $canonical, 1 );
    }

    public function testIncludesAppAndFormat(): void {
        $meta = json_decode( ( new JsonMetadata( 'chaincast/9.9' ) )->build( $this->payload( [ 'blog' ] ) ), true );
        $this->assertSame( 'chaincast/9.9', $meta['app'] );
        $this->assertSame( 'markdown', $meta['format'] );
    }

    public function testTagsNormalizedAndCappedAtFive(): void {
        $meta = json_decode( ( new JsonMetadata() )->build( $this->payload( [ 'Hive', 'Hive', 'Web 3', 'a', 'b', 'c', 'd' ] ) ), true );
        // 'Hive' duplicado colapsa, 'Web 3' -> 'web-3', máximo 5.
        $this->assertSame( [ 'hive', 'web-3', 'a', 'b', 'c' ], $meta['tags'] );
    }

    public function testImagesIncludedWhenPresentAndDeduplicated(): void {
        $meta = json_decode( ( new JsonMetadata() )->build( $this->payload( [ 'blog' ], [ 'https://x/1.png', 'https://x/1.png', 'https://x/2.png' ] ) ), true );
        $this->assertSame( [ 'https://x/1.png', 'https://x/2.png' ], $meta['image'] );
    }

    public function testImageKeyOmittedWhenNoImages(): void {
        $meta = json_decode( ( new JsonMetadata() )->build( $this->payload( [ 'blog' ] ) ), true );
        $this->assertArrayNotHasKey( 'image', $meta );
    }

    public function testCanonicalUrlIncludedWhenSet(): void {
        $meta = json_decode( ( new JsonMetadata() )->build( $this->payload( [ 'blog' ], [], 'https://skunk1.blog/x' ) ), true );
        $this->assertSame( 'https://skunk1.blog/x', $meta['canonical_url'] );
    }
}
