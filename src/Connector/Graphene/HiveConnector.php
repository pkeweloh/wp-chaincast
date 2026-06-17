<?php
/**
 * Conector para la blockchain Hive.
 *
 * Solo aporta lo específico de Hive sobre AbstractGrapheneConnector: chain_id,
 * prefijo de direcciones y dominio de URL. Steem será el "gemelo" con otros valores.
 *
 * @package Chaincast\Connector\Graphene
 */

declare(strict_types=1);

namespace Chaincast\Connector\Graphene;

final class HiveConnector extends AbstractGrapheneConnector {

    /** Chain id de Hive (HF24+). */
    private const CHAIN_ID = 'beeab0de00000000000000000000000000000000000000000000000000000000';

    /** Nodos RPC públicos con failover. */
    public const DEFAULT_NODES = [
        'https://api.hive.blog',
        'https://api.deathwing.me',
        'https://rpc.ausbit.dev',
        'https://anyx.io',
        'https://rpc.ecency.com',
    ];

    public function id(): string {
        return 'hive';
    }

    public function label(): string {
        return 'Hive';
    }

    protected function chainId(): string {
        return self::CHAIN_ID;
    }

    protected function addressPrefix(): string {
        return 'STM';
    }

    protected function postUrl( string $author, string $permlink ): string {
        return sprintf( 'https://hive.blog/@%s/%s', $author, $permlink );
    }
}
