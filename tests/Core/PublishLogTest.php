<?php
/**
 * PublishLog over an in-memory simulated post meta.
 *
 * @package Chaincast\Tests\Core
 */

declare(strict_types=1);

// Post meta stubs in the SUT's namespace: PHP resolves unqualified calls to this
// namespace before the globals. Backed by memory.
namespace Chaincast\Core\State {

    if ( ! function_exists( __NAMESPACE__ . '\\get_post_meta' ) ) {
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

namespace Chaincast\Tests\Core {

    use PHPUnit\Framework\TestCase;
    use Chaincast\Core\State\PublishLog;

    final class PublishLogTest extends TestCase {

        protected function setUp(): void {
            $GLOBALS['chaincast_test_meta'] = [];
        }

        public function testRecordAppendsInOrder(): void {
            // Arrange
            $log = new PublishLog();

            // Act
            $log->record( 5, 'hive', 'publish', true, 'https://hive.blog/@a/x', 'abc123' );
            $log->record( 5, 'steem', 'update', false, 'nodo caído' );

            // Assert
            $entries = $log->all( 5 );
            $this->assertCount( 2, $entries );

            $this->assertSame( 'hive', $entries[0]['connector'] );
            $this->assertTrue( $entries[0]['success'] );
            $this->assertSame( 'abc123', $entries[0]['tx_id'] );

            $this->assertSame( 'steem', $entries[1]['connector'] );
            $this->assertFalse( $entries[1]['success'] );
            $this->assertSame( 'nodo caído', $entries[1]['detail'] );
            $this->assertGreaterThan( 0, $entries[1]['time'] );
        }

        public function testEmptyWhenNoLog(): void {
            $this->assertSame( [], ( new PublishLog() )->all( 99 ) );
        }

        public function testCapsAtThirtyEntries(): void {
            // Arrange
            $log = new PublishLog();

            // Act
            for ( $i = 0; $i < 35; $i++ ) {
                $log->record( 7, 'hive', 'publish', true, "intento $i" );
            }

            // Assert
            $entries = $log->all( 7 );
            $this->assertCount( 30, $entries );
            // Must keep the most recent ones: the first stored is "intento 5".
            $this->assertSame( 'intento 5', $entries[0]['detail'] );
            $this->assertSame( 'intento 34', $entries[29]['detail'] );
        }

        public function testClearRemovesLog(): void {
            $log = new PublishLog();
            $log->record( 3, 'hive', 'publish', true );
            $this->assertCount( 1, $log->all( 3 ) );

            $log->clear( 3 );
            $this->assertSame( [], $log->all( 3 ) );
        }

        public function testLogsAreIsolatedPerPost(): void {
            $log = new PublishLog();
            $log->record( 1, 'hive', 'publish', true );
            $log->record( 2, 'steem', 'update', false );

            $this->assertCount( 1, $log->all( 1 ) );
            $this->assertCount( 1, $log->all( 2 ) );
            $this->assertSame( 'hive', $log->all( 1 )[0]['connector'] );
        }
    }
}
