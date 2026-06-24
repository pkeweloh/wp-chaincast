<?php
/**
 * Connector for the Hive blockchain.
 *
 * Only supplies Hive's specifics over AbstractGrapheneConnector: chain_id,
 * address prefix and URL domain. Steem is the "twin" with different values.
 *
 * @package Chaincast\Connector\Graphene
 */

declare(strict_types=1);

namespace Chaincast\Connector\Graphene;

final class HiveConnector extends AbstractGrapheneConnector {

    /** Hive chain id (HF24+). */
    private const CHAIN_ID = 'beeab0de00000000000000000000000000000000000000000000000000000000';

    /** Public RPC nodes with failover. */
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
