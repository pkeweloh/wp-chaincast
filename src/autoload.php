<?php
/**
 * Carga de dependencias y autoload.
 *
 * Prioridad:
 *   1. Autoloader de Composer (vendor/), que incluye nuestras clases + las
 *      dependencias criptográficas (simplito/elliptic-php, base58) y Action Scheduler.
 *   2. Fallback PSR-4 propio para las clases del plugin si todavía no se ha
 *      ejecutado `composer install` (las dependencias externas seguirán faltando,
 *      pero el plugin podrá al menos mostrar avisos en el admin).
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

    // Action Scheduler no se inicializa solo con el autoload: hay que requerir su
    // fichero principal para que registre su almacén, hooks y comando WP-CLI.
    $actionScheduler = __DIR__ . '/../vendor/woocommerce/action-scheduler/action-scheduler.php';
    if ( is_readable( $actionScheduler ) ) {
        require_once $actionScheduler;
    }
} else {
    // Fallback PSR-4 mínimo para Chaincast\* -> src/*.
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
