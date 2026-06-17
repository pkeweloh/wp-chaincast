<?php
/**
 * Página de ajustes del plugin.
 *
 * Configura cada conector (Hive, Steem): activación, publicación automática,
 * cuenta autor, posting key (guardada SIEMPRE cifrada con el Vault), tag por
 * defecto; más ajustes generales (pie de atribución). Incluye un botón para
 * validar credenciales contra la cadena sin publicar.
 *
 * @package Chaincast\Admin
 */

declare(strict_types=1);

namespace Chaincast\Admin;

use Throwable;
use Chaincast\Connector\Graphene\PrivateKey;
use Chaincast\Core\ConnectorBootstrap;
use Chaincast\Core\ConnectorRegistry;
use Chaincast\Core\Crypto\Vault;
use Chaincast\Core\Settings;

final class SettingsPage {

    private const MENU_SLUG    = 'chaincast';
    private const VALIDATE_ACT = 'chaincast_validate_creds';

    /** Conectores configurables: id => etiqueta. */
    private const CONNECTORS = [
        'hive'  => 'Hive',
        'steem' => 'Steem',
    ];

    private string $hookSuffix = '';

    public function __construct(
        private ConnectorRegistry $connectors,
        private Settings $settings,
    ) {
    }

    public function register(): void {
        add_action( 'admin_menu', [ $this, 'addMenu' ] );
        add_action( 'admin_init', [ $this, 'registerSettings' ] );
        add_action( 'admin_post_' . self::VALIDATE_ACT, [ $this, 'handleValidate' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
    }

    public function addMenu(): void {
        $this->hookSuffix = (string) add_options_page(
            __( 'Chaincast', 'chaincast' ),
            __( 'Chaincast', 'chaincast' ),
            'manage_options',
            self::MENU_SLUG,
            [ $this, 'render' ]
        );
    }

    public function enqueue( string $hook ): void {
        if ( $hook !== $this->hookSuffix ) {
            return;
        }

        Assets::enqueueStyle();
        Assets::enqueueBeneficiaries();
        Assets::enqueuePostingKey();
    }

    /**
     * Campo de reparto de recompensas: input de texto (canónico, lo que se guarda)
     * que el JS realza a tabla. Sin JS, el input sigue siendo usable.
     */
    private function renderBeneficiariesField( string $name, string $value ): void {
        printf(
            '<div class="cc-benef"><input type="text" class="cc-benef-raw cc-input" name="%s" value="%s" placeholder="%s" /></div>',
            esc_attr( $name ),
            esc_attr( $value ),
            esc_attr__( 'account:percentage, account:percentage', 'chaincast' )
        );
    }

    /**
     * Icono de ayuda con tooltip estilizado por CSS (caja), no el title nativo.
     * El texto llega ya escapado para atributo (esc_attr__), y puede contener «%».
     */
    private function renderHelp( string $textEscaped ): void {
        echo '<span class="cc-help" tabindex="0" role="img" aria-label="' . $textEscaped . '" data-tip="' . $textEscaped . '">?</span>';
    }

    public function registerSettings(): void {
        register_setting(
            self::MENU_SLUG,
            Settings::OPTION,
            [
                'type'              => 'array',
                'sanitize_callback' => [ $this, 'sanitize' ],
                'default'           => [],
            ]
        );
    }

    /**
     * Valida la posting key guardada de cada conector activo contra la cuenta
     * on-chain. No emite nada: deriva la pubkey y consulta la cuenta.
     */
    public function handleValidate(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'chaincast' ) );
        }
        check_admin_referer( self::VALIDATE_ACT );

        $registry = new ConnectorRegistry();
        ( new ConnectorBootstrap( $this->settings ) )->register( $registry );

        $lines = [];
        foreach ( $registry->all() as $connector ) {
            $result  = $connector->validateCredentials();
            $lines[] = [
                'ok'  => $result->ok,
                'msg' => $result->ok
                    ? sprintf( __( '%1$s: credentials valid (%2$s)', 'chaincast' ), $connector->label(), (string) ( $result->data['public_key'] ?? '' ) )
                    : sprintf( '%1$s: %2$s', $connector->label(), (string) $result->error ),
            ];
        }

