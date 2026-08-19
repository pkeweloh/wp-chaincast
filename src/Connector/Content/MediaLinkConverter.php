<?php
/**
 * Converts <video> and <audio> to a plain link.
 *
 * Hive and Steem sanitize the HTML and drop both tags, so without this the media
 * disappears entirely and only its caption survives. They only auto-embed players
 * for a few recognized providers, never a file served from the site itself.
 *
 * @package Chaincast\Connector\Content
 */

declare(strict_types=1);

namespace Chaincast\Connector\Content;

use League\HTMLToMarkdown\Converter\ConverterInterface;
use League\HTMLToMarkdown\ElementInterface;
use League\HTMLToMarkdown\PreConverterInterface;

final class MediaLinkConverter implements ConverterInterface, PreConverterInterface {

    private string $sourceSrc = '';

    public function __construct(
        private string $videoLabel = 'Watch video',
        private string $audioLabel = 'Listen to audio',
    ) {
    }

    /**
     * The <source> children are replaced by their Markdown before convert() runs,
     * so their src has to be read here.
     */
    public function preConvert( ElementInterface $element ): void {
        $this->sourceSrc = '';
        foreach ( $element->getChildren() as $child ) {
            if ( 'source' === $child->getTagName() && '' !== $child->getAttribute( 'src' ) ) {
                $this->sourceSrc = $child->getAttribute( 'src' );
                return;
            }
        }
    }

    public function convert( ElementInterface $element ): string {
        $isVideo = 'video' === $element->getTagName();
        $src     = $element->getAttribute( 'src' );
        if ( '' === $src ) {
            $src = $this->sourceSrc;
        }
        if ( '' === $src ) {
            return '';
        }

        $icon  = $isVideo ? '📹' : '🔊';
        $label = $isVideo ? $this->videoLabel : $this->audioLabel;

        return $icon . ' [' . $label . '](' . $src . ')';
    }

    /**
     * @return string[]
     */
    public function getSupportedTags(): array {
        return [ 'video', 'audio' ];
    }
}
