<?php
/**
 * Orquestador del plugin: instancia los componentes y registra los hooks.
 *
 * En la Fase 0 no hay lógica de cadena: solo se cablea la estructura
 * (ajustes, metabox, cola, controlador de publicación y registro de conectores).
 *
 * @package Chaincast\Core
 */

declare(strict_types=1);

namespace Chaincast\Core;

use Chaincast\Admin\KeychainController;
use Chaincast\Admin\MetaBox;
use Chaincast\Admin\SettingsPage;
use Chaincast\Connector\Content\HtmlToMarkdown;
use Chaincast\Connector\PayloadFactory;
use Chaincast\Core\State\PostState;
use Chaincast\Frontend\ChainLinks;

final class Plugin {

    private static ?self $instance = null;

    private ConnectorRegistry $connectors;
    private Settings $settingsRepo;
    private ConnectorBootstrap $connectorBootstrap;
    private Queue $queue;
    private PublishController $controller;
    private SettingsPage $settingsPage;
    private MetaBox $metabox;
    private KeychainController $keychain;
    private ChainLinks $chainLinks;

    private bool $booted = false;

    private function __construct() {
        $this->connectors         = new ConnectorRegistry();
        $this->settingsRepo       = new Settings();
        $this->connectorBootstrap = new ConnectorBootstrap( $this->settingsRepo );

        $publisher = new PublishService(
            $this->connectors,
            new PayloadFactory( new HtmlToMarkdown() ),
            new PostState(),
            $this->settingsRepo
        );

        $this->queue        = new Queue( $publisher );
        $this->controller   = new PublishController( $this->connectors, $this->queue, $this->settingsRepo );
        $this->settingsPage = new SettingsPage( $this->connectors, $this->settingsRepo );
        $this->metabox      = new MetaBox( $this->connectors, $publisher );
        $this->keychain     = new KeychainController( $publisher );
        $this->chainLinks   = new ChainLinks( $this->connectors, new PostState() );
    }

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    /**
     * Registra todos los hooks. Idempotente.
     */
    public function boot(): void {
        if ( $this->booted ) {
            return;
        }
        $this->booted = true;

        /**
         * Punto de extensión: aquí los conectores se registran. El bootstrap del
         * plugin engancha HiveConnector según los ajustes; código externo puede
         * añadir más conectores con este mismo hook.
         *
         * @param ConnectorRegistry $registry
         */
        add_action( 'chaincast_register_connectors', [ $this->connectorBootstrap, 'register' ] );
        do_action( 'chaincast_register_connectors', $this->connectors );

        $this->queue->register();
        $this->controller->register();
        $this->chainLinks->register();

        if ( is_admin() ) {
            $this->settingsPage->register();
            $this->metabox->register();
            $this->keychain->register();
        }
    }

    public function connectors(): ConnectorRegistry {
        return $this->connectors;
    }

    /**
     * Activación: asegura que Action Scheduler esté disponible (lo trae la
     * dependencia de Composer) y deja espacio para tareas de migración futuras.
     */
    public static function on_activate(): void {
        // Nada destructivo en Fase 0. Las opciones se crean perezosamente.
    }

    /**
     * Desactivación: limpia las acciones programadas pendientes de la cola.
     */
    public static function on_deactivate(): void {
        if ( function_exists( 'as_unschedule_all_actions' ) ) {
            as_unschedule_all_actions( Queue::HOOK );
        }
    }
}
