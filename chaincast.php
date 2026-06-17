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
    exit; // No acceso directo.
}

const VERSION     = '0.1.0-dev';
const PLUGIN_FILE  = __FILE__;
const PLUGIN_DIR   = __DIR__;
const MIN_PHP      = '8.1';

/**
 * Carga el autoloader (Composer si existe; si no, un fallback PSR-4 propio
 * para que al menos las clases del plugin se resuelvan).
 */
require_once __DIR__ . '/src/autoload.php';

/**
 * Comprueba requisitos mínimos y muestra un aviso en vez de provocar un fatal
 * en hostings antiguos.
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
 * Arranque del plugin tras cargar todos los plugins (para que Action Scheduler,
 * si viene de otro plugin como WooCommerce, ya esté disponible).
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

// Hooks de activación / desactivación (registro de la cola, limpieza).
register_activation_hook( __FILE__, [ Core\Plugin::class, 'on_activate' ] );
register_deactivation_hook( __FILE__, [ Core\Plugin::class, 'on_deactivate' ] );
