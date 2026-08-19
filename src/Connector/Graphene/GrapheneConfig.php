<?php
/**
 * Configuration of a graphene connector (Hive/Steem).
 *
 * The posting key is stored ENCRYPTED (Vault payload), never in clear. The
 * connector decrypts it right before signing and does not retain it.
 *
 * @package Chaincast\Connector\Graphene
 */

declare(strict_types=1);

namespace Chaincast\Connector\Graphene;

final class GrapheneConfig {

    /** Payout modes of comment_options, the three the chain frontends offer. */
    public const PAYOUT_DEFAULT  = 'default';   // half liquid, half Power.
    public const PAYOUT_POWER_UP = 'power_up';  // everything as Power.
    public const PAYOUT_DECLINED = 'declined';  // no reward at all.

    /**
     * @param string   $author              Account on the chain (e.g. 'demo-author').
     * @param ?string  $encryptedPostingKey Vault-encrypted posting key, or null.
     * @param string   $defaultTag          Default main tag (parent_permlink).
     * @param string[] $nodes               RPC nodes; empty: use the connector's own.
     * @param string   $payout              One of the PAYOUT_* modes.
     */
    public function __construct(
        public readonly string $author,
        public readonly ?string $encryptedPostingKey = null,
        public readonly string $defaultTag = 'blog',
        public readonly array $nodes = [],
        public readonly string $payout = self::PAYOUT_DEFAULT,
    ) {
    }

    public function hasPostingKey(): bool {
        return null !== $this->encryptedPostingKey && '' !== $this->encryptedPostingKey;
    }
}
