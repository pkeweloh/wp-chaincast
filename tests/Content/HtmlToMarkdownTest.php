<?php
/**
 * HTML to Markdown conversion of the WordPress block markup.
 *
 * @package Chaincast\Tests\Content
 */

declare(strict_types=1);

namespace Chaincast\Tests\Content;

use PHPUnit\Framework\TestCase;
use Chaincast\Connector\Content\HtmlToMarkdown;
use Chaincast\Connector\Content\MediaLinkConverter;

final class HtmlToMarkdownTest extends TestCase {

    private HtmlToMarkdown $converter;

    protected function setUp(): void {
        $this->converter = new HtmlToMarkdown();
    }

    public function testLineBreakBecomesAHardBreak(): void {
        // Arrange
        $html = '<p>Description:<br><a href="https://example.com/report">https://example.com/report</a></p>';

        // Act
        $markdown = $this->converter->convert( $html );

        // Assert
        $this->assertSame(
            "Description:  \n[https://example.com/report](https://example.com/report)",
            $markdown
        );
    }

    public function testHardBreakKeepsBothLinesSeparateInsideAParagraph(): void {
        // Arrange
        $html = '<p>First line<br>Second line</p>';

        // Act
        $markdown = $this->converter->convert( $html );

        // Assert
        $this->assertStringContainsString( "First line  \nSecond line", $markdown );
        $this->assertStringNotContainsString( 'First lineSecond line', $markdown );
    }

    public function testFigcaptionBecomesAnItalicParagraphOfItsOwn(): void {
        // Arrange
        $html = '<figure class="wp-block-image size-large">'
            . '<img src="https://example.com/a.jpg" alt="A square"/>'
            . '<figcaption class="wp-element-caption">A square, a file.</figcaption>'
            . '</figure>';

        // Act
        $markdown = $this->converter->convert( $html );

        // Assert
        $this->assertSame(
            "![A square](https://example.com/a.jpg)\n\n*A square, a file.*",
            $markdown
        );
    }

    public function testFigureIsSeparatedFromTheSurroundingParagraphsByASingleBlankLine(): void {
        // Arrange
        $html = '<p>Before.</p>'
            . '<figure class="wp-block-image"><img src="https://example.com/b.jpg" alt="B"/></figure>'
            . '<p>After.</p>';

        // Act
        $markdown = $this->converter->convert( $html );

        // Assert
        $this->assertSame(
            "Before.\n\n![B](https://example.com/b.jpg)\n\nAfter.",
            $markdown
        );
    }

    public function testCaptionAfterABlockChildDoesNotStackBlankLines(): void {
        // Arrange
        $html = '<figure class="wp-block-pullquote">'
            . '<blockquote><p>A quote.</p></blockquote>'
            . '<figcaption>An author</figcaption>'
            . '</figure>';

        // Act
        $markdown = $this->converter->convert( $html );

        // Assert
        $this->assertSame( "> A quote.\n\n*An author*", $markdown );
    }

    public function testCaptionKeepsInlineMarkupAndStaysOnOneParagraph(): void {
        // Arrange
        $html = '<figure><img src="https://example.com/c.jpg" alt=""/>'
            . '<figcaption>Source: <a href="https://example.com">example.com</a></figcaption></figure>';

        // Act
        $markdown = $this->converter->convert( $html );

        // Assert
        $this->assertSame(
            "![](https://example.com/c.jpg)\n\n*Source: [example.com](https://example.com)*",
            $markdown
        );
    }

    public function testEmptyFigureProducesNothing(): void {
        // Arrange
        $html = '<figure></figure><p>Only this.</p>';

        // Act
        $markdown = $this->converter->convert( $html );

        // Assert
        $this->assertSame( 'Only this.', $markdown );
    }

    public function testTableKeepsItsCellsAsAMarkdownTable(): void {
        // Arrange
        $html = '<figure class="wp-block-table"><table>'
            . '<thead><tr><th>A</th><th>B</th></tr></thead>'
            . '<tbody><tr><td>1</td><td>2</td></tr></tbody>'
            . '</table><figcaption>Data</figcaption></figure>';

        // Act
        $markdown = $this->converter->convert( $html );

        // Assert
        $this->assertSame(
            "| A | B |\n|---|---|\n| 1 | 2 |\n\n*Data*",
            $markdown
        );
    }

