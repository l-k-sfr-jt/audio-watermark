<?php
/**
 * Order completion handler for Audio Watermark.
 *
 * When a WooCommerce order reaches "completed" status, this class iterates
 * every line item in the order, checks whether the underlying product has
 * audiobook watermarking enabled, and—if so—calls the watermark service to
 * embed the buyer's order ID into the audio master.
 *
 * On success the master S3 key is stored in ORDER ITEM meta (not order meta)
 * so that orders containing multiple watermarked products each track their
 * own master key independently. A single order-level _watermark_code flag
 * signals that at least one item has been watermarked.
 *
 * Service call failures are deliberately non-fatal: they are logged with
 * error_log() but do not block order completion or throw exceptions.
 *
 * @package Audio_Watermark_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class Audio_WM_Order_Handler {

    public function __construct() {
        add_action( 'woocommerce_order_status_completed', [ $this, 'process_order' ], 10, 1 );
    }

    /**
     * Process a completed order: watermark every eligible audio product.
     *
     * @param int $order_id WooCommerce order ID.
     */
    public function process_order( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            error_log( "[Audio WM] process_order: could not load order #{$order_id}" );
            return;
        }

        $any_watermarked = false;

        foreach ( $order->get_items() as $item_id => $item ) {
            if ( ! ( $item instanceof WC_Order_Item_Product ) ) {
                continue;
            }

            $product_id = (int) $item->get_product_id();

            $enabled    = get_post_meta( $product_id, '_audio_wm_enabled', true );
            $master_key = get_post_meta( $product_id, '_audio_wm_s3_key', true );

            if ( 'yes' !== $enabled || ! $master_key ) {
                continue;
            }

            try {
                $result = $this->call_service( '/watermark', [
                    'master_key' => $master_key,
                    'order_id'   => $order_id,
                ] );

                // Store the master key in ITEM meta so each product in the order
                // can be downloaded independently (order meta would be overwritten
                // on each iteration if the order contains multiple audio products).
                $item->update_meta_data( '_audio_wm_master_key', $master_key );
                $item->save();
                $any_watermarked = true;

                error_log( sprintf(
                    '[Audio WM] Watermark applied — order #%d, item #%d, product #%d, code %s, from_cache %s',
                    $order_id,
                    $item_id,
                    $product_id,
                    $result['watermark_code'] ?? 'n/a',
                    ! empty( $result['from_cache'] ) ? 'true' : 'false'
                ) );

            } catch ( \Exception $e ) {
                // Log but do NOT abort the order completion flow.
                error_log( sprintf(
                    '[Audio WM] Watermark failed — order #%d, item #%d, product #%d: %s',
                    $order_id,
                    $item_id,
                    $product_id,
                    $e->getMessage()
                ) );
            }
        }

        // Order-level flag: at least one item was watermarked (used by the
        // download handler to decide whether to show the download section).
        if ( $any_watermarked ) {
            $order->update_meta_data( '_watermark_code', $order_id );
            $order->save_meta_data();
        }
    }

    /**
     * Shared helper: send a JSON POST request to the watermark service.
     *
     * @param string $endpoint Path relative to the base URL, e.g. '/watermark'.
     * @param array  $body     Associative array; will be JSON-encoded.
     * @return array Decoded response body.
     * @throws \RuntimeException On WP_Error, non-2xx HTTP response, or invalid JSON.
     */
    public static function call_service( string $endpoint, array $body ): array {
        $api_url = rtrim( (string) get_option( 'audio_wm_api_url', '' ), '/' );
        $api_key = (string) get_option( 'audio_wm_api_key', '' );

        if ( ! $api_url ) {
            throw new \RuntimeException( 'Watermark service URL is not configured (audio_wm_api_url option is empty).' );
        }

        $url      = $api_url . $endpoint;
        $response = wp_remote_post( $url, [
            'timeout' => 30,
            'headers' => [
                'Content-Type' => 'application/json',
                'x-api-key'    => $api_key,
            ],
            'body'    => wp_json_encode( $body ),
        ] );

        if ( is_wp_error( $response ) ) {
            throw new \RuntimeException(
                'HTTP request failed: ' . $response->get_error_message()
            );
        }

        $http_code = (int) wp_remote_retrieve_response_code( $response );
        $raw_body  = wp_remote_retrieve_body( $response );

        if ( $http_code < 200 || $http_code >= 300 ) {
            $detail = '';
            $decoded = json_decode( $raw_body, true );
            if ( is_array( $decoded ) && isset( $decoded['message'] ) ) {
                $detail = ' — ' . $decoded['message'];
            }
            throw new \RuntimeException(
                "Service returned HTTP {$http_code}{$detail}"
            );
        }

        $data = json_decode( $raw_body, true );
        if ( ! is_array( $data ) ) {
            throw new \RuntimeException(
                'Service returned non-JSON or unexpected body: ' . substr( $raw_body, 0, 200 )
            );
        }

        return $data;
    }
}
