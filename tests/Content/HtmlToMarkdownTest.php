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
}
