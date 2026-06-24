<?php
/**
 * Plugin orchestrator: instantiates the components and registers the hooks.
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
     * Registers all hooks. Idempotent.
     */
    public function boot(): void {
        if ( $this->booted ) {
            return;
        }
        $this->booted = true;

        /**
         * Extension point: connectors register here. The plugin's bootstrap hooks
         * up the connectors based on the settings; external code can add more
         * connectors through this same hook.
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
     * Activation: ensures Action Scheduler is available (shipped by the Composer
     * dependency) and leaves room for future migration tasks.
     */
    public static function on_activate(): void {
        // Nothing destructive. Options are created lazily.
    }

    /**
     * Deactivation: clears the queue's pending scheduled actions.
     */
    public static function on_deactivate(): void {
        if ( function_exists( 'as_unschedule_all_actions' ) ) {
            as_unschedule_all_actions( Queue::HOOK );
        }
    }
}
