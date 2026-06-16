<?php
/**
 * Plugin Name: Audio Watermark for WooCommerce
 * Plugin URI:  https://github.com/your-org/audio-watermark
 * Description: Embeds a unique forensic watermark (buyer's order ID) into audiobook WAV files so leaked copies can be traced back to the buyer.
 * Version:     1.0.0
 * Author:      Audio Watermark
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 * WC tested up to: 8.5
 * Text Domain: audio-watermark-woo
 */

defined( 'ABSPATH' ) || exit;

define( 'AUDIO_WM_VERSION',    '1.0.0' );
define( 'AUDIO_WM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AUDIO_WM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Activation hook — options have defaults so nothing to set up here.
 */
register_activation_hook( __FILE__, function () {
    // No-op: all options are stored/read on demand.
} );

/**
 * Bootstrap after all plugins are loaded, so we can confirm WooCommerce is active.
 */
add_action( 'plugins_loaded', function () {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-error"><p>'
                . esc_html__( 'Audio Watermark for WooCommerce requires WooCommerce 6.0 or later to be installed and active.', 'audio-watermark-woo' )
                . '</p></div>';
        } );
        return;
    }

    require_once AUDIO_WM_PLUGIN_DIR . 'includes/class-settings.php';
    require_once AUDIO_WM_PLUGIN_DIR . 'includes/class-product-panel.php';
    require_once AUDIO_WM_PLUGIN_DIR . 'includes/class-order-handler.php';
    require_once AUDIO_WM_PLUGIN_DIR . 'includes/class-download-handler.php';

    new Audio_WM_Settings();
    new Audio_WM_Product_Panel();
    new Audio_WM_Order_Handler();
    new Audio_WM_Download_Handler();
} );
