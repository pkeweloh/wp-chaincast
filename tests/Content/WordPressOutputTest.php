<?php
/**
 * Conversion of the HTML WordPress really produces.
 *
 * The rest of the converter tests feed hand-written markup, which is only ever
 * an assumption about what `the_content` emits. This one works from a frozen
 * capture of a real WordPress 6.6 install (`docker/`) with every block the
 * converter handles, so it also carries what nobody writes by hand: the
 * `loading="lazy"` WordPress injects into an iframe, the layout classes it adds
 * to a blockquote, the blank lines it leaves between blocks.
 *
 * To refresh it after changing the fixture's post, from `docker/`:
 *   docker compose exec -T wpcli wp eval-file \
 *     wp-content/plugins/chaincast/tools/dump-fixture.php <post_id>
 *
 * @package Chaincast\Tests\Content
 */

declare(strict_types=1);

namespace Chaincast\Tests\Content;

use PHPUnit\Framework\TestCase;
use Chaincast\Connector\Content\HtmlToMarkdown;

final class WordPressOutputTest extends TestCase {

    /** The one post of the fixture's site that is published on the chain. */
    private const ON_CHAIN = [
        'https://example.com/quien-controla-el-clima/' => 'https://hive.blog/@demo-author/quien-controla-el-clima-10',
    ];

    private HtmlToMarkdown $converter;

    protected function setUp(): void {
        $this->converter = new HtmlToMarkdown();
        $this->converter->shortenBareUrls( true );
        $this->converter->rewriteLinks(
            static fn( string $href ): string => self::ON_CHAIN[ $href ] ?? ''
        );
    }

    public function testTheWholeArticleConvertsToTheGoldenMarkdown(): void {
        // Arrange
        $expected = $this->fixture( 'wordpress-content.md' );

        // Act
        $markdown = $this->converter->convert( $this->fixture( 'wordpress-content.html' ) );

        // Assert
        $this->assertSame( $expected, $markdown );
    }

    /**
     * The permlink carries a suffix the slug does not predict, so only the one
     * recorded when publishing can produce this link.
     */
    public function testALinkToAPostPublishedOnTheChainGoesToTheChain(): void {
        // Act
        $markdown = $this->converter->convert( $this->fixture( 'wordpress-content.html' ) );

        // Assert
        $this->assertStringContainsString(
            '[la primera entrega](https://hive.blog/@demo-author/quien-controla-el-clima-10)',
            $markdown
        );
    }

    public function testALinkToAPostNotOnTheChainStillGoesToTheBlog(): void {
        // Act
        $markdown = $this->converter->convert( $this->fixture( 'wordpress-content.html' ) );

        // Assert
        $this->assertStringContainsString(
            '[la segunda](https://example.com/la-sequia-es-una-decision/)',
            $markdown
        );
    }

    /**
     * Uploads live on the site's own host too: rewriting one would break every
     * image and every attachment in the body.
     */
    public function testNothingUnderWpContentIsRewritten(): void {
        // Act
        $markdown = $this->converter->convert( $this->fixture( 'wordpress-content.html' ) );

        // Assert
        $this->assertStringContainsString( '![Una foto](https://example.com/wp-content/uploads/2026/08/foto.jpg)', $markdown );
        $this->assertStringContainsString( '[informe.pdf](https://example.com/wp-content/uploads/2026/08/informe.pdf)', $markdown );
    }

    /**
     * The one form Hive and Steem turn into a player, straight out of the iframe
     * WordPress rendered.
     */
    public function testTheProviderIframeLeavesAsANakedUrlDespiteTheShortener(): void {
        // Act
        $markdown = $this->converter->convert( $this->fixture( 'wordpress-content.html' ) );

        // Assert
        $this->assertStringContainsString( "\n\nhttps://play.3speak.tv/embed?v=demo-author/abcdefg\n\n", $markdown );
    }

    private function fixture( string $name ): string {
        return (string) file_get_contents( __DIR__ . '/../fixtures/' . $name );
    }
}
