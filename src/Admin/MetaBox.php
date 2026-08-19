<?php
/**
 * Per-post metabox in the editor.
 *
 * Shows, for each connector, the publishing state (with a link if published) and
 * a button to **publish/update now** on that chain. This lets the user decide
 * when to publish, independently of clicking "Publish" in WP.
 *
 * @package Chaincast\Admin
 */

declare(strict_types=1);

namespace Chaincast\Admin;

use Chaincast\Connector\ConnectorInterface;
use Chaincast\Core\ConnectorRegistry;
use Chaincast\Core\PublishService;
use Chaincast\Core\State\PostState;
use Chaincast\Core\State\PublishLog;
use WP_Post;

final class MetaBox {

    private const ID               = 'chaincast-box';
    private const ACTION           = 'chaincast_publish_now';
    private const ACTION_CLEAR_LOG = 'chaincast_clear_log';
    private const OPTIONS_NONCE    = 'chaincast_post_options_nonce';
    private const OPTIONS_FIELD    = 'chaincast_shorten_urls';

    private PostState $state;
    private PublishLog $log;

    public function __construct(
        private ConnectorRegistry $connectors,
        private PublishService $publisher,
    ) {
        $this->state = new PostState();
        $this->log   = new PublishLog();
    }

    public function register(): void {
        add_action( 'add_meta_boxes', [ $this, 'add' ] );
        add_action( 'admin_post_' . self::ACTION, [ $this, 'handlePublishNow' ] );
        add_action( 'admin_post_' . self::ACTION_CLEAR_LOG, [ $this, 'handleClearLog' ] );
        add_action( 'save_post', [ $this, 'saveOptions' ], 10, 2 );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
    }

