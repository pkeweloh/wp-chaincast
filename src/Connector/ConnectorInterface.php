<?php
/**
 * Common contract every chain connector must implement.
 *
 * The plugin core knows no concrete blockchain: it talks only to this interface.
 * Adding a new chain (Hive, Steem, Lens, LikeCoin...) means implementing this
 * interface, without touching the core.
 *
 * @package Chaincast\Connector
 */

declare(strict_types=1);

namespace Chaincast\Connector;

interface ConnectorInterface {

    /**
     * Stable, unique chain identifier: 'hive', 'steem', ...
     * Used as the key in post meta, settings and the queue.
     */
    public function id(): string;

    /**
     * Human-readable name for the UI ("Hive", "Steem").
     */
    public function label(): string;

    /**
     * Does it have the minimum configuration to operate (author, nodes, etc.)?
     */
    public function isConfigured(): bool;

    /**
     * Can it publish automatically (a signing key is available and decryptable
     * on the server)? If false, only assisted mode is possible.
     */
    public function supportsAutomatic(): bool;

    /**
     * Assisted mode: name of the global object the browser signing extension
     * exposes for this chain ('hive_keychain', 'steem_keychain'), or null if the
     * chain has no extension-assisted signing.
     */
    public function keychainExtension(): ?string;

    /**
     * Validates the current credentials/configuration against the network if applicable.
     */
    public function validateCredentials(): Result;

    /**
     * Automatic mode: serializes, signs and broadcasts the transaction.
     */
    public function publish(PostPayload $post): PublishResult;

    /**
     * Assisted mode: prepares the operation(s) for the browser to sign (e.g. Hive
     * Keychain). Returns the structure the JS will consume:
     * { account, operations: [[name, fields], ...], permlink }.
     *
     * @return array{account:string,operations:array<int,array{0:string,1:array<string,mixed>}>,permlink:string}
     */
    public function buildSigningRequest(PostPayload $post): array;

    /**
     * Assisted mode: records the result of a broadcast made outside the server
     * (the browser reports the permlink/tx after signing).
     */
    public function confirmExternalBroadcast(string $ref): PublishResult;
}
