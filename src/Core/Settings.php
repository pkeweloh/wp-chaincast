<?php
/**
 * Repositorio de ajustes del plugin (una option de WordPress).
 *
 * Estructura: option => [ connectorId => [ enabled, author, posting_key_enc,
 * default_tag ] ]. La posting key se guarda SIEMPRE cifrada (payload del Vault).
 *
 * @package Chaincast\Core
 */

declare(strict_types=1);

namespace Chaincast\Core;

final class Settings {

    public const OPTION = 'chaincast_settings';

    /** Plantilla por defecto del pie (fallback no traducido). Marcadores: {site} y {url}. */
    public const DEFAULT_FOOTER = '*Originally published at [{site}]({url}).*';

    /**
     * Plantilla por defecto del pie, traducible al idioma del sitio.
     * Marcadores: {site} y {url}.
     */
    public static function defaultFooter(): string {
        return sprintf(
            /* translators: 1: the literal {site} placeholder, 2: the literal {url} placeholder; keep the Markdown link format. */
            __( '*Originally published at [%1$s](%2$s).*', 'chaincast' ),
            '{site}',
            '{url}'
        );
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function all(): array {
        $value = get_option( self::OPTION, [] );
        return is_array( $value ) ? $value : [];
    }

    /**
     * @return array<string,mixed>
     */
    public function forConnector( string $id ): array {
        $all = $this->all();
        return isset( $all[ $id ] ) && is_array( $all[ $id ] ) ? $all[ $id ] : [];
    }

    public function get( string $id, string $key, mixed $default = '' ): mixed {
        return $this->forConnector( $id )[ $key ] ?? $default;
    }

    public function isEnabled( string $id ): bool {
        return ! empty( $this->forConnector( $id )['enabled'] );
    }

    /**
     * Sección de ajustes generales (no atados a un conector).
     *
     * @return array<string,mixed>
     */
    public function general(): array {
        $all = $this->all();
        return isset( $all['general'] ) && is_array( $all['general'] ) ? $all['general'] : [];
    }

    /**
     * ¿Añadir el pie "Publicado originalmente en ..."? Por defecto NO: solo el
     * contenido de la entrada.
     */
    public function footerEnabled(): bool {
        return ! empty( $this->general()['footer_enabled'] );
    }

    /**
     * Plantilla del pie (con marcadores {site} y {url}). Si está vacía, usa la de
     * por defecto.
     */
    public function footerText(): string {
        $text = trim( (string) ( $this->general()['footer_text'] ?? '' ) );
        return '' !== $text ? $text : self::defaultFooter();
    }

    /**
     * Beneficiaries (reparto de recompensas de autor) de ESTA cadena, formato de
     * texto "cuenta:porcentaje, cuenta2:porcentaje". Es por conector: las cuentas
     * de Hive y Steem suelen ser distintas, y mandar un beneficiario inexistente a
     * una cadena haría fallar la publicación. Vacío → 100% para el autor. Solo se
     * aplica al CREAR el post en la cadena (la cadena no admite cambiarlos luego).
     *
     * Compatibilidad: si esta cadena no tiene valor propio, cae al antiguo valor
     * global único (instalaciones previas a la separación per-cadena).
     */
    public function beneficiaries( string $id ): string {
        $perChain = trim( (string) ( $this->forConnector( $id )['beneficiaries'] ?? '' ) );
        if ( '' !== $perChain ) {
            return $perChain;
        }
        return trim( (string) ( $this->general()['beneficiaries'] ?? '' ) );
    }

    /**
     * Mapa de categorías/etiquetas de WordPress a tags o comunidades de ESTA
     * cadena (slug => destino). Es por conector: Hive y Steem pueden apuntar a
     * comunidades distintas para la misma categoría. Vacío por defecto → se usan
     * los slugs de WordPress tal cual. Solo afecta al camino automático de tags
     * (sin override por entrada).
     *
     * @return array<string,string>
     */
    public function categoryMapFor( string $id ): array {
        $map = $this->forConnector( $id )['category_map'] ?? [];
        if ( ! is_array( $map ) ) {
            return [];
        }
        $clean = [];
        foreach ( $map as $key => $value ) {
            $key   = (string) $key;
            $value = (string) $value;
            if ( '' !== $key && '' !== $value ) {
                $clean[ $key ] = $value;
            }
        }
        return $clean;
    }

    /**
     * ¿Auto-publicar en esta cadena al publicar en WordPress? Por defecto NO:
     * el usuario decide cuándo con el botón manual del editor.
     */
    public function autoPublish( string $id ): bool {
        return ! empty( $this->forConnector( $id )['auto_publish'] );
    }

    /**
     * @param array<string,array<string,mixed>> $settings
     */
    public function save( array $settings ): void {
        update_option( self::OPTION, $settings );
    }
}
