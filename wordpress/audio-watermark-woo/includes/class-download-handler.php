<?php
/**
 * Download handler for Audio Watermark.
 *
 * After an order is watermarked (see Audio_WM_Order_Handler), this class
 * shows a "Download Audiobook" button per watermarked line item on the
 * customer's order-details page, and serves a short-lived presigned S3 URL
 * when the customer clicks it.
 *
 * Per-item design: each watermarked order item gets its own download button
 * and passes its own item_id to the download action, so orders with multiple
 * audio products each serve the correct file independently.
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
 *  - The handler verifies that the requested item_id belongs to the order.
 *  - download_url from the service is validated to an amazonaws.com host
 *    before redirecting to prevent open-redirect abuse.
 *
 * Note on `woocommerce_order_details_after_order_table`: this hook also fires
 * inside WooCommerce HTML order emails. The get_current_user_id() === 0 check
 * inside add_download_buttons() suppresses output in that context (no user is
 * logged in when WC renders the email), so no link leaks into email bodies.
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
     * Inject a "Download" button for each watermarked item below the order table
     * on the order-received / account > orders / view-order pages.
     *
     * Only rendered when:
     *  - The current user is logged in and is the order's customer.
     *  - The order has been watermarked (has _watermark_code order meta).
     *
     * Each item gets its own per-item URL so the correct master file is served.
     *
     * @param WC_Order $order The order being displayed.
     */
    public function add_download_buttons( WC_Order $order ): void {
        $current_user_id = get_current_user_id();

        // Must be a logged-in user who owns the order.
        if ( ! $current_user_id || $current_user_id !== (int) $order->get_customer_id() ) {
            return;
        }

        // Order must have been watermarked.
        if ( ! $order->get_meta( '_watermark_code', true ) ) {
            return;
        }

        $order_id = $order->get_id();

        // Collect watermarked items that have a stored master key in item meta.
        $watermarked_items = [];
        foreach ( $order->get_items() as $item_id => $item ) {
            if ( ! ( $item instanceof WC_Order_Item_Product ) ) {
                continue;
            }
            $master_key = $item->get_meta( '_audio_wm_master_key' );
            if ( $master_key ) {
                $watermarked_items[ $item_id ] = $item->get_name();
            }
        }

        if ( empty( $watermarked_items ) ) {
            return;
        }

        $nonce = wp_create_nonce( 'audio_wm_download_' . $order_id );

        echo '<section class="audio-wm-downloads" style="margin-top:1.5em;">';
        echo '<h2>' . esc_html__( 'Audiobook Downloads', 'audio-watermark-woo' ) . '</h2>';

        foreach ( $watermarked_items as $item_id => $item_name ) {
            // Each item gets its own URL so the correct master file is served.
            $download_url = add_query_arg(
                [
                    'action'   => 'audio_wm_download',
                    'order_id' => $order_id,
                    'item_id'  => $item_id,
                    '_wpnonce' => $nonce,
                ],
                admin_url( 'admin-ajax.php' )
            );

            printf(
                '<p><a href="%s" class="button" target="_blank">%s</a></p>',
                esc_url( $download_url ),
                esc_html(
                    sprintf(
                        /* translators: %s: product name */
                        __( 'Download: %s', 'audio-watermark-woo' ),
                        $item_name
                    )
                )
            );
        }

        echo '</section>';
    }

    /**
     * AJAX handler: verify ownership, fetch a fresh presigned URL from the
     * watermark service, validate the URL domain, and redirect the browser.
     *
     * Called via: /wp-admin/admin-ajax.php?action=audio_wm_download&order_id=NNN&item_id=MMM&_wpnonce=XXX
     * Access: logged-in users only (wp_ajax_*).
     */
    public function handle_download(): void {
        $order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
        $item_id  = isset( $_GET['item_id'] )  ? absint( $_GET['item_id'] )  : 0;

        // ── Nonce check ──────────────────────────────────────────────────────
        if ( ! $order_id || ! check_admin_referer( 'audio_wm_download_' . $order_id ) ) {
            wp_die(
                esc_html__( 'Security check failed. Please go back and try again.', 'audio-watermark-woo' ),
                '',
                [ 'response' => 403 ]
            );
            return;
        }

        // ── Load order ───────────────────────────────────────────────────────
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_die(
                esc_html__( 'Order not found.', 'audio-watermark-woo' ),
                '',
                [ 'response' => 404 ]
            );
            return;
        }

        // ── Ownership check ──────────────────────────────────────────────────
        $current_user_id = get_current_user_id();
        if ( ! $current_user_id || $current_user_id !== (int) $order->get_customer_id() ) {
            wp_die(
                esc_html__( 'You do not have permission to download this file.', 'audio-watermark-woo' ),
                '',
                [ 'response' => 403 ]
            );
            return;
        }

        // ── Verify item belongs to this order and retrieve its master key ─────
        if ( ! $item_id ) {
            wp_die(
                esc_html__( 'Invalid download request.', 'audio-watermark-woo' ),
                '',
                [ 'response' => 400 ]
            );
            return;
        }

        // Load the item from the already-loaded order object; this simultaneously
        // confirms the item belongs to this order (get_item returns null otherwise).
        $item       = $order->get_item( $item_id );
        $master_key = ( $item instanceof WC_Order_Item_Product )
            ? $item->get_meta( '_audio_wm_master_key' )
            : '';

        if ( ! $master_key ) {
            wp_die(
                esc_html__( 'Could not generate download link. Please contact support.', 'audio-watermark-woo' ),
                '',
                [ 'response' => 404 ]
            );
            return;
        }

        // ── Call watermark service (idempotent — returns a fresh presigned URL) ──
        try {
            // Send the same item_id the order handler used so this resolves to
            // the per-item copy (orders/<order_id>/<item_id>.mp3), not whichever
            // title happened to be watermarked first in a multi-item order.
            $result = Audio_WM_Order_Handler::call_service( '/watermark', [
                'master_key' => $master_key,
                'order_id'   => $order_id,
                'item_id'    => $item_id,
            ] );
        } catch ( \Exception $e ) {
            error_log( "[Audio WM] Download failed — order #{$order_id}, item #{$item_id}: " . $e->getMessage() );
            wp_die(
                esc_html__( 'Could not generate download link. Please try again.', 'audio-watermark-woo' ),
                '',
                [ 'response' => 503 ]
            );
            return;
        }

        if ( empty( $result['download_url'] ) ) {
            error_log( "[Audio WM] Download failed — order #{$order_id}, item #{$item_id}: service returned no download_url" );
            wp_die(
                esc_html__( 'Could not generate download link. Please try again.', 'audio-watermark-woo' ),
                '',
                [ 'response' => 502 ]
            );
            return;
        }

        // ── Validate download_url domain (open-redirect guard) ────────────────
        // The URL must be an HTTPS presigned S3 URL. If the audio_wm_api_url
        // option is misconfigured to point at an attacker-controlled host, the
        // returned download_url could be arbitrary — reject anything not on
        // amazonaws.com so customers can never be redirected to a hostile site.
        $download_url = $result['download_url'];
        $host         = (string) wp_parse_url( $download_url, PHP_URL_HOST );
        if ( ! $host
            || 'https' !== wp_parse_url( $download_url, PHP_URL_SCHEME )
            || substr( $host, -strlen( '.amazonaws.com' ) ) !== '.amazonaws.com'
        ) {
            error_log( "[Audio WM] Rejected non-S3 download_url for order #{$order_id}: {$download_url}" );
            wp_die(
                esc_html__( 'Invalid download URL returned by service.', 'audio-watermark-woo' ),
                '',
                [ 'response' => 502 ]
            );
            return;
        }

        // ── Redirect to the validated presigned S3 URL ────────────────────────
        wp_redirect( $download_url, 302 );
        exit;
    }
}