    public function testNestedGalleryFiguresEachKeepTheirOwnCaption(): void {
        // Arrange
        $html = '<figure class="wp-block-gallery has-nested-images">'
            . '<figure class="wp-block-image"><img src="https://example.com/1.jpg" alt="One"/>'
            . '<figcaption>Caption one</figcaption></figure>'
            . '<figure class="wp-block-image"><img src="https://example.com/2.jpg" alt="Two"/></figure>'
            . '<figcaption class="blocks-gallery-caption">Gallery caption</figcaption></figure>';

        // Act
        $markdown = $this->converter->convert( $html );

        // Assert
        $this->assertSame(
            "![One](https://example.com/1.jpg)\n\n*Caption one*\n\n"
            . "![Two](https://example.com/2.jpg)\n\n*Gallery caption*",
            $markdown
        );
    }

    public function testVideoBecomesALinkWithItsCaptionBelow(): void {
        // Arrange
        $html = '<figure class="wp-block-video">'
            . '<video controls src="https://example.com/video-plan.mp4"></video>'
            . '<figcaption class="wp-element-caption">The plan explained</figcaption></figure>';

        // Act
        $markdown = $this->converter->convert( $html );

        // Assert
        $this->assertSame(
            "\u{1F4F9} [Watch video](https://example.com/video-plan.mp4)\n\n*The plan explained*",
            $markdown
        );
    }

    public function testVideoLabelIsTheInjectedOne(): void {
        // Arrange
        $converter = new HtmlToMarkdown( new MediaLinkConverter( 'Ver vídeo', 'Escuchar audio' ) );
        $html      = '<figure class="wp-block-video"><video controls src="https://example.com/v.mp4"></video></figure>';

        // Act
        $markdown = $converter->convert( $html );

        // Assert
        $this->assertSame( "\u{1F4F9} [Ver vídeo](https://example.com/v.mp4)", $markdown );
    }

    public function testAudioWithNestedSourceIsAlsoLinked(): void {
        // Arrange
        $html = '<figure class="wp-block-audio"><audio controls><source src="https://example.com/a.mp3" type="audio/mpeg"/></audio></figure>';

        // Act
        $markdown = $this->converter->convert( $html );

        // Assert
        $this->assertSame( "\u{1F50A} [Listen to audio](https://example.com/a.mp3)", $markdown );
    }

    public function testMediaWithoutSourceDisappearsInsteadOfLeavingAnEmptyLink(): void {
        // Arrange
        $html = '<p>Before.</p><figure class="wp-block-video"><video controls></video></figure><p>After.</p>';

        // Act
        $markdown = $this->converter->convert( $html );

        // Assert
        $this->assertSame( "Before.\n\nAfter.", $markdown );
    }

    public function testFileBlockKeepsTheDescriptiveLinkAndDropsTheDownloadButton(): void {
        // Arrange
        $html = '<div class="wp-block-file">'
            . '<a id="wp-block-file--media-x" href="https://example.com/list.xlsx">List of 559 dams (spreadsheet)</a>'
            . '<a href="https://example.com/list.xlsx" class="wp-block-file__button wp-element-button" download '
            . 'aria-describedby="wp-block-file--media-x">Download</a></div>';

        // Act
        $markdown = $this->converter->convert( $html );

        // Assert
        $this->assertSame(
            '[List of 559 dams (spreadsheet)](https://example.com/list.xlsx)',
            $markdown
        );
    }

    public function testPdfFileBlockDropsTheEmbedAndKeepsOneLink(): void {
        // Arrange
        $html = '<div class="wp-block-file">'
            . '<object class="wp-block-file__embed" data="https://example.com/doc.pdf" type="application/pdf" '
            . 'aria-label="Summary"></object>'
            . '<a id="wp-block-file--media-y" href="https://example.com/doc.pdf">Summary of the conversation</a>'
            . '<a href="https://example.com/doc.pdf" class="wp-block-file__button wp-element-button" download>Download</a></div>';

        // Act
        $markdown = $this->converter->convert( $html );

        // Assert
        $this->assertSame( '[Summary of the conversation](https://example.com/doc.pdf)', $markdown );
    }

