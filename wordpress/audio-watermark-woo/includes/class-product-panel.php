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

        // ── Multi-file master list (set by JS after each upload) ────────────
        // New format: _audio_wm_s3_keys holds a JSON array of S3 keys.
        // Falls back to legacy _audio_wm_s3_key (single string) for existing products.
        $keys_json   = get_post_meta( $post->ID, '_audio_wm_s3_keys', true );
        $master_keys = [];
        if ( $keys_json ) {
            $master_keys = json_decode( $keys_json, true ) ?: [];
        }
        if ( empty( $master_keys ) ) {
            $legacy_key = get_post_meta( $post->ID, '_audio_wm_s3_key', true );
            if ( $legacy_key ) {
                $master_keys = [ $legacy_key ];
            }
        }
        ?>
        <p class="form-field">
            <label><?php esc_html_e( 'Master audio files', 'audio-watermark-woo' ); ?></label>

            <input type="hidden"
                   id="_audio_wm_s3_keys"
                   name="_audio_wm_s3_keys"
                   value="<?php echo esc_attr( wp_json_encode( $master_keys ) ); ?>">

            <ul id="audio-wm-files-list" style="margin:0 0 8px;padding:0;list-style:none;">
            <?php foreach ( $master_keys as $mk ) : ?>
                <li style="display:flex;align-items:center;gap:8px;margin-bottom:4px;" data-key="<?php echo esc_attr( $mk ); ?>">
                    <code style="flex:1;"><?php echo esc_html( basename( $mk ) ); ?></code>
                    <button type="button" class="button-link audio-wm-remove-file" style="color:#a00;">
                        <?php esc_html_e( 'Remove', 'audio-watermark-woo' ); ?>
                    </button>
                </li>
            <?php endforeach; ?>
            </ul>

            <button type="button" id="audio-wm-upload-btn" class="button">
                <?php esc_html_e( 'Add master audio files', 'audio-watermark-woo' ); ?>
            </button>

            <input type="file"
                   id="audio-wm-file"
                   accept="audio/*"
                   multiple
                   style="display:none">

            <span id="audio-wm-upload-status" style="display:block;margin-top:6px;"></span>
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

        // Multi-file master keys: stored as a JSON array in _audio_wm_s3_keys.
        // Each entry must start with masters/ and must not contain .. segments.
        if ( isset( $_POST['_audio_wm_s3_keys'] ) ) {
            $raw   = wp_unslash( $_POST['_audio_wm_s3_keys'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
            $keys  = json_decode( $raw, true );
            $clean = [];
            if ( is_array( $keys ) ) {
                foreach ( $keys as $key ) {
                    $key = sanitize_text_field( (string) $key );
                    if ( strncmp( $key, 'masters/', 8 ) === 0 && strpos( $key, '..' ) === false ) {
                        $clean[] = $key;
                    }
                }
            }
            update_post_meta( $post_id, '_audio_wm_s3_keys', wp_json_encode( $clean ) );
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
