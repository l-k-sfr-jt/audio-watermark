<?php
/**
 * Product admin panel for Audio Watermark.
 *
 * Adds watermarking fields to the WooCommerce simple-product "General" tab:
 *  - a checkbox to enable watermarking for this product
 *  - a (readonly) text field showing the uploaded S3 master key
 *  - an "Upload master audio" button that drives a two-step browser upload:
 *      1. AJAX → PHP → watermark service to get a presigned S3 PUT URL
 *      2. browser PUT directly to S3 (no file data passes through WordPress)
 *
 * @package Audio_Watermark_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class Audio_WM_Product_Panel {

    public function __construct() {
        add_action( 'woocommerce_product_options_general_product_data', [ $this, 'add_fields' ] );
        add_action( 'woocommerce_process_product_meta',                 [ $this, 'save_fields' ] );
        add_action( 'admin_enqueue_scripts',                            [ $this, 'enqueue_scripts' ] );
        add_action( 'wp_ajax_audio_wm_get_upload_url',                  [ $this, 'ajax_get_upload_url' ] );
    }

    /**
     * Render the custom fields inside the General product data tab.
     */
    public function add_fields(): void {
        global $post;

        echo '<div class="options_group audio-wm-options-group">';

        // ── Enable watermarking ──────────────────────────────────────────────
        woocommerce_wp_checkbox( [
            'id'          => '_audio_wm_enabled',
            'label'       => __( 'Enable audiobook watermarking', 'audio-watermark-woo' ),
            'description' => __( 'When checked, a unique watermark is embedded into this product\'s audio file for each order.', 'audio-watermark-woo' ),
            'value'       => get_post_meta( $post->ID, '_audio_wm_enabled', true ),
        ] );

        // ── S3 master key (set by JS after a successful upload) ──────────────
        woocommerce_wp_text_input( [
            'id'                => '_audio_wm_s3_key',
            'label'             => __( 'S3 master key', 'audio-watermark-woo' ),
            'description'       => __( 'Filled automatically after the master audio file is uploaded.', 'audio-watermark-woo' ),
            'desc_tip'          => true,
            'value'             => get_post_meta( $post->ID, '_audio_wm_s3_key', true ),
            'custom_attributes' => [ 'readonly' => 'readonly' ],
        ] );

        // ── Upload control ───────────────────────────────────────────────────
        ?>
        <p class="form-field">
            <label><?php esc_html_e( 'Master audio file', 'audio-watermark-woo' ); ?></label>

            <button type="button" id="audio-wm-upload-btn" class="button">
                <?php esc_html_e( 'Upload master audio', 'audio-watermark-woo' ); ?>
            </button>

            <input type="file"
                   id="audio-wm-file"
                   accept="audio/*"
                   style="display:none">

            <span id="audio-wm-upload-status" style="margin-left:10px;"></span>
        </p>
        <?php

        echo '</div><!-- .audio-wm-options-group -->';
    }

    /**
     * Persist the custom fields when the product is saved.
     *
     * @param int $post_id WooCommerce product post ID.
     */
    public function save_fields( int $post_id ): void {
        // Checkbox: present in $_POST only when checked.
        $enabled = isset( $_POST['_audio_wm_enabled'] ) ? 'yes' : 'no';
        update_post_meta( $post_id, '_audio_wm_enabled', $enabled );

        // S3 key: plain text, no HTML allowed.
        if ( isset( $_POST['_audio_wm_s3_key'] ) ) {
            update_post_meta(
                $post_id,
                '_audio_wm_s3_key',
                sanitize_text_field( wp_unslash( $_POST['_audio_wm_s3_key'] ) )
            );
        }
    }

    /**
     * Enqueue admin.js on product edit screens and localise the data it needs.
     *
     * @param string $hook Current admin page hook suffix.
     */
    public function enqueue_scripts( string $hook ): void {
        // Only load on "Edit product" (post.php) and "New product" (post-new.php).
        if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
            return;
        }

        // Confirm we're editing a product.
        $screen = get_current_screen();
        if ( ! $screen || 'product' !== $screen->post_type ) {
            return;
        }

        wp_enqueue_script(
            'audio-wm-admin',
            AUDIO_WM_PLUGIN_URL . 'assets/admin.js',
            [], // no jQuery dependency — vanilla JS
            AUDIO_WM_VERSION,
            true // load in footer
        );

        global $post;
        $product_id = $post ? (int) $post->ID : 0;

        wp_localize_script( 'audio-wm-admin', 'AudioWM', [
            'ajax_url'   => admin_url( 'admin-ajax.php' ),
            'nonce'      => wp_create_nonce( 'audio_wm_upload_nonce' ),
            'product_id' => $product_id,
        ] );
    }

    /**
     * AJAX handler: ask the watermark service for a presigned S3 PUT URL.
     *
     * Expected $_POST: product_id, filename, content_type, nonce
     * Returns JSON { upload_url, s3_key } or wp_send_json_error.
     */
    public function ajax_get_upload_url(): void {
        // ── Security ─────────────────────────────────────────────────────────
        // No `false` third arg: let check_ajax_referer() die automatically on
        // invalid nonce rather than relying on the caller to return after the check.
        check_ajax_referer( 'audio_wm_upload_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_products' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'audio-watermark-woo' ) ], 403 );
            return;
        }

        // ── Validate inputs ───────────────────────────────────────────────────
        $product_id   = isset( $_POST['product_id'] )   ? absint( $_POST['product_id'] )                               : 0;
        $filename     = isset( $_POST['filename'] )     ? sanitize_file_name( wp_unslash( $_POST['filename'] ) )       : '';
        $content_type = isset( $_POST['content_type'] ) ? sanitize_mime_type( wp_unslash( $_POST['content_type'] ) )   : '';

        if ( ! $product_id || ! $filename || ! $content_type ) {
            wp_send_json_error( [ 'message' => __( 'Missing required fields: product_id, filename, content_type.', 'audio-watermark-woo' ) ], 400 );
            return;
        }

        // ── Call watermark service ────────────────────────────────────────────
        $api_url = rtrim( (string) get_option( 'audio_wm_api_url', '' ), '/' );
        $api_key = (string) get_option( 'audio_wm_api_key', '' );

        if ( ! $api_url ) {
            wp_send_json_error( [ 'message' => __( 'Watermark service URL is not configured. Please visit WooCommerce > Settings > Audiobook WM.', 'audio-watermark-woo' ) ], 500 );
            return;
        }

        $response = wp_remote_post(
            $api_url . '/products/upload-url',
            [
                'timeout'     => 30,
                'headers'     => [
                    'Content-Type' => 'application/json',
                    'x-api-key'    => $api_key,
                ],
                'body'        => wp_json_encode( [
                    'product_id'   => (string) $product_id,
                    'filename'     => $filename,
                    'content_type' => $content_type,
                ] ),
            ]
        );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( [ 'message' => $response->get_error_message() ], 502 );
            return;
        }

        $http_code = wp_remote_retrieve_response_code( $response );
        $raw_body  = wp_remote_retrieve_body( $response );
        $body      = json_decode( $raw_body, true );

        if ( $http_code < 200 || $http_code >= 300 ) {
            $detail = is_array( $body ) && isset( $body['message'] ) ? $body['message'] : $raw_body;
            wp_send_json_error(
                [ 'message' => sprintf( __( 'Service error %d: %s', 'audio-watermark-woo' ), $http_code, $detail ) ],
                502
            );
            return;
        }

        if ( ! is_array( $body ) || empty( $body['upload_url'] ) || empty( $body['s3_key'] ) ) {
            wp_send_json_error( [ 'message' => __( 'Unexpected response from watermark service.', 'audio-watermark-woo' ) ], 502 );
            return;
        }

        wp_send_json_success( [
            'upload_url' => $body['upload_url'],
            's3_key'     => $body['s3_key'],
        ] );
    }
}