    public function testOrdinaryLinksAreUntouched(): void {
        // Arrange
        $html = '<p>See <a href="https://example.com/report">the report</a>.</p>';

        // Act
        $markdown = $this->converter->convert( $html );

        // Assert
        $this->assertSame( 'See [the report](https://example.com/report).', $markdown );
    }

    public function testBareUrlKeepsItsFullTextByDefault(): void {
        // Arrange
        $html = '<p>Source:<br><a href="https://www.boe.es/buscar/doc.php?id=DOUE-L-2000-82524">'
            . 'https://www.boe.es/buscar/doc.php?id=DOUE-L-2000-82524</a></p>';

        // Act
        $markdown = $this->converter->convert( $html );

        // Assert
        $this->assertStringContainsString(
            '[https://www.boe.es/buscar/doc.php?id=DOUE-L-2000-82524](https://www.boe.es/buscar/doc.php?id=DOUE-L-2000-82524)',
            $markdown
        );
    }

    public function testBareUrlIsRelabelledWithItsDomainWhenEnabled(): void {
        // Arrange
        $converter = new HtmlToMarkdown();
        $converter->shortenBareUrls( true );
        $html = '<p>Source:<br><a href="https://www.boe.es/buscar/doc.php?id=DOUE-L-2000-82524">'
            . 'https://www.boe.es/buscar/doc.php?id=DOUE-L-2000-82524</a></p>';

        // Act
        $markdown = $converter->convert( $html );

        // Assert
        $this->assertSame(
            "Source:  \n[boe.es](https://www.boe.es/buscar/doc.php?id=DOUE-L-2000-82524)",
            $markdown
        );
    }

    public function testShorteningLeavesLinksThatHaveRealTextAlone(): void {
        // Arrange
        $converter = new HtmlToMarkdown();
        $converter->shortenBareUrls( true );
        $html = '<p>See <a href="https://example.com/report">the full report</a>.</p>';

        // Act
        $markdown = $converter->convert( $html );

        // Assert
        $this->assertSame( 'See [the full report](https://example.com/report).', $markdown );
    }

    public function testShorteningAlsoCoversATruncatedUrlAsLinkText(): void {
        // Arrange
        $converter = new HtmlToMarkdown();
        $converter->shortenBareUrls( true );
        $html = '<p><a href="https://sede.miteco.gob.es/portal/site/seMITECO/ficha?id=226&amp;by=theme">'
            . 'https://sede.miteco.gob.es/portal/site/seMITECO/ficha?id=226</a></p>';

        // Act
        $markdown = $converter->convert( $html );

        // Assert
        $this->assertSame(
            '[sede.miteco.gob.es](https://sede.miteco.gob.es/portal/site/seMITECO/ficha?id=226&by=theme)',
            $markdown
        );
    }

    public function testShorteningCanBeTurnedBackOffOnTheSameConverter(): void {
        // Arrange
        $html = '<p><a href="https://example.com/a/b">https://example.com/a/b</a></p>';
        $this->converter->shortenBareUrls( true );

        // Act
        $this->converter->shortenBareUrls( false );
        $markdown = $this->converter->convert( $html );

        // Assert
        $this->assertSame( '[https://example.com/a/b](https://example.com/a/b)', $markdown );
    }

    public function testCarriageReturnsDoNotIndentTheOutput(): void {
        // Arrange
        $html = "<!-- wp:paragraph -->\r\n<p>One.</p>\r\n<!-- /wp:paragraph -->\r\n\r\n"
            . "<!-- wp:paragraph -->\r\n<p>Two.</p>\r\n<!-- /wp:paragraph -->";

        // Act
        $markdown = $this->converter->convert( $html );

        // Assert
        $this->assertSame( "One.\n\nTwo.", $markdown );
    }

    public function testAppendFooterSeparatesItWithARule(): void {
        // Arrange
        $markdown = 'Body.';

        // Act
        $result = $this->converter->appendFooter( $markdown, '  Originally at example.com  ' );

        // Assert
        $this->assertSame( "Body.\n\n---\n\nOriginally at example.com", $result );
    }

