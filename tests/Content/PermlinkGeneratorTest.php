<?php
/**
 * @package Chaincast\Tests\Content
 */

declare(strict_types=1);

namespace Chaincast\Tests\Content;

use PHPUnit\Framework\TestCase;
use Chaincast\Connector\Content\PermlinkGenerator;

final class PermlinkGeneratorTest extends TestCase {

    private PermlinkGenerator $gen;

    protected function setUp(): void {
        $this->gen = new PermlinkGenerator();
    }

    public function testBasicSlugWithIdSuffix(): void {
        $this->assertSame( 'hola-mundo-42', $this->gen->generate( 'Hola Mundo', 42 ) );
    }

    public function testTransliteratesAccentsAndStripsSymbols(): void {
        $this->assertSame( 'cafe-nandu-y-emojis-7', $this->gen->generate( 'Café, ñandú y emojis 🚀', 7 ) );
    }

    public function testOnlyAllowedCharacters(): void {
        $permlink = $this->gen->generate( '¿Qué? ¡Sí! 100% «genial»', 5 );
        $this->assertMatchesRegularExpression( '/^[a-z0-9-]+$/', $permlink );
        $this->assertStringEndsWith( '-5', $permlink );
    }

    public function testEmptyTitleFallsBackToPostId(): void {
        $this->assertSame( 'post-99', $this->gen->generate( '🚀🚀🚀', 99 ) );
        $this->assertSame( 'post-99', $this->gen->generate( '', 99 ) );
    }

    public function testRespectsMaxLength(): void {
        $permlink = $this->gen->generate( str_repeat( 'palabra ', 100 ), 12345 );
        $this->assertLessThanOrEqual( 256, strlen( $permlink ) );
        $this->assertStringEndsWith( '-12345', $permlink );
    }

    public function testDeterministicForIdempotentEdits(): void {
        $this->assertSame(
            $this->gen->generate( 'Mi Post', 10 ),
            $this->gen->generate( 'Mi Post', 10 )
        );
    }
}
