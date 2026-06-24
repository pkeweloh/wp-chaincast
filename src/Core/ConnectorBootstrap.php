<?php
/**
 * Builds and registers the concrete connectors from the settings.
 *
 * Hooks into `chaincast_register_connectors`. Wires each connector's
 * dependencies here (RPC over the WordPress transport, Vault, signing).
 *
 * @package Chaincast\Core
 */

declare(strict_types=1);

namespace Chaincast\Core;

use Chaincast\Connector\Content\JsonMetadata;
use Chaincast\Connector\Content\PermlinkGenerator;
use Chaincast\Connector\Graphene\AbstractGrapheneConnector;
use Chaincast\Connector\Graphene\GrapheneConfig;
use Chaincast\Connector\Graphene\HiveConnector;
use Chaincast\Connector\Graphene\SteemConnector;
use Chaincast\Core\Crypto\Secp256k1;
use Chaincast\Core\Crypto\Vault;
use Chaincast\Core\Rpc\RpcClient;
use Chaincast\Core\Rpc\WpHttpTransport;

final class ConnectorBootstrap {

    /**
     * Supported graphene connectors: id => [class, default nodes, default tag].
     *
     * @var array<string,array{class:class-string<AbstractGrapheneConnector>,nodes:string[],tag:string}>
     */
    private const GRAPHENE = [
        'hive'  => [ 'class' => HiveConnector::class, 'nodes' => HiveConnector::DEFAULT_NODES, 'tag' => 'blog' ],
        'steem' => [ 'class' => SteemConnector::class, 'nodes' => SteemConnector::DEFAULT_NODES, 'tag' => 'blog' ],
    ];

    public function __construct(
        private Settings $settings,
    ) {
    }

    public function register( ConnectorRegistry $registry ): void {
        foreach ( self::GRAPHENE as $id => $spec ) {
            if ( $this->settings->isEnabled( $id ) ) {
                $registry->add( $this->buildGraphene( $id, $spec ) );
            }
        }
    }

    /**
     * @param array{class:class-string<AbstractGrapheneConnector>,nodes:string[],tag:string} $spec
     */
    private function buildGraphene( string $id, array $spec ): AbstractGrapheneConnector {
        $enc = (string) $this->settings->get( $id, 'posting_key_enc', '' );

        $config = new GrapheneConfig(
            author: (string) $this->settings->get( $id, 'author', '' ),
            encryptedPostingKey: '' !== $enc ? $enc : null,
            defaultTag: (string) $this->settings->get( $id, 'default_tag', $spec['tag'] ),
        );

        $rpc   = new RpcClient( $spec['nodes'], new WpHttpTransport() );
        $class = $spec['class'];

        return new $class(
            $config,
            $rpc,
            Vault::fromWpConfig(),
            new Secp256k1(),
            new PermlinkGenerator(),
            new JsonMetadata(),
        );
    }
}
