<?php
/**
 * WooCommerce Settings tab for Audio Watermark.
 *
 * Adds an "Audiobook WM" tab under WooCommerce > Settings with the two
 * configuration fields required by the watermark service.
 *
 * @package Audio_Watermark_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class Audio_WM_Settings {

    /**
     * Settings tab ID / section ID.
     */
    const TAB_ID = 'audio_wm';

    public function __construct() {
        // Register tab in the WC settings navigation.
        add_filter( 'woocommerce_settings_tabs_array', [ $this, 'add_settings_tab' ], 50 );

        // Render settings page content.
        add_action( 'woocommerce_settings_' . self::TAB_ID, [ $this, 'render_settings' ] );

        // Save settings when the form is submitted.
        add_action( 'woocommerce_settings_save_' . self::TAB_ID, [ $this, 'save_settings' ] );

        // Enqueue JS on our settings tab.
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );

        // AJAX: test the saved API connection.
        add_action( 'wp_ajax_audio_wm_test_connection', [ $this, 'ajax_test_connection' ] );
    }

    /**
     * Append our tab to the WC settings tab list.
     *
     * @param array $tabs Existing tabs.
     * @return array
     */
    public function add_settings_tab( array $tabs ): array {
        $tabs[ self::TAB_ID ] = __( 'Audiobook WM', 'audio-watermark-woo' );
        return $tabs;
    }

    /**
     * Return the settings field definitions.
     *
     * @return array WC settings array.
     */
    public function get_settings(): array {
        return [
            [
                'title' => __( 'Audio Watermark Service', 'audio-watermark-woo' ),
                'type'  => 'title',
                'desc'  => __( 'Configure the connection to the forensic audio watermarking API.', 'audio-watermark-woo' ),
                'id'    => 'audio_wm_section_start',
            ],
            [
                'title'       => __( 'Watermark service base URL', 'audio-watermark-woo' ),
                'type'        => 'text',
                'desc'        => __( 'Base URL of the deployed watermark service (no trailing slash).', 'audio-watermark-woo' ),
                'id'          => 'audio_wm_api_url',
                'placeholder' => 'https://xxx.execute-api.eu-central-1.amazonaws.com/Prod',
                'css'         => 'width:100%; max-width:600px;',
                'autoComplete' => 'off',
            ],
            [
                'title'        => __( 'API key (x-api-key header)', 'audio-watermark-woo' ),
                'type'         => 'password',
                'desc'         => __( 'Secret API key sent in the <code>x-api-key</code> header on every request.', 'audio-watermark-woo' ),
                'id'           => 'audio_wm_api_key',
                'css'          => 'width:100%; max-width:600px;',
                'autoComplete' => 'new-password',
            ],
            [
                'type' => 'sectionend',
                'id'   => 'audio_wm_section_end',
            ],
        ];
    }

    /**
     * Output the settings fields plus a "Test connection" button.
     */
    public function render_settings(): void {
        WC_Admin_Settings::output_fields( $this->get_settings() );
        ?>
        <p>
            <button type="button" id="audio-wm-test-btn" class="button">
                <?php esc_html_e( 'Test connection', 'audio-watermark-woo' ); ?>
            </button>
            <span id="audio-wm-test-result" style="margin-left:1em;"></span>
        </p>
        <?php
    }

    /**
     * Save the settings fields.
     */
    public function save_settings(): void {
        WC_Admin_Settings::save_fields( $this->get_settings() );
    }

    /**
     * Enqueue admin.js on the Audiobook WM settings tab only.
     *
     * @param string $hook Current admin page hook suffix.
     */
    public function enqueue_scripts( string $hook ): void {
        // Only load on the WC settings page.
        if ( 'woocommerce_page_wc-settings' !== $hook ) {
            return;
        }
        // Only load on our specific tab.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ( $_GET['tab'] ?? '' ) !== self::TAB_ID ) {
            return;
        }

        wp_enqueue_script(
            'audio-wm-admin',
            AUDIO_WM_PLUGIN_URL . 'assets/admin.js',
            [],
            AUDIO_WM_VERSION,
            true
        );

        wp_localize_script( 'audio-wm-admin', 'AudioWMSettings', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'audio_wm_test_nonce' ),
        ] );
    }

    /**
     * AJAX: send a test request to the watermark service to verify the saved
     * API URL and key. Expects a 400 (bad payload, but credentials accepted)
     * rather than 403 (key rejected) or a network error (URL wrong).
     */
    public function ajax_test_connection(): void {
        check_ajax_referer( 'audio_wm_test_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'audio-watermark-woo' ), 403 );
            return;
        }

        $api_url = rtrim( (string) get_option( 'audio_wm_api_url', '' ), '/' );
        $api_key = (string) get_option( 'audio_wm_api_key', '' );

        if ( ! $api_url ) {
            wp_send_json_error( __( 'No API URL configured. Save the settings first.', 'audio-watermark-woo' ) );
            return;
        }

        // POST /watermark with an intentionally invalid payload.
        // Expected: 400 (Lambda validates input and rejects) — meaning the URL
        // is reachable and the API key is accepted.
        // 403 → key is wrong; non-200/400 → URL wrong or service down.
        $response = wp_remote_post( $api_url . '/watermark', [
            'headers' => [
                'Content-Type' => 'application/json',
                'x-api-key'    => $api_key,
            ],
            'body'    => wp_json_encode( [] ),
            'timeout' => 8,
        ] );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error(
                sprintf(
                    /* translators: %s: error message */
                    __( 'Could not reach service: %s', 'audio-watermark-woo' ),
                    $response->get_error_message()
                )
            );
            return;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );

        if ( 400 === $code ) {
            wp_send_json_success( __( 'Connection OK — API key accepted.', 'audio-watermark-woo' ) );
        } elseif ( 403 === $code ) {
            wp_send_json_error( __( 'API key rejected (403 Forbidden). Check the key.', 'audio-watermark-woo' ) );
        } else {
            wp_send_json_error(
                sprintf(
                    /* translators: %d: HTTP status code */
                    __( 'Unexpected response HTTP %d. Check the API base URL.', 'audio-watermark-woo' ),
                    $code
                )
            );
        }
    }
}
