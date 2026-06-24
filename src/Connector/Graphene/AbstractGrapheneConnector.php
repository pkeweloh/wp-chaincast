<?php
/**
 * Shared logic for the graphene connectors (Hive and Steem).
 *
 * Implements the automatic publishing flow: fetches the reference block from a
 * node, builds the comment op from the PostPayload, serializes, signs and
 * broadcasts with failover. Subclasses only supply the chain-specific parts:
 * chain_id, address prefix, default nodes and URL domain.
 *
 * @package Chaincast\Connector\Graphene
 */

declare(strict_types=1);

namespace Chaincast\Connector\Graphene;

use Throwable;
use Chaincast\Connector\ConnectorInterface;
use Chaincast\Connector\Content\JsonMetadata;
use Chaincast\Connector\Content\PermlinkGenerator;
use Chaincast\Connector\PostPayload;
use Chaincast\Connector\PublishResult;
use Chaincast\Connector\Result;
use Chaincast\Core\Crypto\Secp256k1;
use Chaincast\Core\Crypto\Vault;
use Chaincast\Core\Rpc\RpcClient;
use Chaincast\Core\Rpc\RpcException;

abstract class AbstractGrapheneConnector implements ConnectorInterface {

    /** 100% in basis points (percent_hbd/percent_steem_dollars of comment_options). */
    private const FULL_PERCENT = 10000;

    public function __construct(
        protected GrapheneConfig $config,
        protected RpcClient $rpc,
        protected ?Vault $vault,
        protected Secp256k1 $signer,
        protected PermlinkGenerator $permlinks,
        protected JsonMetadata $jsonMetadata,
    ) {
    }

    // Chain-specific

    abstract protected function chainId(): string;

    /** Address prefix (both chains use 'STM' for public keys). */
    abstract protected function addressPrefix(): string;

    abstract protected function postUrl( string $author, string $permlink ): string;

    // ConnectorInterface

    public function isConfigured(): bool {
        return '' !== $this->config->author;
    }

    public function supportsAutomatic(): bool {
        return $this->isConfigured()
            && null !== $this->vault
            && $this->config->hasPostingKey();
    }

    /**
     * Hive: 'hive_keychain', Steem: 'steem_keychain' (the convention of both
     * extensions, which are forks of each other).
     */
    public function keychainExtension(): ?string {
        return $this->id() . '_keychain';
    }

    public function validateCredentials(): Result {
        if ( ! $this->isConfigured() ) {
            return Result::fail( 'Missing author account.' );
        }
        if ( ! $this->supportsAutomatic() ) {
            return Result::fail( 'Automatic mode unavailable (no Vault or no posting key).' );
        }

        try {
            $priv   = $this->decryptKey();
            $derived = $priv->publicKey( $this->addressPrefix() )->toString();
        } catch ( Throwable $e ) {
            return Result::fail( 'Could not decrypt/derive the key: ' . $e->getMessage() );
        }

        // Check the derived public key is in the account's posting authority.
        try {
            $accounts = $this->rpc->call( 'condenser_api.get_accounts', [ [ $this->config->author ] ] );
        } catch ( RpcException $e ) {
            return Result::fail( 'Could not query the account: ' . $this->rpcDetail( $e ) );
        }

        $postingKeys = $this->extractPostingKeys( is_array( $accounts ) ? ( $accounts[0] ?? [] ) : [] );
        if ( ! in_array( $derived, $postingKeys, true ) ) {
            return Result::fail( 'The posting key does not match the account authority.' );
        }

        return Result::ok( [ 'public_key' => $derived ] );
    }

