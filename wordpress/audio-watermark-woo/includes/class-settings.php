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
     * Output the settings fields.
     */
    public function render_settings(): void {
        WC_Admin_Settings::output_fields( $this->get_settings() );
    }

    /**
     * Save the settings fields.
     */
    public function save_settings(): void {
        WC_Admin_Settings::save_fields( $this->get_settings() );
    }
}
