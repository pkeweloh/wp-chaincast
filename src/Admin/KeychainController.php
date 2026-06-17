<?php
/**
 * Modo asistido con Hive Keychain.
 *
 * Encola el JS del editor y atiende dos peticiones AJAX:
 *  - request: devuelve la operación a firmar (sin clave, sin red).
 *  - confirm: registra el broadcast que hizo el navegador (permlink + tx id).
 *
 * @package Chaincast\Admin
 */

declare(strict_types=1);

namespace Chaincast\Admin;

use Chaincast\Core\PublishService;

final class KeychainController {

    private const NONCE          = 'chaincast_keychain';
    private const ACTION_REQUEST = 'chaincast_keychain_request';
    private const ACTION_CONFIRM = 'chaincast_keychain_confirm';

    public function __construct(
        private PublishService $publisher,
    ) {
    }

    public function register(): void {
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
        add_action( 'wp_ajax_' . self::ACTION_REQUEST, [ $this, 'ajaxRequest' ] );
        add_action( 'wp_ajax_' . self::ACTION_CONFIRM, [ $this, 'ajaxConfirm' ] );
    }

    public function enqueue( string $hook ): void {
        if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
            return;
        }

        wp_enqueue_script(
            'cc-keychain',
            plugins_url( 'assets/js/keychain.js', \Chaincast\PLUGIN_FILE ),
            [],
            \Chaincast\VERSION,
            true
        );

        wp_localize_script(
            'cc-keychain',
            'chaincastKeychain',
            [
                'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
                'nonce'         => wp_create_nonce( self::NONCE ),
                'actionRequest' => self::ACTION_REQUEST,
                'actionConfirm' => self::ACTION_CONFIRM,
                'i18n'          => [
                    'noKeychain' => __( 'Hive Keychain not detected. Install the extension in your browser.', 'chaincast' ),
                    'working'    => __( 'Opening Keychain…', 'chaincast' ),
                    'published'  => __( 'Published ✓', 'chaincast' ),
                    'error'      => __( 'Error', 'chaincast' ),
                ],
            ]
        );
    }

    public function ajaxRequest(): void {
        check_ajax_referer( self::NONCE, 'nonce' );

        $postId      = (int) ( $_POST['post_id'] ?? 0 );
        $connectorId = sanitize_key( (string) ( $_POST['connector_id'] ?? '' ) );

        if ( ! current_user_can( 'edit_post', $postId ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'chaincast' ) ] );
        }

        $req = $this->publisher->buildSigningRequest( $postId, $connectorId );
        if ( null === $req ) {
            wp_send_json_error( [ 'message' => __( 'Could not prepare the operation.', 'chaincast' ) ] );
        }

        wp_send_json_success( $req );
    }

    public function ajaxConfirm(): void {
        check_ajax_referer( self::NONCE, 'nonce' );

        $postId      = (int) ( $_POST['post_id'] ?? 0 );
        $connectorId = sanitize_key( (string) ( $_POST['connector_id'] ?? '' ) );
        $permlink    = sanitize_title( (string) ( $_POST['permlink'] ?? '' ) );
        $txId        = sanitize_text_field( (string) ( $_POST['tx_id'] ?? '' ) );

        if ( ! current_user_can( 'edit_post', $postId ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'chaincast' ) ] );
        }
        if ( '' === $permlink ) {
            wp_send_json_error( [ 'message' => __( 'Missing permlink.', 'chaincast' ) ] );
        }

        $result = $this->publisher->confirmExternal( $postId, $connectorId, $permlink, $txId );

        wp_send_json_success( [ 'url' => $result->url ] );
    }
}