    public function publish( PostPayload $post ): PublishResult {
        if ( ! $this->supportsAutomatic() ) {
            return PublishResult::failure( 'Automatic mode unavailable for ' . $this->id() . '.' );
        }

        try {
            $priv = $this->decryptKey();
        } catch ( Throwable $e ) {
            return PublishResult::failure( 'Unusable posting key: ' . $e->getMessage() );
        }

        $permlink = $this->permlinkFor( $post );

        try {
            $props      = $this->rpc->getDynamicGlobalProperties();
            $ref        = RpcClient::referenceBlock( $props );
            $expiration = $this->expiration( $props );
        } catch ( RpcException $e ) {
            // Node failure: retryable.
            return PublishResult::failure( 'Could not fetch global properties: ' . $this->rpcDetail( $e ), true );
        }

        $ops = [
            [
                'name'      => 'comment',
                'serialize' => [
                    'parent_author'   => '',
                    'parent_permlink' => $this->parentPermlink( $post ),
                    'author'          => $this->config->author,
                    'permlink'        => $permlink,
                    'title'           => $post->title,
                    'body'            => $post->body,
                    'json_metadata'   => $this->jsonMetadata->build( $post ),
                ],
            ],
        ];

        // Beneficiaries (reward split) only when CREATING the post.
        $beneficiariesOp = $this->beneficiariesOp( $post, $permlink );
        if ( null !== $beneficiariesOp ) {
            $ops[] = $beneficiariesOp;
        }

        $signedTx = $this->buildSignedTransaction( $ref, $expiration, $ops, $priv );

        try {
            $this->rpc->broadcastTransaction( $signedTx['tx'] );
        } catch ( RpcException $e ) {
            // Telling a network error (retryable) from a chain rejection is not
            // trivial here; treat as retryable unless there is evidence otherwise.
            return PublishResult::failure( 'Broadcast failed: ' . $this->rpcDetail( $e ), true );
        }

        return PublishResult::success(
            $permlink,
            $this->postUrl( $this->config->author, $permlink ),
            $signedTx['trx_id']
        );
    }

    /**
     * Deletes one of our own posts/comments from the chain (delete_comment op).
     * Only works if the post has no votes and no replies.
     */
    public function deletePost( string $permlink ): PublishResult {
        if ( ! $this->supportsAutomatic() ) {
            return PublishResult::failure( 'Automatic mode unavailable for ' . $this->id() . '.' );
        }

        try {
            $priv = $this->decryptKey();
        } catch ( Throwable $e ) {
            return PublishResult::failure( 'Unusable posting key: ' . $e->getMessage() );
        }

        try {
            $props      = $this->rpc->getDynamicGlobalProperties();
            $ref        = RpcClient::referenceBlock( $props );
            $expiration = $this->expiration( $props );
        } catch ( RpcException $e ) {
            return PublishResult::failure( 'Could not fetch global properties: ' . $this->rpcDetail( $e ), true );
        }

        $ops = [
            [
                'name'      => 'delete_comment',
                'serialize' => [
                    'author'   => $this->config->author,
                    'permlink' => $permlink,
                ],
            ],
        ];

        $signedTx = $this->buildSignedTransaction( $ref, $expiration, $ops, $priv );

        try {
            $this->rpc->broadcastTransaction( $signedTx['tx'] );
        } catch ( RpcException $e ) {
            return PublishResult::failure( 'Delete failed: ' . $this->rpcDetail( $e ), true );
        }

        return PublishResult::success( $permlink, $this->postUrl( $this->config->author, $permlink ), $signedTx['trx_id'] );
    }

    public function buildSigningRequest( PostPayload $post ): array {
        // Assisted mode (Keychain): the extension serializes, so we send the ops
        // with the broadcast field names.
        $permlink = $this->permlinkFor( $post );

        $operations = [
            [
                'comment',
                [
                    'parent_author'   => '',
                    'parent_permlink' => $this->parentPermlink( $post ),
                    'author'          => $this->config->author,
                    'permlink'        => $permlink,
                    'title'           => $post->title,
                    'body'            => $post->body,
                    'json_metadata'   => $this->jsonMetadata->build( $post ),
                ],
            ],
        ];

        $beneficiariesOp = $this->beneficiariesOp( $post, $permlink );
        if ( null !== $beneficiariesOp ) {
            $operations[] = [ 'comment_options', $beneficiariesOp['broadcast'] ];
        }

        return [
            'account'    => $this->config->author,
            'operations' => $operations,
            'permlink'   => $permlink,
        ];
    }

    /**
     * Builds the comment_options op with beneficiaries, ONLY for a new post (not
     * an edit) and when there are beneficiaries. The chain only allows setting
     * them when creating the post. Returns separate fields for serialization
     * (fixed positional % key) and for the broadcast (field name and asset symbol
     * depend on the chain).
     *
     * @return array{name:string,serialize:array<string,mixed>,broadcast:array<string,mixed>}|null
     */
    protected function beneficiariesOp( PostPayload $post, string $permlink ): ?array {
        if ( ! $this->isNewPost( $post ) || empty( $post->beneficiaries ) ) {
            return null;
        }

        $extensions = [ [ 0, [ 'beneficiaries' => array_values( $post->beneficiaries ) ] ] ];
        $common     = [
            'author'                 => $this->config->author,
            'permlink'               => $permlink,
            'max_accepted_payout'    => '1000000.000 ' . $this->backingSymbol(),
            'allow_votes'            => true,
            'allow_curation_rewards' => true,
            'extensions'             => $extensions,
        ];

        return [
            'name'      => 'comment_options',
            'serialize' => $common + [ 'percent_hbd' => self::FULL_PERCENT ],
            'broadcast' => $common + [ $this->percentField() => self::FULL_PERCENT ],
        ];
    }

