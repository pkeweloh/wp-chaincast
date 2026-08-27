<?php
/**
 * Internal link resolution against a simulated site and post meta.
 *
 * @package Chaincast\Tests\Content
 */

declare(strict_types=1);

// WordPress stubs in the namespaces of the classes under test: PHP resolves
// unqualified calls to their own namespace before the globals.
namespace Chaincast\Connector\Content {

    if ( ! function_exists( __NAMESPACE__ . '\home_url' ) ) {
        function home_url(): string {
            return 'https://example.com';
        }
        function url_to_postid( string $url ): int {
            return $GLOBALS['chaincast_test_posts'][ $url ] ?? 0;
        }
    }
}

namespace Chaincast\Core\State {

    if ( ! function_exists( __NAMESPACE__ . '\get_post_meta' ) ) {
        function get_post_meta( int $postId, string $key, bool $single ) {
            return $GLOBALS['chaincast_test_meta'][ $postId ][ $key ] ?? '';
        }
        function update_post_meta( int $postId, string $key, $value ): bool {
            $GLOBALS['chaincast_test_meta'][ $postId ][ $key ] = $value;
            return true;
        }
        function delete_post_meta( int $postId, string $key ): bool {
            unset( $GLOBALS['chaincast_test_meta'][ $postId ][ $key ] );
            return true;
        }
    }
}

namespace Chaincast\Tests\Content {

    use PHPUnit\Framework\TestCase;
    use Chaincast\Connector\Content\InternalLinkResolver;
    use Chaincast\Core\State\PostState;

    final class InternalLinkResolverTest extends TestCase {

        private const POST_URL = 'https://example.com/who-controls-it/';

        protected function setUp(): void {
            $GLOBALS['chaincast_test_meta']  = [];
            $GLOBALS['chaincast_test_posts'] = [ self::POST_URL => 7 ];
        }

        public function testPublishedPostResolvesToItsChainUrl(): void {
            // Arrange
            $GLOBALS['chaincast_test_meta'][7]['_chaincast_state_hive'] = [
                'status' => PostState::STATUS_PUBLISHED,
                'ref'    => 'who-controls-it-10',
                'url'    => 'https://hive.blog/@demo-author/who-controls-it-10',
            ];
            $resolve = new InternalLinkResolver( 'hive' );

            // Act
            $target = $resolve( self::POST_URL );

            // Assert
            $this->assertSame( 'https://hive.blog/@demo-author/who-controls-it-10', $target );
        }

        /**
         * The permlink is not the slug: this post went out before the permlink
         * cleanup and carries a suffix nothing else knows about.
         */
        public function testTheStoredPermlinkWinsOverTheSlug(): void {
            // Arrange
            $GLOBALS['chaincast_test_meta'][7]['_chaincast_state_hive'] = [
                'status' => PostState::STATUS_PUBLISHED,
                'url'    => 'https://hive.blog/@demo-author/who-controls-it-10',
            ];
            $resolve = new InternalLinkResolver( 'hive' );

            // Act
            $target = $resolve( self::POST_URL );

            // Assert
            $this->assertStringEndsWith( '-10', $target );
        }

        public function testAPostNotPublishedOnThatChainKeepsItsBlogLink(): void {
            // Arrange
            $GLOBALS['chaincast_test_meta'][7]['_chaincast_state_hive'] = [
                'status' => PostState::STATUS_PUBLISHED,
                'url'    => 'https://hive.blog/@demo-author/who-controls-it-10',
            ];
            $resolve = new InternalLinkResolver( 'steem' );

            // Act
            $target = $resolve( self::POST_URL );

            // Assert
            $this->assertSame( '', $target );
        }

        public function testAQueuedPostIsNotRewrittenEither(): void {
            // Arrange
            $GLOBALS['chaincast_test_meta'][7]['_chaincast_state_hive'] = [
                'status' => PostState::STATUS_QUEUED,
            ];
            $resolve = new InternalLinkResolver( 'hive' );

            // Act
            $target = $resolve( self::POST_URL );

            // Assert
            $this->assertSame( '', $target );
        }

        public function testAnUploadIsNeverTouchedEvenOnTheSiteHost(): void {
            // Arrange
            $url                                     = 'https://example.com/wp-content/uploads/2026/08/photo.jpg';
            $GLOBALS['chaincast_test_posts'][ $url ] = 7;
            $GLOBALS['chaincast_test_meta'][7]['_chaincast_state_hive'] = [
                'status' => PostState::STATUS_PUBLISHED,
                'url'    => 'https://hive.blog/@demo-author/who-controls-it-10',
            ];
            $resolve = new InternalLinkResolver( 'hive' );

            // Act
            $target = $resolve( $url );

            // Assert
            $this->assertSame( '', $target );
        }

        public function testAnExternalLinkIsNotEvenLookedUp(): void {
            // Arrange
            $GLOBALS['chaincast_test_posts']['https://elsewhere.test/who-controls-it/'] = 7;
            $resolve = new InternalLinkResolver( 'hive' );

            // Act
            $target = $resolve( 'https://elsewhere.test/who-controls-it/' );

            // Assert
            $this->assertSame( '', $target );
        }

        public function testTheWwwPrefixDoesNotMakeTheSiteExternal(): void {
            // Arrange
            $url                                     = 'https://www.example.com/who-controls-it/';
            $GLOBALS['chaincast_test_posts'][ $url ] = 7;
            $GLOBALS['chaincast_test_meta'][7]['_chaincast_state_hive'] = [
                'status' => PostState::STATUS_PUBLISHED,
                'url'    => 'https://hive.blog/@demo-author/who-controls-it-10',
            ];
            $resolve = new InternalLinkResolver( 'hive' );

            // Act
            $target = $resolve( $url );

            // Assert
            $this->assertSame( 'https://hive.blog/@demo-author/who-controls-it-10', $target );
        }

        public function testAnUrlThatIsNoPostResolvesToNothing(): void {
            // Arrange
            $resolve = new InternalLinkResolver( 'hive' );

            // Act
            $target = $resolve( 'https://example.com/about/' );

            // Assert
            $this->assertSame( '', $target );
        }
    }
}
