<?php
/**
 * Connector for the Steem blockchain.
 *
 * Twin of HiveConnector: reuses all of AbstractGrapheneConnector's logic and only
 * changes Steem's specifics (chain_id, nodes, URL domain). The address prefix is
 * 'STM', same as Hive.
 *
 * @package Chaincast\Connector\Graphene
 */

declare(strict_types=1);

namespace Chaincast\Connector\Graphene;

final class SteemConnector extends AbstractGrapheneConnector {

    /** Steem chain id (genesis, all zeros). */
    private const CHAIN_ID = '0000000000000000000000000000000000000000000000000000000000000000';

    /** Public RPC nodes with failover. */
    public const DEFAULT_NODES = [
        'https://api.steemit.com',
        'https://api.steem.fans',
        'https://api.justyy.com',
        'https://steemd.privex.io',
    ];

    public function id(): string {
        return 'steem';
    }

    public function label(): string {
        return 'Steem';
    }

    protected function chainId(): string {
        return self::CHAIN_ID;
    }

    protected function addressPrefix(): string {
        return 'STM';
    }

    protected function postUrl( string $author, string $permlink ): string {
        return sprintf( 'https://steemit.com/@%s/%s', $author, $permlink );
    }

    /** Steem uses SBD as the chain dollar in the broadcast JSON. */
    protected function backingSymbol(): string {
        return 'SBD';
    }

    /** On Steem the comment_options field is named percent_steem_dollars. */
    protected function percentField(): string {
        return 'percent_steem_dollars';
    }
}