    /** A post is new (creation) when it has no permlink assigned on the chain yet. */
    protected function isNewPost( PostPayload $post ): bool {
        return '' === (string) ( $post->extra['permlink'] ?? '' );
    }

    /** Chain dollar symbol for the broadcast JSON. Hive: HBD; Steem overrides it. */
    protected function backingSymbol(): string {
        return 'HBD';
    }

    /** comment_options % field in the broadcast. Hive: percent_hbd; Steem overrides it. */
    protected function percentField(): string {
        return 'percent_hbd';
    }

    public function confirmExternalBroadcast( string $ref ): PublishResult {
        // The browser already broadcast; record the permlink and build the URL.
        return PublishResult::success( $ref, $this->postUrl( $this->config->author, $ref ) );
    }

    // Internals

    /**
     * Builds and signs the transaction (one or more ops). Returns the array ready
     * to broadcast and the trx_id.
     *
     * Each op carries fields to serialize and, optionally, different fields for
     * the broadcast (some fields change name/symbol between Hive and Steem even
     * though they serialize the same, e.g. percent_hbd vs percent_steem_dollars).
     *
     * @param array{ref_block_num:int,ref_block_prefix:int}                                        $ref
     * @param array<int,array{name:string,serialize:array<string,mixed>,broadcast?:array<string,mixed>}> $ops
     *
     * @return array{tx:array<string,mixed>,trx_id:string}
     */
    protected function buildSignedTransaction( array $ref, string $expiration, array $ops, PrivateKey $priv ): array {
        $serializeOps = [];
        $broadcastOps = [];
        foreach ( $ops as $op ) {
            $serializeOps[] = [ $op['name'], $op['serialize'] ];
            $broadcastOps[] = [ $op['name'], $op['broadcast'] ?? $op['serialize'] ];
        }

        $serializer = new Serializer();
        $serializer->transaction(
            $ref['ref_block_num'],
            $ref['ref_block_prefix'],
            $expiration,
            $serializeOps
        );

        $serializedHex = $serializer->hex();
        $digest        = hash( 'sha256', hex2bin( $this->chainId() . $serializedHex ) );
        $signature     = $priv->signDigest( $digest, $this->signer );
        $trxId         = substr( hash( 'sha256', hex2bin( $serializedHex ) ), 0, 40 );

        return [
            'tx'     => [
                'ref_block_num'    => $ref['ref_block_num'],
                'ref_block_prefix' => $ref['ref_block_prefix'],
                'expiration'       => $expiration,
                'operations'       => $broadcastOps,
                'extensions'       => [],
                'signatures'       => [ $signature ],
            ],
            'trx_id' => $trxId,
        ];
    }

    protected function permlinkFor( PostPayload $post ): string {
        $override = $post->extra['permlink'] ?? '';
        if ( is_string( $override ) && '' !== $override ) {
            return $override;
        }
        return $this->permlinks->generate( $post->title, $post->wpPostId );
    }

    protected function parentPermlink( PostPayload $post ): string {
        $first = $post->tags[0] ?? '';
        $tag   = $this->permlinks->slugify( (string) $first );
        return '' !== $tag ? $tag : $this->config->defaultTag;
    }

    protected function expiration( array $props ): string {
        $base = isset( $props['time'] ) ? strtotime( (string) $props['time'] . ' UTC' ) : false;
        if ( false === $base ) {
            $base = time();
        }
        return gmdate( 'Y-m-d\TH:i:s', $base + 60 );
    }

    private function rpcDetail( RpcException $e ): string {
        $parts = [];
        foreach ( $e->nodeErrors() as $node => $err ) {
            $parts[] = $node . ' → ' . $err;
        }
        return empty( $parts )
            ? $e->getMessage()
            : $e->getMessage() . ' [' . implode( ' | ', $parts ) . ']';
    }

    protected function decryptKey(): PrivateKey {
        $wif = $this->vault->decrypt( (string) $this->config->encryptedPostingKey );
        return PrivateKey::fromWif( $wif );
    }

    /**
     * @param array<string,mixed> $account
     * @return string[]
     */
    protected function extractPostingKeys( array $account ): array {
        $keyAuths = $account['posting']['key_auths'] ?? [];
        $keys     = [];
        foreach ( $keyAuths as $auth ) {
            if ( isset( $auth[0] ) ) {
                $keys[] = (string) $auth[0];
            }
        }
        return $keys;
    }
}
