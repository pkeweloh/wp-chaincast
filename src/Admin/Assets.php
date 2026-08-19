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

    /**
     * Help icon with its tooltip. The text must come already escaped for an
     * attribute.
     *
     * Inside the editor's meta box panel the CSS bubble cannot be used: it is
     * painted below the editor whichever way it opens, because that panel is its
     * own stacking context. There the browser's native tooltip takes over, which
     * is plainer but always renders on top.
     */
    public static function renderHelp( string $textEscaped, bool $inPanel = false ): void {
        printf(
            '<span class="cc-help" tabindex="0" role="img" aria-label="%s" %s="%s">?</span>',
            $textEscaped,
            $inPanel ? 'title' : 'data-tip',
            $textEscaped
        );
    }

    /** Enqueues the admin stylesheet (idempotent). */
    public static function enqueueStyle(): void {
        wp_enqueue_style(
            'cc-admin',
            plugins_url( 'assets/css/admin.css', \Chaincast\PLUGIN_FILE ),
            [],
            \Chaincast\VERSION
        );
    }

    /** Enqueues the reward-sharing widget with its translatable strings. */
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
                'empty'     => __( 'No reward sharing: you keep 100%', 'chaincast' ),
            ]
        );
    }

    /**
     * Enqueues the "Posting key" field enhancement (shows only the badge when a
     * key is stored, with a link to replace it). Needs no strings: all visible
     * content is rendered on the server.
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
