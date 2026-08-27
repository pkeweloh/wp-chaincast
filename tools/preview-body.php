<?php
/**
 * Prints the exact Markdown body a post would send to a chain, WITHOUT touching
 * the network. What the unit tests cannot cover lives here: the HTML that
 * `the_content` really produces, `url_to_postid()` resolving real permalinks and
 * the per-chain state that decides each internal link.
 *
 * Usage (from docker/):
 *   docker compose run --rm wpcli wp eval-file \
 *     wp-content/plugins/chaincast/tools/preview-body.php <post_id> <connector_id>
 *
 * No `declare(strict_types=1)` here on purpose: `wp eval-file` evals the file
 * instead of including it, and a declare is not allowed inside an eval.
 *
 * @package Chaincast\Tools
 */

use Chaincast\Connector\Content\HtmlToMarkdown;
use Chaincast\Connector\Content\InternalLinkResolver;
use Chaincast\Connector\Content\MediaLinkConverter;
use Chaincast\Connector\PayloadFactory;
use Chaincast\Core\Settings;
use Chaincast\Core\State\PostState;

$postId      = (int) ( $args[0] ?? 0 );
$connectorId = (string) ( $args[1] ?? 'hive' );

$post = get_post( $postId );
if ( null === $post ) {
    WP_CLI::error( 'No such post: ' . $postId );
}

$settings = new Settings();
$state    = new PostState();

$shorten = $state->shortenBareUrls( $postId ) ?? $settings->shortenBareUrls();
$rewrite = $state->rewriteInternalLinks( $postId ) ?? $settings->rewriteInternalLinks();

$payload = ( new PayloadFactory( new HtmlToMarkdown( new MediaLinkConverter() ) ) )->fromPost(
    $post,
    (string) get_bloginfo( 'name' ),
    '',
    $settings->footerEnabled() ? $settings->footerText() : '',
    '',
    $settings->categoryMapFor( $connectorId ),
    $shorten,
    $rewrite ? new InternalLinkResolver( $connectorId, $state ) : null
);

WP_CLI::line( '--- ' . $connectorId . ' | shorten: ' . ( $shorten ? 'on' : 'off' ) . ' | internal links: ' . ( $rewrite ? 'on' : 'off' ) . ' ---' );
WP_CLI::line( 'Title: ' . $payload->title );
WP_CLI::line( 'Tags: ' . implode( ', ', $payload->tags ) );
WP_CLI::line( 'Body bytes: ' . strlen( $payload->body ) );
WP_CLI::line( '' );
WP_CLI::line( $payload->body );
