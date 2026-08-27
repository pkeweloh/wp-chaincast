<?php
/**
 * Freezes the real `the_content` output of a post as tests/fixtures/wordpress-content.html,
 * the oracle behind WordPressOutputTest. Same idea as generate-vectors.php, with
 * WordPress itself as the oracle instead of a reference library.
 *
 * Usage (from docker/), after tools/seed-fixture-post.php:
 *   docker compose exec -T wpcli wp eval-file  *     wp-content/plugins/chaincast/tools/dump-fixture.php <post_id>
 *
 * @package Chaincast\Tools
 */

$postId = (int) ( $args[0] ?? 0 );
$post   = get_post( $postId );
if ( null === $post ) {
    WP_CLI::error( 'No such post: ' . $postId );
}

$html = (string) apply_filters( 'the_content', $post->post_content );

// The fixture travels with the repo, so it uses the placeholder host the test
// conventions ask for instead of this machine's dev URL.
$html = str_replace( 'http://localhost:8089', 'https://example.com', $html );

$path = ABSPATH . 'wp-content/plugins/chaincast/tests/fixtures/wordpress-content.html';
file_put_contents( $path, $html );

WP_CLI::line( 'Written ' . strlen( $html ) . ' bytes to ' . $path );
