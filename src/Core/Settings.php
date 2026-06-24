<?php
/**
 * Plugin settings repository (a single WordPress option).
 *
 * Structure: option => [ connectorId => [ enabled, author, posting_key_enc,
 * default_tag ] ]. The posting key is ALWAYS stored encrypted (Vault payload).
 *
 * @package Chaincast\Core
 */

declare(strict_types=1);

namespace Chaincast\Core;

final class Settings {

    public const OPTION = 'chaincast_settings';

    /** Default footer template (untranslated fallback). Placeholders: {site} and {url}. */
    public const DEFAULT_FOOTER = '*Originally published at [{site}]({url}).*';

    /**
     * Default footer template, translatable to the site language.
     * Placeholders: {site} and {url}.
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
     * General settings section (not tied to a connector).
     *
     * @return array<string,mixed>
     */
    public function general(): array {
        $all = $this->all();
        return isset( $all['general'] ) && is_array( $all['general'] ) ? $all['general'] : [];
    }

    /**
     * Add the "Originally published at ..." footer? Off by default: just the
     * post content.
     */
    public function footerEnabled(): bool {
        return ! empty( $this->general()['footer_enabled'] );
    }

    /**
     * Footer template (with {site} and {url} placeholders). If empty, uses the
     * default.
     */
    public function footerText(): string {
        $text = trim( (string) ( $this->general()['footer_text'] ?? '' ) );
        return '' !== $text ? $text : self::defaultFooter();
    }

    /**
     * Beneficiaries (author reward split) for THIS chain, in the text format
     * "account:percent, account2:percent". Per connector: Hive and Steem accounts
     * are usually different, and sending a nonexistent beneficiary to a chain
     * would fail the publish. Empty: 100% to the author. Applied only when
     * CREATING the post on the chain (the chain does not allow changing them later).
     *
     * Backward compatibility: if this chain has no own value, it falls back to the
     * old single global value (installs predating the per-chain split).
     */
    public function beneficiaries( string $id ): string {
        $perChain = trim( (string) ( $this->forConnector( $id )['beneficiaries'] ?? '' ) );
        if ( '' !== $perChain ) {
            return $perChain;
        }
        return trim( (string) ( $this->general()['beneficiaries'] ?? '' ) );
    }

    /**
     * Map of WordPress categories/tags to THIS chain's tags or communities
     * (slug => target). Per connector: Hive and Steem may point to different
     * communities for the same category. Empty by default: the WordPress slugs
     * are used as-is. Only affects the automatic tag path (no per-post override).
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
     * Auto-publish to this chain when publishing in WordPress? Off by default:
     * the user decides when via the editor's manual button.
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
