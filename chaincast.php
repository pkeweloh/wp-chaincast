<?php
/**
 * Plugin Name:       Chaincast
 * Plugin URI:        https://github.com/pkeweloh/wp-chaincast
 * Description:        Auto-publish WordPress posts to the Hive and Steem blockchains via pluggable per-chain connectors.
 * Version:           0.1.0-dev
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            Philipp Keweloh
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       chaincast
 * Domain Path:       /languages
 *
 * @package Chaincast
 */

declare(strict_types=1);

namespace Chaincast;

if ( ! defined( 'ABSPATH' ) ) {
    exit; // No direct access.
}

const VERSION     = '0.1.0-dev';
const PLUGIN_FILE  = __FILE__;
const PLUGIN_DIR   = __DIR__;
const MIN_PHP      = '8.1';

/**
 * Loads the autoloader (Composer if present; otherwise our own PSR-4 fallback so
 * at least the plugin classes resolve).
 */
require_once __DIR__ . '/src/autoload.php';

/**
 * Checks minimum requirements and shows a notice instead of causing a fatal on
 * old hosting.
 */
function check_requirements(): bool {
    if ( version_compare( PHP_VERSION, MIN_PHP, '<' ) ) {
        add_action(
            'admin_notices',
            static function (): void {
                $msg = sprintf(
                    /* translators: 1: required PHP version, 2: current PHP version */
                    esc_html__( 'Chaincast requires PHP %1$s or higher. Current version: %2$s.', 'chaincast' ),
                    MIN_PHP,
                    PHP_VERSION
                );
                printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $msg ) );
            }
        );

        return false;
    }

    return true;
}

/**
 * Boots the plugin after all plugins are loaded (so Action Scheduler, if it comes
 * from another plugin like WooCommerce, is already available).
 */
function bootstrap(): void {
    load_plugin_textdomain(
        'chaincast',
        false,
        dirname( plugin_basename( __FILE__ ) ) . '/languages'
    );

    if ( ! check_requirements() ) {
        return;
    }

    Core\Plugin::instance()->boot();
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\\bootstrap' );

// Activation / deactivation hooks (queue registration, cleanup).
register_activation_hook( __FILE__, [ Core\Plugin::class, 'on_activate' ] );
register_deactivation_hook( __FILE__, [ Core\Plugin::class, 'on_deactivate' ] );
