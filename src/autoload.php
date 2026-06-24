<?php
/**
 * Dependency loading and autoload.
 *
 * Priority:
 *   1. Composer autoloader (vendor/), which includes our classes plus the
 *      crypto dependencies (simplito/elliptic-php, base58) and Action Scheduler.
 *   2. Our own minimal PSR-4 fallback for the plugin classes when `composer
 *      install` has not run yet (external dependencies will still be missing,
 *      but the plugin can at least show admin notices).
 *
 * @package Chaincast
 */

declare(strict_types=1);

namespace Chaincast;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$composer = __DIR__ . '/../vendor/autoload.php';

if ( is_readable( $composer ) ) {
    require_once $composer;

    // Action Scheduler does not init from the autoload alone: we must require its
    // main file so it registers its store, hooks and WP-CLI command.
    $actionScheduler = __DIR__ . '/../vendor/woocommerce/action-scheduler/action-scheduler.php';
    if ( is_readable( $actionScheduler ) ) {
        require_once $actionScheduler;
    }
} else {
    // Minimal PSR-4 fallback for Chaincast\* to src/*.
    spl_autoload_register(
        static function ( string $class ): void {
            $prefix  = __NAMESPACE__ . '\\';
            $baseDir = __DIR__ . '/';

            if ( ! str_starts_with( $class, $prefix ) ) {
                return;
            }

            $relative = substr( $class, strlen( $prefix ) );
            $file     = $baseDir . str_replace( '\\', '/', $relative ) . '.php';

            if ( is_readable( $file ) ) {
                require_once $file;
            }
        }
    );

    add_action(
        'admin_notices',
        static function (): void {
            printf(
                '<div class="notice notice-warning"><p>%s</p></div>',
                esc_html__( 'Chaincast: Composer dependencies are missing. Run "composer install" in the plugin folder to enable signing and the queue.', 'chaincast' )
            );
        }
    );
}