    public function testAppendFooterWithAnEmptyLineLeavesTheBodyUnchanged(): void {
        // Arrange
        $markdown = 'Body.';

        // Act & Assert
        $this->assertSame( $markdown, $this->converter->appendFooter( $markdown, '   ' ) );
    }

    public function testProviderIframeBecomesTheNakedUrlOnItsOwnLine(): void {
        // Arrange
        $html = '<figure class="wp-block-embed"><div class="wp-block-embed__wrapper">'
            . '<iframe src="https://play.3speak.tv/embed?v=demo-author/abcdefg" allowfullscreen></iframe>'
            . '</div></figure>';

        // Act
        $markdown = $this->converter->convert( $html );

        // Assert
        $this->assertSame( 'https://play.3speak.tv/embed?v=demo-author/abcdefg', $markdown );
    }

    public function testIframeFromAnUnknownProviderIsDropped(): void {
        // Arrange
        $html = '<p>Before.</p><iframe src="https://example.com/widget"></iframe><p>After.</p>';

        // Act
        $markdown = $this->converter->convert( $html );

        // Assert
        $this->assertStringNotContainsString( 'example.com/widget', $markdown );
        $this->assertStringContainsString( 'Before.', $markdown );
        $this->assertStringContainsString( 'After.', $markdown );
    }

    public function testShorteningNeverTouchesAVideoLinkBecauseItWouldKillThePlayer(): void {
        // Arrange
        $converter = new HtmlToMarkdown();
        $converter->shortenBareUrls( true );
        $html = '<p><a href="https://www.youtube.com/watch?v=abcdefghijk">https://www.youtube.com/watch?v=abcdefghijk</a></p>';

        // Act
        $markdown = $converter->convert( $html );

        // Assert
        $this->assertSame( 'https://www.youtube.com/watch?v=abcdefghijk', $markdown );
    }

    public function testALabelledVideoLinkKeepsItsLabel(): void {
        // Arrange
        $html = '<p>See <a href="https://youtu.be/abcdefghijk">the recording</a>.</p>';

        // Act
        $markdown = $this->converter->convert( $html );

        // Assert
        $this->assertSame( 'See [the recording](https://youtu.be/abcdefghijk).', $markdown );
    }

    public function testInternalLinkIsPointedAtTheChainWhenTheResolverClaimsIt(): void {
        // Arrange
        $html = '<p>As told in <a href="https://example.com/who-controls-it/">the first part</a>.</p>';
        $this->converter->rewriteLinks(
            static fn( string $href ): string => 'https://example.com/who-controls-it/' === $href
                ? 'https://hive.blog/@demo-author/who-controls-it-10'
                : ''
        );

        // Act
        $markdown = $this->converter->convert( $html );

        // Assert
        $this->assertSame( 'As told in [the first part](https://hive.blog/@demo-author/who-controls-it-10).', $markdown );
    }

    public function testAnUnclaimedLinkIsLeftExactlyAsItWas(): void {
        // Arrange
        $html = '<p>As told in <a href="https://example.com/only-on-the-blog/">the first part</a>.</p>';
        $this->converter->rewriteLinks( static fn( string $href ): string => '' );

        // Act
        $markdown = $this->converter->convert( $html );

        // Assert
        $this->assertSame( 'As told in [the first part](https://example.com/only-on-the-blog/).', $markdown );
    }

    public function testARewrittenBareLinkAlsoShowsTheChainUrlAsItsText(): void {
        // Arrange
        $html = '<p><a href="https://example.com/who-controls-it/">https://example.com/who-controls-it/</a></p>';
        $this->converter->rewriteLinks(
            static fn( string $href ): string => 'https://hive.blog/@demo-author/who-controls-it-10'
        );

        // Act
        $markdown = $this->converter->convert( $html );

        // Assert
        $this->assertSame(
            '[https://hive.blog/@demo-author/who-controls-it-10](https://hive.blog/@demo-author/who-controls-it-10)',
            $markdown
        );
    }
}
