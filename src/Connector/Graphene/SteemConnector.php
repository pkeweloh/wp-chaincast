<?php
/**
 * Conector para la blockchain Steem.
 *
 * Gemelo de HiveConnector: reutiliza toda la lógica de AbstractGrapheneConnector
 * y solo cambia lo específico de Steem (chain_id, nodos, dominio de URL). El
 * prefijo de direcciones es 'STM', igual que Hive.
 *
 * @package Chaincast\Connector\Graphene
 */

declare(strict_types=1);

namespace Chaincast\Connector\Graphene;

final class SteemConnector extends AbstractGrapheneConnector {

    /** Chain id de Steem (génesis, todo ceros). */
    private const CHAIN_ID = '0000000000000000000000000000000000000000000000000000000000000000';

    /** Nodos RPC públicos con failover. */
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

    /** Steem usa SBD como dólar de la cadena en el JSON del broadcast. */
    protected function backingSymbol(): string {
        return 'SBD';
    }

    /** En Steem el campo de comment_options se llama percent_steem_dollars. */
    protected function percentField(): string {
        return 'percent_steem_dollars';
    }
}
