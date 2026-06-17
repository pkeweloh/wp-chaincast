<?php
/**
 * Metabox por-entrada en el editor.
 *
 * Muestra, por cada conector, el estado de publicación (con enlace si está
 * publicado) y un botón para **publicar/actualizar ahora** en esa cadena. Así el
 * usuario decide cuándo publicar, independientemente de pulsar "Publicar" en WP.
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

        echo '<hr>';
        foreach ( $all as $connector ) {
            $this->renderConnectorRow( $post, $connector );
        }

        $this->renderLog( $post );
    }

    /**
     * Historial de intentos de publicación de la entrada (todas las cadenas/vías).
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

        // Más recientes primero.
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

        // Estado, con enlace si está publicado.
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

        // Botón manual (enlace con nonce; evita formularios anidados en el editor).
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

        // Modo asistido con Keychain (firma en el navegador, sin clave en el servidor).
        // Hive → Hive Keychain, Steem → Steem Keychain (extensiones distintas).
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
