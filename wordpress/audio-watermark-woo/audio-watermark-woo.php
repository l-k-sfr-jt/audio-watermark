<?php
/**
 * Plugin Name: Audio Watermark for WooCommerce
 * Plugin URI:  https://github.com/your-org/audio-watermark
 * Description: Embeds a unique forensic watermark (buyer's order ID) into audiobook WAV files so leaked copies can be traced back to the buyer.
 * Version:     1.1.0
 * Author:      Audio Watermark
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 * WC tested up to: 8.5
 * Text Domain: audio-watermark-woo
 */

defined( 'ABSPATH' ) || exit;

define( 'AUDIO_WM_VERSION',    '1.1.0' );
define( 'AUDIO_WM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AUDIO_WM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Declare HPOS (High-Performance Order Storage) compatibility so WC 7+ HPOS
// feature works without a compatibility warning in WooCommerce > Status.
add_action( 'before_woocommerce_init', function () {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );

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

/**
 * Register our custom WC_Email so it appears under WooCommerce > Settings > Emails
 * and so WooCommerce loads it (its constructor wires the send-on-order hooks).
 *
 * The class file is required here (not in plugins_loaded above) because the
 * woocommerce_email_classes filter passes the WC_Email base class, which only
 * exists once WooCommerce has booted its mailer.
 */
add_filter( 'woocommerce_email_classes', function ( array $emails ): array {
    require_once AUDIO_WM_PLUGIN_DIR . 'includes/class-email-download.php';
    $emails['Audio_WM_Email_Download'] = new Audio_WM_Email_Download();
    return $emails;
} );