        if ( empty( $lines ) ) {
            $lines[] = [ 'ok' => false, 'msg' => __( 'Enable a connector and save the settings before validating.', 'chaincast' ) ];
        }

        set_transient( 'chaincast_validate_' . get_current_user_id(), $lines, 60 );
        wp_safe_redirect( add_query_arg( 'page', self::MENU_SLUG, admin_url( 'options-general.php' ) ) );
        exit;
    }

    /**
     * Sanea y cifra los ajustes de todos los conectores + la sección general.
     *
     * @param mixed $input
     * @return array<string,array<string,mixed>>
     */
    public function sanitize( $input ): array {
        $input    = is_array( $input ) ? $input : [];
        $existing = $this->settings->all();
        $result   = $existing;

        foreach ( array_keys( self::CONNECTORS ) as $id ) {
            $in  = is_array( $input[ $id ] ?? null ) ? $input[ $id ] : [];
            $cfg = [
                'enabled'         => ! empty( $in['enabled'] ) ? '1' : '',
                'auto_publish'    => ! empty( $in['auto_publish'] ) ? '1' : '',
                'author'          => sanitize_text_field( (string) ( $in['author'] ?? '' ) ),
                'default_tag'     => sanitize_title( (string) ( $in['default_tag'] ?? 'blog' ) ),
                'category_map'    => $this->sanitizeCategoryMap( $in['category_map'] ?? [] ),
                'beneficiaries'   => sanitize_text_field( (string) ( $in['beneficiaries'] ?? '' ) ),
                'posting_key_enc' => (string) ( $existing[ $id ]['posting_key_enc'] ?? '' ),
            ];

            $newKey = trim( (string) ( $in['posting_key'] ?? '' ) );
            if ( '' !== $newKey ) {
                $cfg['posting_key_enc'] = $this->encryptPostingKey( $id, $newKey, $cfg['posting_key_enc'] );
            }

            $result[ $id ] = $cfg;
        }

        $generalIn         = is_array( $input['general'] ?? null ) ? $input['general'] : [];
        $result['general'] = [
            'footer_enabled' => ! empty( $generalIn['footer_enabled'] ) ? '1' : '',
            'footer_text'    => sanitize_text_field( (string) ( $generalIn['footer_text'] ?? '' ) ),
        ];

        return $result;
    }

    /**
     * Sanea el mapa de categorías de un conector (slug WP => destino). Recibe el
     * array de inputs por categoría; descarta destinos vacíos.
     *
     * @param mixed $input
     * @return array<string,string>
     */
    private function sanitizeCategoryMap( $input ): array {
        if ( ! is_array( $input ) ) {
            return [];
        }
        $map = [];
        foreach ( $input as $slug => $dest ) {
            $slug = sanitize_title( (string) $slug );
            $dest = sanitize_title( (string) $dest );
            if ( '' !== $slug && '' !== $dest ) {
                $map[ $slug ] = $dest;
            }
        }
        return $map;
    }

    private function encryptPostingKey( string $connectorId, string $plainWif, string $fallback ): string {
        $vault = Vault::fromWpConfig();
        if ( null === $vault ) {
            add_settings_error( Settings::OPTION, 'no_vault', __( 'The posting key was not saved: the CHAINCAST_KEY constant is missing in wp-config.php.', 'chaincast' ) );
            return $fallback;
        }

        try {
            PrivateKey::fromWif( $plainWif ); // valida el formato antes de cifrar.
        } catch ( Throwable $e ) {
            add_settings_error(
                Settings::OPTION,
                'bad_wif_' . $connectorId,
                sprintf( __( '%s: the posting key is not a valid WIF; it was not saved.', 'chaincast' ), $connectorId )
            );
            return $fallback;
        }

        return $vault->encrypt( $plainWif );
    }

    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $general    = $this->settings->general();
        $vaultReady = Vault::isConfigured();
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__( 'Chaincast', 'chaincast' ); ?></h1>

            <?php $this->renderValidationNotice(); ?>

            <?php if ( ! $vaultReady ) : ?>
                <div class="notice notice-warning inline">
                    <p><?php echo esc_html__( 'Automatic mode disabled: define the CHAINCAST_KEY constant in wp-config.php to store the encrypted posting key.', 'chaincast' ); ?></p>
                </div>
            <?php endif; ?>

            <form action="options.php" method="post">
                <?php settings_fields( self::MENU_SLUG ); ?>

                <nav class="nav-tab-wrapper cc-nav">
                    <a href="#" class="nav-tab nav-tab-active" data-tab="general"><?php echo esc_html__( 'General', 'chaincast' ); ?></a>
                    <?php foreach ( self::CONNECTORS as $id => $label ) : ?>
                        <a href="#" class="nav-tab" data-tab="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></a>
                    <?php endforeach; ?>
                </nav>

                <div class="cc-tab-panel active" data-panel="general">
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><?php echo esc_html__( 'Attribution footer', 'chaincast' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[general][footer_enabled]" value="1" <?php checked( ! empty( $general['footer_enabled'] ) ); ?> />
                                    <?php echo esc_html__( 'Append a link to the original post', 'chaincast' ); ?>
                                </label>
                                <p style="margin-top:8px">
                                    <input type="text" name="<?php echo esc_attr( Settings::OPTION ); ?>[general][footer_text]" value="<?php echo esc_attr( (string) ( $general['footer_text'] ?? '' ) ); ?>" class="cc-input" placeholder="<?php echo esc_attr( Settings::defaultFooter() ); ?>" />
                                </p>
                                <p class="description">
                                    <?php
                                    printf(
                                        /* translators: %1$s and %2$s are the literal placeholders {site} and {url}. */
                                        esc_html__( 'Markdown. %1$s = site name, %2$s = link. Empty = default text.', 'chaincast' ),
                                        '<code>{site}</code>',
                                        '<code>{url}</code>'
                                    );
                                    ?>
                                </p>
                            </td>
                        </tr>
                    </table>

                    <?php
                    $validateUrl = wp_nonce_url(
                        add_query_arg( 'action', self::VALIDATE_ACT, admin_url( 'admin-post.php' ) ),
                        self::VALIDATE_ACT
                    );
                    ?>
                    <div class="cc-general-extra">
                        <h2><?php echo esc_html__( 'Connector status', 'chaincast' ); ?></h2>
                        <?php $this->renderConnectorsTable(); ?>
                        <p class="cc-validate-row">
                            <a href="<?php echo esc_url( $validateUrl ); ?>" class="button button-secondary"><?php echo esc_html__( 'Validate credentials', 'chaincast' ); ?></a>
                        </p>
                        <p class="description"><?php echo esc_html__( 'Checks the stored posting keys against the accounts (without publishing anything).', 'chaincast' ); ?></p>
                    </div>
                </div>

                <?php foreach ( self::CONNECTORS as $id => $label ) : ?>
                    <div class="cc-tab-panel" data-panel="<?php echo esc_attr( $id ); ?>">
                        <?php $this->renderConnectorFields( $id, $label, $vaultReady ); ?>
                    </div>
                <?php endforeach; ?>

                <?php submit_button(); ?>
            </form>

            <script>
                ( function () {
                    var nav = document.querySelector( '.cc-nav' );
                    if ( ! nav ) { return; }
                    var tabs   = nav.querySelectorAll( '.nav-tab' );
                    var panels = document.querySelectorAll( '.cc-tab-panel' );
                    tabs.forEach( function ( tab ) {
                        tab.addEventListener( 'click', function ( e ) {
                            e.preventDefault();
                            tabs.forEach( function ( t ) { t.classList.remove( 'nav-tab-active' ); } );
                            panels.forEach( function ( p ) { p.classList.remove( 'active' ); } );
                            tab.classList.add( 'nav-tab-active' );
                            var name = tab.getAttribute( 'data-tab' );
                            var panel = document.querySelector( '.cc-tab-panel[data-panel="' + name + '"]' );
                            if ( panel ) { panel.classList.add( 'active' ); }
                        } );
                    } );
                } )();
            </script>
        </div>
        <?php
    }

    private function renderConnectorFields( string $id, string $label, bool $vaultReady ): void {
        $cfg    = $this->settings->forConnector( $id );
        $opt    = Settings::OPTION;
        $hasKey = ! empty( $cfg['posting_key_enc'] );
        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php echo esc_html__( 'Enabled', 'chaincast' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="<?php echo esc_attr( "{$opt}[{$id}][enabled]" ); ?>" value="1" <?php checked( ! empty( $cfg['enabled'] ) ); ?> />
                        <?php printf( esc_html__( 'Publish to %s', 'chaincast' ), esc_html( $label ) ); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php echo esc_html__( 'Automatic publishing', 'chaincast' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="<?php echo esc_attr( "{$opt}[{$id}][auto_publish]" ); ?>" value="1" <?php checked( ! empty( $cfg['auto_publish'] ) ); ?> />
                        <?php printf( esc_html__( 'Publish to %s when the post is published in WordPress', 'chaincast' ), esc_html( $label ) ); ?>
                    </label>
                    <p class="description"><?php echo esc_html__( 'If left unchecked, you decide when to publish using the button in the editor.', 'chaincast' ); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php echo esc_html__( 'Account (author)', 'chaincast' ); ?></th>
                <td><input type="text" name="<?php echo esc_attr( "{$opt}[{$id}][author]" ); ?>" value="<?php echo esc_attr( (string) ( $cfg['author'] ?? '' ) ); ?>" class="regular-text" /></td>
            </tr>
            <tr>
                <th scope="row">
                    <?php echo esc_html__( 'Posting key', 'chaincast' ); ?>
                    <?php $this->renderHelp( esc_attr__( 'Limited-authority key (posting/voting only). Stored encrypted with AES-256-GCM. Do not use the Active or Owner key.', 'chaincast' ) ); ?>
                </th>
                <td>
                    <div class="cc-key"<?php echo $hasKey ? ' data-haskey="1"' : ''; ?>>
                        <?php if ( $hasKey ) : ?>
                            <span class="cc-saved"><span class="dashicons dashicons-lock" aria-hidden="true"></span><?php echo esc_html__( 'Key saved & encrypted', 'chaincast' ); ?></span>
                            <button type="button" class="button-link cc-key-edit"><?php echo esc_html__( 'Replace key', 'chaincast' ); ?></button>
                        <?php endif; ?>
                        <input type="password" name="<?php echo esc_attr( "{$opt}[{$id}][posting_key]" ); ?>" value="" autocomplete="new-password" class="regular-text cc-key-input" placeholder="<?php echo esc_attr__( 'Paste your private posting key', 'chaincast' ); ?>" <?php disabled( ! $vaultReady ); ?> />
                        <?php if ( $hasKey ) : ?>
                            <button type="button" class="button-link cc-key-cancel"><?php echo esc_html__( 'Cancel', 'chaincast' ); ?></button>
                            <p class="description cc-key-hint"><?php echo esc_html__( 'Leave empty to keep the current key.', 'chaincast' ); ?></p>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <?php echo esc_html__( 'Category mapping', 'chaincast' ); ?>
                    <?php $this->renderHelp( esc_attr__( 'Translate each WordPress category to its community (parent_permlink) on this chain. The post\'s primary category determines where it lands.', 'chaincast' ) ); ?>
                </th>
                <td><?php $this->renderCategoryMapRows( $id, is_array( $cfg['category_map'] ?? null ) ? $cfg['category_map'] : [] ); ?></td>
            </tr>
            <tr>
                <th scope="row">
                    <?php echo esc_html__( 'Default category', 'chaincast' ); ?>
                    <?php $this->renderHelp( esc_attr__( 'Fallback community (parent_permlink) used when the post has no WordPress category. The first tag of the post on the chain.', 'chaincast' ) ); ?>
                </th>
                <td><input type="text" name="<?php echo esc_attr( "{$opt}[{$id}][default_tag]" ); ?>" value="<?php echo esc_attr( (string) ( $cfg['default_tag'] ?? 'blog' ) ); ?>" class="regular-text" /></td>
            </tr>
            <tr>
                <th scope="row">
                    <?php echo esc_html__( 'Reward sharing', 'chaincast' ); ?>
                    <?php $this->renderHelp( esc_attr__( 'On Hive/Steem these are called "beneficiaries": accounts on THIS chain that receive a % of the post\'s author rewards. Use the right usernames for each chain.', 'chaincast' ) ); ?>
                </th>
                <td>
                    <?php $this->renderBeneficiariesField( "{$opt}[{$id}][beneficiaries]", $this->settings->beneficiaries( $id ) ); ?>
                    <p class="description"><?php echo esc_html__( 'No beneficiaries = you keep 100%. Applied only when first publishing to this chain.', 'chaincast' ); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Filas de mapeo: una por categoría real de WordPress, con su destino en esta
     * cadena. Vacío = se usa el slug de la categoría tal cual.
     *
     * @param array<string,string> $map
     */
    private function renderCategoryMapRows( string $id, array $map ): void {
        $opt        = Settings::OPTION;
        $categories = get_categories( [ 'hide_empty' => false ] );

        if ( empty( $categories ) ) {
            printf( '<p class="description">%s</p>', esc_html__( 'There are no categories in WordPress yet.', 'chaincast' ) );
            return;
        }
        ?>
        <table class="widefat striped" style="max-width:520px">
            <thead>
                <tr>
                    <th><?php echo esc_html__( 'WordPress category', 'chaincast' ); ?></th>
                    <th><?php echo esc_html__( 'Tag / community on the chain', 'chaincast' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $categories as $cat ) : ?>
                    <tr>
                        <td>
                            <?php echo esc_html( $cat->name ); ?>
                            <code style="color:#888;font-size:11px"><?php echo esc_html( $cat->slug ); ?></code>
                        </td>
                        <td>
                            <input
                                type="text"
                                name="<?php echo esc_attr( "{$opt}[{$id}][category_map][{$cat->slug}]" ); ?>"
                                value="<?php echo esc_attr( (string) ( $map[ $cat->slug ] ?? '' ) ); ?>"
                                class="regular-text"
                                placeholder="<?php echo esc_attr( $cat->slug ); ?>"
                            />
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="description"><?php echo esc_html__( 'Empty = the slug is used as-is.', 'chaincast' ); ?></p>
        <?php
    }

    private function renderValidationNotice(): void {
        $key   = 'chaincast_validate_' . get_current_user_id();
        $lines = get_transient( $key );
        if ( ! is_array( $lines ) ) {
            return;
        }
        delete_transient( $key );

        foreach ( $lines as $line ) {
            $class = ! empty( $line['ok'] ) ? 'notice-success' : 'notice-error';
            printf(
                '<div class="notice %s is-dismissible"><p>%s</p></div>',
                esc_attr( $class ),
                esc_html( (string) ( $line['msg'] ?? '' ) )
            );
        }
    }

    private function renderConnectorsTable(): void {
        $all = $this->connectors->all();

        if ( empty( $all ) ) {
            printf( '<p><em>%s</em></p>', esc_html__( 'No active connector (enable one and save the settings).', 'chaincast' ) );
            return;
        }
        ?>
        <table class="widefat striped" style="max-width:640px">
            <thead>
                <tr>
                    <th><?php echo esc_html__( 'Chain', 'chaincast' ); ?></th>
                    <th><?php echo esc_html__( 'Configured', 'chaincast' ); ?></th>
                    <th><?php echo esc_html__( 'Automatic mode', 'chaincast' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $all as $connector ) : ?>
                    <tr>
                        <td><?php echo esc_html( $connector->label() ); ?></td>
                        <td><?php echo $connector->isConfigured() ? '✓' : '—'; ?></td>
                        <td><?php echo $connector->supportsAutomatic() ? '✓' : '—'; ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }
}
