<?php
/**
 * Parsing, formatting and applying the category map.
 *
 * @package Chaincast\Tests\Content
 */

declare(strict_types=1);

namespace Chaincast\Tests\Content;

use PHPUnit\Framework\TestCase;
use Chaincast\Connector\Content\CategoryMap;

final class CategoryMapTest extends TestCase {

    public function testApplyTranslatesMappedKeepsOthers(): void {
        $map   = [ 'noticias' => 'hive-167922' ];
        $slugs = [ 'noticias', 'wordpress', 'blog' ];
        $this->assertSame(
            [ 'hive-167922', 'wordpress', 'blog' ],
            CategoryMap::apply( $map, $slugs )
        );
    }

    public function testApplyDedupesAfterMappingPreservingOrder(): void {
        // Two different categories mapping to the same target: just one.
        $map   = [ 'noticias' => 'hive-167922', 'actualidad' => 'hive-167922' ];
        $slugs = [ 'noticias', 'actualidad', 'blog' ];
        $this->assertSame(
            [ 'hive-167922', 'blog' ],
            CategoryMap::apply( $map, $slugs )
        );
    }

    public function testApplyWithEmptyMapIsIdentity(): void {
        $this->assertSame(
            [ 'blog', 'noticias' ],
            CategoryMap::apply( [], [ 'blog', 'noticias' ] )
        );
    }

    public function testApplyIsCaseInsensitiveOnKey(): void {
        $map = [ 'noticias' => 'hive-167922' ];
        $this->assertSame( [ 'hive-167922' ], CategoryMap::apply( $map, [ 'Noticias' ] ) );
    }
}
