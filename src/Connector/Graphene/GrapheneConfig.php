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

    /**
     * @param string   $author              Account on the chain (e.g. 'skunk1').
     * @param ?string  $encryptedPostingKey Vault-encrypted posting key, or null.
     * @param string   $defaultTag          Default main tag (parent_permlink).
     * @param string[] $nodes               RPC nodes; empty: use the connector's own.
     */
    public function __construct(
        public readonly string $author,
        public readonly ?string $encryptedPostingKey = null,
        public readonly string $defaultTag = 'blog',
        public readonly array $nodes = [],
    ) {
    }

    public function hasPostingKey(): bool {
        return null !== $this->encryptedPostingKey && '' !== $this->encryptedPostingKey;
    }
}
