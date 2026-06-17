<?php
/**
 * Contrato común que todo conector de cadena debe implementar.
 *
 * El núcleo del plugin no conoce ninguna blockchain concreta: habla únicamente
 * con esta interfaz. Añadir una cadena nueva (Hive, Steem, Lens, LikeCoin...) =
 * implementar esta interfaz, sin tocar el núcleo.
 *
 * @package Chaincast\Connector
 */

declare(strict_types=1);

namespace Chaincast\Connector;

interface ConnectorInterface {

    /**
     * Identificador estable y único de la cadena: 'hive', 'steem', ...
     * Se usa como clave en post meta, ajustes y la cola.
     */
    public function id(): string;

    /**
     * Nombre legible para la UI ("Hive", "Steem").
     */
    public function label(): string;

    /**
     * ¿Tiene la configuración mínima para operar (autor, nodos, etc.)?
     */
    public function isConfigured(): bool;

    /**
     * ¿Puede publicar de forma automática (hay clave de firma disponible y
     * descifrable en el servidor)? Si es false, solo cabe el modo asistido.
     */
    public function supportsAutomatic(): bool;

    /**
     * Modo asistido: nombre del objeto global que expone la extensión de firma
     * del navegador para esta cadena ('hive_keychain', 'steem_keychain'), o null
     * si la cadena no admite firma asistida por extensión.
     */
    public function keychainExtension(): ?string;

    /**
     * Valida las credenciales/configuración actuales contra la red si procede.
     */
    public function validateCredentials(): Result;

    /**
     * Modo automático: serializa, firma y emite la transacción.
     */
    public function publish(PostPayload $post): PublishResult;

    /**
     * Modo asistido: prepara la(s) operación(es) para que el navegador las firme
     * (p. ej. Hive Keychain). Devuelve la estructura que consumirá el JS:
     * { account, operations: [[nombre, campos], ...], permlink }.
     *
     * @return array{account:string,operations:array<int,array{0:string,1:array<string,mixed>}>,permlink:string}
     */
    public function buildSigningRequest(PostPayload $post): array;

    /**
     * Modo asistido: registra el resultado de un broadcast hecho fuera del
     * servidor (el navegador notifica el permlink/tx tras firmar).
     */
    public function confirmExternalBroadcast(string $ref): PublishResult;
}
