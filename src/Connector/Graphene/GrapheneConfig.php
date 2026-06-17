<?php
/**
 * Configuración de un conector graphene (Hive/Steem).
 *
 * La posting key se guarda CIFRADA (payload del Vault), nunca en claro. El
 * conector la descifra justo antes de firmar y no la retiene.
 *
 * @package Chaincast\Connector\Graphene
 */

declare(strict_types=1);

namespace Chaincast\Connector\Graphene;

final class GrapheneConfig {

    /**
     * @param string   $author              Cuenta en la cadena (p. ej. 'skunk1').
     * @param ?string  $encryptedPostingKey Posting key cifrada con el Vault, o null.
     * @param string   $defaultTag          Tag principal por defecto (parent_permlink).
     * @param string[] $nodes               Nodos RPC; vacío = usar los del conector.
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
