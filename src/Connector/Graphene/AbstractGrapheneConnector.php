<?php
/**
 * Lógica común de los conectores graphene (Hive y Steem).
 *
 * Implementa el flujo de publicación automática: obtiene el bloque de referencia
 * de un nodo, construye la comment op a partir del PostPayload, serializa, firma
 * y emite con failover. Las subclases solo aportan lo específico de cada cadena:
 * chain_id, prefijo de direcciones, nodos por defecto y dominio de URL.
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

    /** 100% en puntos base (percent_hbd/percent_steem_dollars de comment_options). */
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

    // ---- Específico de cada cadena ----

    abstract protected function chainId(): string;

    /** Prefijo de direcciones (ambas cadenas usan 'STM' para claves públicas). */
    abstract protected function addressPrefix(): string;

    abstract protected function postUrl( string $author, string $permlink ): string;

    // ---- ConnectorInterface ----

    public function isConfigured(): bool {
        return '' !== $this->config->author;
    }

    public function supportsAutomatic(): bool {
        return $this->isConfigured()
            && null !== $this->vault
            && $this->config->hasPostingKey();
    }

    /**
     * Hive → 'hive_keychain', Steem → 'steem_keychain' (convención de ambas
     * extensiones, que son forks la una de la otra).
     */
    public function keychainExtension(): ?string {
        return $this->id() . '_keychain';
    }

    public function validateCredentials(): Result {
        if ( ! $this->isConfigured() ) {
            return Result::fail( 'Falta la cuenta de autor.' );
        }
        if ( ! $this->supportsAutomatic() ) {
            return Result::fail( 'Modo automático no disponible (sin Vault o sin posting key).' );
        }

        try {
            $priv   = $this->decryptKey();
            $derived = $priv->publicKey( $this->addressPrefix() )->toString();
        } catch ( Throwable $e ) {
            return Result::fail( 'No se pudo descifrar/derivar la clave: ' . $e->getMessage() );
        }

        // Comprueba que la clave pública derivada figure en la posting authority de la cuenta.
        try {
            $accounts = $this->rpc->call( 'condenser_api.get_accounts', [ [ $this->config->author ] ] );
        } catch ( RpcException $e ) {
            return Result::fail( 'No se pudo consultar la cuenta: ' . $this->rpcDetail( $e ) );
        }

        $postingKeys = $this->extractPostingKeys( is_array( $accounts ) ? ( $accounts[0] ?? [] ) : [] );
        if ( ! in_array( $derived, $postingKeys, true ) ) {
            return Result::fail( 'La posting key no coincide con la autoridad de la cuenta.' );
        }

        return Result::ok( [ 'public_key' => $derived ] );
    }

    public function publish( PostPayload $post ): PublishResult {
        if ( ! $this->supportsAutomatic() ) {
            return PublishResult::failure( 'Modo automático no disponible para ' . $this->id() . '.' );
        }

        try {
            $priv = $this->decryptKey();
        } catch ( Throwable $e ) {
            return PublishResult::failure( 'Posting key inutilizable: ' . $e->getMessage() );
        }

        $permlink = $this->permlinkFor( $post );

        try {
            $props      = $this->rpc->getDynamicGlobalProperties();
            $ref        = RpcClient::referenceBlock( $props );
            $expiration = $this->expiration( $props );
        } catch ( RpcException $e ) {
            // Fallo de nodo: es reintentable.
            return PublishResult::failure( 'No se pudieron obtener propiedades globales: ' . $this->rpcDetail( $e ), true );
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

        // Beneficiaries (reparto de recompensas) solo al CREAR el post.
        $beneficiariesOp = $this->beneficiariesOp( $post, $permlink );
        if ( null !== $beneficiariesOp ) {
            $ops[] = $beneficiariesOp;
        }

        $signedTx = $this->buildSignedTransaction( $ref, $expiration, $ops, $priv );

        try {
            $this->rpc->broadcastTransaction( $signedTx['tx'] );
        } catch ( RpcException $e ) {
            // Distinguir error de red (reintentable) de rechazo de la cadena no es
            // trivial aquí; tratamos como reintentable salvo evidencia en contra.
            return PublishResult::failure( 'Broadcast falló: ' . $this->rpcDetail( $e ), true );
        }

        return PublishResult::success(
            $permlink,
            $this->postUrl( $this->config->author, $permlink ),
            $signedTx['trx_id']
        );
    }

    /**
     * Borra un post/comentario propio de la cadena (op delete_comment).
     * Solo funciona si el post no tiene votos ni respuestas.
     */
    public function deletePost( string $permlink ): PublishResult {
        if ( ! $this->supportsAutomatic() ) {
            return PublishResult::failure( 'Modo automático no disponible para ' . $this->id() . '.' );
        }

        try {
            $priv = $this->decryptKey();
        } catch ( Throwable $e ) {
            return PublishResult::failure( 'Posting key inutilizable: ' . $e->getMessage() );
        }

        try {
            $props      = $this->rpc->getDynamicGlobalProperties();
            $ref        = RpcClient::referenceBlock( $props );
            $expiration = $this->expiration( $props );
        } catch ( RpcException $e ) {
            return PublishResult::failure( 'No se pudieron obtener propiedades globales: ' . $this->rpcDetail( $e ), true );
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
            return PublishResult::failure( 'Borrado falló: ' . $this->rpcDetail( $e ), true );
        }

        return PublishResult::success( $permlink, $this->postUrl( $this->config->author, $permlink ), $signedTx['trx_id'] );
    }

    public function buildSigningRequest( PostPayload $post ): array {
        // Modo asistido (Keychain): la extensión serializa, así que enviamos las
        // ops con los nombres de campo del broadcast.
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
     * Construye la op comment_options con beneficiaries, SOLO si es un post nuevo
     * (no edición) y hay beneficiaries. La cadena solo admite fijarlos al crear el
     * post. Devuelve campos separados para serializar (clave de % fija, posicional)
     * y para el broadcast (nombre de campo y símbolo del asset según la cadena).
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

    /** Un post es nuevo (creación) si aún no tiene permlink asignado en la cadena. */
    protected function isNewPost( PostPayload $post ): bool {
        return '' === (string) ( $post->extra['permlink'] ?? '' );
    }

    /** Símbolo del dólar de la cadena para el JSON del broadcast. Hive: HBD; Steem lo sobreescribe. */
    protected function backingSymbol(): string {
        return 'HBD';
    }

    /** Campo de % de comment_options en el broadcast. Hive: percent_hbd; Steem lo sobreescribe. */
    protected function percentField(): string {
        return 'percent_hbd';
    }

    public function confirmExternalBroadcast( string $ref ): PublishResult {
        // El navegador ya emitió; registramos el permlink y construimos la URL.
        return PublishResult::success( $ref, $this->postUrl( $this->config->author, $ref ) );
    }

    // ---- Internos ----

    /**
     * Construye y firma la transacción (una o varias ops). Devuelve el array listo
     * para broadcast y el trx_id.
     *
     * Cada op trae campos para serializar y, opcionalmente, campos distintos para
     * el broadcast (algunos campos cambian de nombre/símbolo entre Hive y Steem
     * aunque serialicen igual, p. ej. percent_hbd vs percent_steem_dollars).
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