    public function enqueue( string $hook ): void {
        if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
            return;
        }
        Assets::enqueueStyle();
    }

    public function add(): void {
        add_meta_box(
            self::ID,
            __( 'Chaincast', 'chaincast' ),
            [ $this, 'render' ],
            'post',
            'side',
            'default'
        );
    }

    public function render( WP_Post $post ): void {
        $this->renderNotice( (int) $post->ID );

        $all = $this->connectors->all();
        if ( empty( $all ) ) {
            printf( '<p><em>%s</em></p>', esc_html__( 'No active connectors. Configure them in Settings → Chaincast.', 'chaincast' ) );
            return;
        }

        $this->renderSizeWarning( $post, $all );
        $this->renderLinkOption( $post );

        echo '<hr>';
        foreach ( $all as $connector ) {
            $this->renderConnectorRow( $post, $connector );
        }

        $this->renderLog( $post );
    }

    /**
     * Size of the post against the chain limit. Silent while the post is nowhere
     * near it, since measuring means rendering the whole post.
     *
     * @param ConnectorInterface[] $connectors
     */
    private function renderSizeWarning( WP_Post $post, array $connectors ): void {
        $limit = 0;
        foreach ( $connectors as $connector ) {
            $max = $connector->maxPayloadBytes();
            if ( $max > 0 && ( 0 === $limit || $max < $limit ) ) {
                $limit = $max;
            }
        }

        // The Markdown is always smaller than the block markup it comes from, so
        // a short post is not worth rendering just to measure it.
        if ( 0 === $limit || strlen( $post->post_content ) <= intdiv( $limit, 3 ) ) {
            return;
        }

        $bytes = $this->publisher->payloadBytes( (int) $post->ID );
        if ( $bytes <= 0 ) {
            return;
        }

        $percent = (int) round( $bytes / $limit * 100 );
        $level   = $bytes > $limit ? ' over' : ( $percent >= 75 ? ' near' : '' );

        echo '<div class="cc-size">';
        printf(
            '<div class="cc-size-head"><span class="cc-size-label">%s</span><span class="cc-size-pct%s">%s</span></div>',
            esc_html__( 'Size on the chain', 'chaincast' ),
            esc_attr( $level ),
            esc_html( sprintf( '%d%%', $percent ) )
        );
        printf(
            '<div class="cc-size-track"><div class="cc-size-fill%s" style="width:%d%%"></div></div>',
            esc_attr( $level ),
            min( 100, max( 2, $percent ) )
        );
        printf(
            '<p class="cc-size-note%s">%s</p>',
            esc_attr( $level ),
            esc_html(
                ' over' === $level
                    ? __( 'It does not fit. Shorten the text: images and video barely count, only their URL travels.', 'chaincast' )
                    : sprintf(
                        /* translators: 1: post size, 2: chain limit, both already formatted (e.g. "36 KB"). */
                        __( '%1$s of %2$s', 'chaincast' ),
                        size_format( $bytes, 1 ),
                        size_format( $limit )
                    )
            )
        );
        echo '</div>';
    }

    /**
     * Per-post choice on shortening bare links: it depends on how tight this
     * article is against the size limit, so it does not belong in the settings.
     * Starts off from the site setting and, once the post is saved, stays where
     * the author left it.
     */
    private function renderLinkOption( WP_Post $post ): void {
        wp_nonce_field( self::OPTIONS_NONCE, self::OPTIONS_NONCE );

        echo '<p style="margin:0 0 10px">';
        printf(
            '<label><input type="checkbox" name="%s" value="1"%s /> %s</label> ',
            esc_attr( self::OPTIONS_FIELD ),
            checked( $this->publisher->shortenBareUrls( (int) $post->ID ), true, false ),
            esc_html__( 'Shorten bare links', 'chaincast' )
        );
        Assets::renderHelp(
            esc_attr__( 'Only affects links whose visible text is the URL itself: they are relabelled with their domain and still point to the full URL.', 'chaincast' ),
            true
        );
        echo '</p>';
    }

    /**
     * Saves the per-post options with the post itself.
     */
    public function saveOptions( int $postId, WP_Post $post ): void {
        if ( 'post' !== $post->post_type || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
            return;
        }

        $nonce = isset( $_POST[ self::OPTIONS_NONCE ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::OPTIONS_NONCE ] ) ) : '';
        if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::OPTIONS_NONCE ) || ! current_user_can( 'edit_post', $postId ) ) {
            return;
        }

        $this->state->setShortenBareUrls( $postId, ! empty( $_POST[ self::OPTIONS_FIELD ] ) );
    }

    /**
     * The post's publish-attempt history (all chains/paths).
     */
    private function renderLog( WP_Post $post ): void {
        $entries = $this->log->all( (int) $post->ID );
        if ( empty( $entries ) ) {
            return;
        }

        echo '<details style="margin-top:4px">';
        printf(
            '<summary style="cursor:pointer"><strong>%s</strong> (%d)</summary>',
            esc_html__( 'History', 'chaincast' ),
            count( $entries )
        );
        echo '<ul style="margin:8px 0 0;max-height:220px;overflow:auto;font-size:12px">';

        // Most recent first.
        foreach ( array_reverse( $entries ) as $entry ) {
            $ok    = ! empty( $entry['success'] );
            $icon  = $ok ? '✓' : '✗';
            $color = $ok ? '#008a20' : '#b32d2e';
            $when  = $this->formatTime( (int) ( $entry['time'] ?? 0 ) );
            $label = $this->actionLabel( (string) ( $entry['action'] ?? '' ), (string) ( $entry['connector'] ?? '' ) );

            printf(
                '<li style="margin:0 0 6px;padding-bottom:6px;border-bottom:1px solid #f0f0f0"><span style="color:%s">%s</span> <strong>%s</strong> <span style="color:#888">%s</span>',
                esc_attr( $color ),
                esc_html( $icon ),
                esc_html( $label ),
                esc_html( $when )
            );

            $detail = (string) ( $entry['detail'] ?? '' );
            if ( '' !== $detail ) {
                printf( '<br><span style="color:%s;word-break:break-all">%s</span>', esc_attr( $ok ? '#555' : '#b32d2e' ), esc_html( $detail ) );
            }
            $txId = (string) ( $entry['tx_id'] ?? '' );
            if ( '' !== $txId ) {
                printf( '<br><code style="font-size:11px;word-break:break-all">%s</code>', esc_html( $txId ) );
            }
            echo '</li>';
        }
        echo '</ul>';

        $url = wp_nonce_url(
            add_query_arg(
                [ 'action' => self::ACTION_CLEAR_LOG, 'post_id' => (int) $post->ID ],
                admin_url( 'admin-post.php' )
            ),
            self::ACTION_CLEAR_LOG . '_' . $post->ID
        );
        printf(
            '<p style="margin:6px 0 0"><a href="%s" class="button-link" style="color:#b32d2e">%s</a></p>',
            esc_url( $url ),
            esc_html__( 'Clear history', 'chaincast' )
        );
        echo '</details>';
    }

    private function actionLabel( string $action, string $connector ): string {
        $verb = match ( $action ) {
            'publish'  => __( 'Published', 'chaincast' ),
            'update'   => __( 'Updated', 'chaincast' ),
            'keychain' => __( 'Keychain', 'chaincast' ),
            default    => $action,
        };
        return '' !== $connector ? $verb . ' · ' . ucfirst( $connector ) : $verb;
    }

    private function formatTime( int $timestamp ): string {
        if ( $timestamp <= 0 ) {
            return '';
        }
        $format = (string) ( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
        $local  = function_exists( 'wp_date' ) ? wp_date( $format, $timestamp ) : gmdate( $format, $timestamp );
        return (string) $local;
    }

    private function renderConnectorRow( WP_Post $post, ConnectorInterface $connector ): void {
        $id     = $connector->id();
        $state  = $this->state->get( (int) $post->ID, $id );
        $status = (string) ( $state['status'] ?? PostState::STATUS_NONE );

        echo '<div style="margin:0 0 12px;padding-bottom:10px;border-bottom:1px solid #eee">';
        printf( '<strong>%s</strong><br>', esc_html( $connector->label() ) );

        // Status, with a link if published.
        if ( PostState::STATUS_PUBLISHED === $status && ! empty( $state['url'] ) ) {
            printf(
                '%s <a href="%s" target="_blank" rel="noopener">%s</a>',
                esc_html__( 'Published ✓', 'chaincast' ),
                esc_url( (string) $state['url'] ),
                esc_html__( 'view', 'chaincast' )
            );
        } else {
            echo esc_html( $this->statusLabel( $status ) );
            if ( PostState::STATUS_FAILED === $status && ! empty( $state['error'] ) ) {
                printf( '<br><span style="color:#b32d2e">%s</span>', esc_html( (string) $state['error'] ) );
            }
        }

        // Manual button (nonce link; avoids nested forms in the editor).
        if ( $connector->supportsAutomatic() && 'auto-draft' !== $post->post_status ) {
            $label = PostState::STATUS_PUBLISHED === $status
                ? __( 'Update on %s now', 'chaincast' )
                : __( 'Publish to %s now', 'chaincast' );

            $url = wp_nonce_url(
                add_query_arg(
                    [
                        'action'       => self::ACTION,
                        'post_id'      => (int) $post->ID,
                        'connector_id' => $id,
                    ],
                    admin_url( 'admin-post.php' )
                ),
                self::ACTION . '_' . $post->ID . '_' . $id
            );

            printf(
                '<p style="margin:8px 0 0"><a href="%s" class="button button-secondary">%s</a></p>',
                esc_url( $url ),
                esc_html( sprintf( $label, $connector->label() ) )
            );
        }

        // Assisted mode with Keychain (signs in the browser, no key on the server).
        // Hive: Hive Keychain, Steem: Steem Keychain (different extensions).
        $extension = $connector->keychainExtension();
        if ( null !== $extension && 'auto-draft' !== $post->post_status ) {
            printf(
                '<p style="margin:8px 0 0"><a href="#" class="button cc-keychain-btn" data-post="%d" data-connector="%s" data-extension="%s">%s</a> <span class="cc-keychain-status" style="display:block;margin-top:4px"></span></p>',
                (int) $post->ID,
                esc_attr( $id ),
                esc_attr( $extension ),
                esc_html( sprintf(
                    /* translators: %s: chain name, e.g. Hive */
                    __( 'Publish with %s Keychain', 'chaincast' ),
                    $connector->label()
                ) )
            );
        }

        echo '</div>';
    }

    public function handlePublishNow(): void {
        $postId      = (int) ( $_REQUEST['post_id'] ?? 0 );
        $connectorId = sanitize_key( (string) ( $_REQUEST['connector_id'] ?? '' ) );

        if ( ! current_user_can( 'edit_post', $postId ) ) {
            wp_die( esc_html__( 'Permission denied.', 'chaincast' ) );
        }
        check_admin_referer( self::ACTION . '_' . $postId . '_' . $connectorId );

        $wasPublished = PostState::STATUS_PUBLISHED === $this->state->status( $postId, $connectorId );

        $result = $this->publisher->publishNow( $postId, $connectorId );

        // A failed update must not wipe a prior published state: the post is still
        // on-chain. Only mark failed when it wasn't already published.
        if ( ! $result->success && ! $wasPublished ) {
            $this->state->markFailed( $postId, $connectorId, (string) $result->error );
        }

        set_transient(
            'chaincast_publish_' . get_current_user_id() . '_' . $postId,
            [
                'ok'  => $result->success,
                'msg' => $result->success
                    ? sprintf( __( 'Published to the chain: %s', 'chaincast' ), (string) $result->url )
                    : (string) $result->error,
            ],
            60
        );

        $redirect = get_edit_post_link( $postId, 'raw' );
        wp_safe_redirect( $redirect ?: admin_url() );
        exit;
    }

    public function handleClearLog(): void {
        $postId = (int) ( $_REQUEST['post_id'] ?? 0 );

        if ( ! current_user_can( 'edit_post', $postId ) ) {
            wp_die( esc_html__( 'Permission denied.', 'chaincast' ) );
        }
        check_admin_referer( self::ACTION_CLEAR_LOG . '_' . $postId );

        $this->log->clear( $postId );

        $redirect = get_edit_post_link( $postId, 'raw' );
        wp_safe_redirect( $redirect ?: admin_url() );
        exit;
    }

    private function renderNotice( int $postId ): void {
        $key     = 'chaincast_publish_' . get_current_user_id() . '_' . $postId;
        $payload = get_transient( $key );
        if ( ! is_array( $payload ) ) {
            return;
        }
        delete_transient( $key );

        $color = ! empty( $payload['ok'] ) ? '#008a20' : '#b32d2e';
        printf(
            '<p style="color:%s"><strong>%s</strong></p>',
            esc_attr( $color ),
            esc_html( (string) ( $payload['msg'] ?? '' ) )
        );
    }

    private function statusLabel( string $status ): string {
        return match ( $status ) {
            PostState::STATUS_PUBLISHED => __( 'Published ✓', 'chaincast' ),
            PostState::STATUS_QUEUED    => __( 'Queued…', 'chaincast' ),
            PostState::STATUS_FAILED    => __( 'Error', 'chaincast' ),
            default                     => __( 'Not published', 'chaincast' ),
        };
    }
}
