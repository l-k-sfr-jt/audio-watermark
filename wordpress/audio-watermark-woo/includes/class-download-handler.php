<?php
/**
 * Download handler for Audio Watermark.
 *
 * After an order is watermarked (see Audio_WM_Order_Handler), this class
 * shows a "Download Audiobook" button on the customer's order-details page
 * and serves a short-lived presigned S3 URL when the customer clicks it.
 *
 * Because the presigned URL from the watermark service is valid for only
 * one hour, the URL is never stored — it is fetched fresh on every click.
 * The watermark itself is idempotent (same order → same file in S3), so
 * repeated requests are cheap after the first watermarking pass.
 *
 * Security model:
 *  - The download action is available only to logged-in users (wp_ajax_*).
 *  - A per-order WordPress nonce guards against CSRF.
 *  - The handler verifies that the current user owns the order.
 *
 * @package Audio_Watermark_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class Audio_WM_Download_Handler {

    public function __construct() {
        add_action( 'woocommerce_order_details_after_order_table', [ $this, 'add_download_buttons' ] );
        add_action( 'wp_ajax_audio_wm_download',                   [ $this, 'handle_download' ] );
    }

    /**
     * Inject a "Download Audiobook" button below the order table on the
     * order-received / account > orders / view-order pages.
     *
     * Only rendered when:
     *  - The current user is the order's customer.
     *  - The order has been watermarked (has _watermark_code meta).
     *
     * @param WC_Order $order The order being displayed.
     */
    public function add_download_buttons( WC_Order $order ): void {
        // Must be the order owner.
        if ( get_current_user_id() !== (int) $order->get_customer_id() ) {
            return;
        }

        // Order must have been watermarked.
        $watermark_code = $order->get_meta( '_watermark_code', true );
        if ( ! $watermark_code ) {
            return;
        }

        $order_id     = $order->get_id();
        $download_url = wp_nonce_url(
            admin_url( 'admin-ajax.php' ) . '?action=audio_wm_download&order_id=' . $order_id,
            'audio_wm_download_' . $order_id
        );

        // Collect watermarked line-item names for the button label(s).
        $item_names = [];
        foreach ( $order->get_items() as $item ) {
            if ( ! ( $item instanceof WC_Order_Item_Product ) ) {
                continue;
            }
            $product_id = (int) $item->get_product_id();
            if ( 'yes' === get_post_meta( $product_id, '_audio_wm_enabled', true ) ) {
                $item_names[] = $item->get_name();
            }
        }

        if ( empty( $item_names ) ) {
            return;
        }

        echo '<section class="audio-wm-downloads" style="margin-top:1.5em;">';
        echo '<h2>' . esc_html__( 'Audiobook Downloads', 'audio-watermark-woo' ) . '</h2>';

        foreach ( $item_names as $name ) {
            printf(
                '<p><a href="%s" class="button" target="_blank">%s</a></p>',
                esc_url( $download_url ),
                esc_html(
                    sprintf(
                        /* translators: %s: product name */
                        __( 'Download: %s', 'audio-watermark-woo' ),
                        $name
                    )
                )
            );
        }

        echo '</section>';
    }

    /**
     * AJAX handler: verify ownership, fetch a fresh presigned URL from the
     * watermark service, and redirect the browser to it.
     *
     * Called via: /wp-admin/admin-ajax.php?action=audio_wm_download&order_id=NNN&_wpnonce=XXX
     * Access: logged-in users only (wp_ajax_*).
     */
    public function handle_download(): void {
        $order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;

        // ── Nonce check ──────────────────────────────────────────────────────
        if ( ! $order_id || ! check_admin_referer( 'audio_wm_download_' . $order_id ) ) {
            wp_die(
                esc_html__( 'Security check failed. Please go back and try again.', 'audio-watermark-woo' ),
                403
            );
            return;
        }

        // ── Load order ───────────────────────────────────────────────────────
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_die(
                esc_html__( 'Order not found.', 'audio-watermark-woo' ),
                404
            );
            return;
        }

        // ── Ownership check ──────────────────────────────────────────────────
        if ( get_current_user_id() !== (int) $order->get_customer_id() ) {
            wp_die(
                esc_html__( 'You do not have permission to download this file.', 'audio-watermark-woo' ),
                403
            );
            return;
        }

        // ── Retrieve stored master key ────────────────────────────────────────
        $master_key = $order->get_meta( '_watermark_master_key', true );
        if ( ! $master_key ) {
            wp_die(
                esc_html__( 'Could not generate download link. Please contact support.', 'audio-watermark-woo' ),
                500
            );
            return;
        }

        // ── Call watermark service (idempotent — returns a fresh presigned URL) ──
        try {
            $result = Audio_WM_Order_Handler::call_service( '/watermark', [
                'master_key' => $master_key,
                'order_id'   => $order_id,
            ] );
        } catch ( \Exception $e ) {
            error_log( "[Audio WM] Download failed — order #{$order_id}: " . $e->getMessage() );
            wp_die(
                esc_html__( 'Could not generate download link. Please try again.', 'audio-watermark-woo' ),
                403
            );
            return;
        }

        if ( empty( $result['download_url'] ) ) {
            error_log( "[Audio WM] Download failed — order #{$order_id}: service returned no download_url" );
            wp_die(
                esc_html__( 'Could not generate download link. Please try again.', 'audio-watermark-woo' ),
                500
            );
            return;
        }

        // ── Redirect to the presigned S3 URL ─────────────────────────────────
        wp_redirect( $result['download_url'], 302 );
        exit;
    }
}
