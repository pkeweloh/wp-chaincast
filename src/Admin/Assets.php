<?php
/**
 * Registration/enqueueing of shared admin assets.
 *
 * Centralizes the reward-sharing (beneficiaries) widget and the stylesheet,
 * used by both the Settings page and the editor metabox.
 *
 * @package Chaincast\Admin
 */

declare(strict_types=1);

namespace Chaincast\Admin;

final class Assets {

    /** Encola la hoja de estilos del admin (idempotente). */
    public static function enqueueStyle(): void {
        wp_enqueue_style(
            'cc-admin',
            plugins_url( 'assets/css/admin.css', \Chaincast\PLUGIN_FILE ),
            [],
            \Chaincast\VERSION
        );
    }

    /** Encola el widget de reparto de recompensas con sus textos traducibles. */
    public static function enqueueBeneficiaries(): void {
        wp_enqueue_script(
            'cc-beneficiaries',
            plugins_url( 'assets/js/beneficiaries.js', \Chaincast\PLUGIN_FILE ),
            [],
            \Chaincast\VERSION,
            true
        );

        wp_localize_script(
            'cc-beneficiaries',
            'chaincastBenef',
            [
                'account'   => __( 'Account', 'chaincast' ),
                'accountPh' => __( 'account', 'chaincast' ),
                'add'       => __( 'Add beneficiary', 'chaincast' ),
                'remove'    => __( 'Remove', 'chaincast' ),
                'assigned'  => __( 'Assigned', 'chaincast' ),
                'mine'      => __( 'For you', 'chaincast' ),
                'empty'     => __( 'No reward sharing — you keep 100%', 'chaincast' ),
            ]
        );
    }

    /**
     * Encola el realce del campo "Posting key" (muestra solo el badge cuando hay
     * clave guardada, con un enlace para reemplazarla). No necesita textos: todo
     * el contenido visible se pinta en el server.
     */
    public static function enqueuePostingKey(): void {
        wp_enqueue_script(
            'cc-posting-key',
            plugins_url( 'assets/js/posting-key.js', \Chaincast\PLUGIN_FILE ),
            [],
            \Chaincast\VERSION,
            true
        );
    }
}
