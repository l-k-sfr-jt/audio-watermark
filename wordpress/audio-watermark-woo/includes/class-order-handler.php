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

    /** Maximum number of automatic retry attempts per item. */
    const MAX_RETRIES = 3;

    public function __construct() {
        add_action( 'woocommerce_order_status_completed', [ $this, 'process_order' ], 10, 1 );
        // Action Scheduler hook for deferred retry (ships with WooCommerce ≥ 3.5).
        add_action( 'audio_wm_retry_watermark', [ $this, 'retry_watermark' ], 10, 4 );
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

            // Skip items already watermarked in a previous run (e.g. if the
            // order-completed hook fires twice due to a manual status change).
            // The service call is idempotent, but we avoid queuing extra
            // Action Scheduler jobs for items that are already done.
            if ( $item->get_meta( '_audio_wm_master_key' ) ) {
                continue;
            }

            try {
                // item_id namespaces the stored copy (orders/<order_id>/<item_id>.mp3)
                // so multiple different audiobooks in one order don't collide on a
                // single orders/<order_id>.mp3 key. The download handler sends the
                // same item_id so it resolves to this exact copy.
                $result = $this->call_service( '/watermark', [
                    'master_key' => $master_key,
                    'order_id'   => $order_id,
                    'item_id'    => $item_id,
                ] );

                // Store the master key in ITEM meta so each product in the order
                // can be downloaded independently (order meta would be overwritten
                // on each iteration if the order contains multiple audio products).
                $item->update_meta_data( '_audio_wm_master_key', $master_key );
                $item->save();
                $any_watermarked = true;

                $cache_label = ! empty( $result['from_cache'] ) ? ' (from cache)' : '';
                $order->add_order_note( sprintf(
                    /* translators: 1: item ID, 2: product ID, 3: watermark code, 4: cache label */
                    __( '[Audio WM] Audiobook watermarked — item #%1$d (product #%2$d), code %3$s%4$s', 'audio-watermark-woo' ),
                    $item_id,
                    $product_id,
                    $result['watermark_code'] ?? 'n/a',
                    $cache_label
                ) );

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
                $err = $e->getMessage();
                error_log( sprintf(
                    '[Audio WM] Watermark failed — order #%d, item #%d, product #%d: %s',
                    $order_id, $item_id, $product_id, $err
                ) );

                // Schedule an automatic retry via Action Scheduler (bundled with WooCommerce ≥ 3.5).
                // Retry attempt 1 at +5 min, attempt 2 at +30 min, attempt 3 at +2 h.
                if ( function_exists( 'as_schedule_single_action' ) ) {
                    $delays = [ 300, 1800, 7200 ];
                    $attempt = 1;
                    $order->add_order_note( sprintf(
                        /* translators: 1: item ID, 2: error, 3: delay in minutes */
                        __( '[Audio WM] Watermarking failed for item #%1$d: %2$s. Retry #%3$d scheduled in %4$d min.', 'audio-watermark-woo' ),
                        $item_id, $err, $attempt, (int) ( $delays[0] / 60 )
                    ) );
                    as_schedule_single_action(
                        time() + $delays[0],
                        'audio_wm_retry_watermark',
                        [ $order_id, $item_id, $master_key, $attempt ]
                    );
                } else {
                    $order->add_order_note( sprintf(
                        /* translators: 1: item ID, 2: error */
                        __( '[Audio WM] Watermarking failed for item #%1$d: %2$s. Please retry manually.', 'audio-watermark-woo' ),
                        $item_id, $err
                    ) );
                }
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
     * Action Scheduler callback: retry a failed watermark job.
     *
     * Scheduled with three slots (attempts 1-3) at 5 min / 30 min / 2 h delays.
     * On success it adds an order note. On final failure it notes that manual
     * intervention is needed and stops scheduling.
     *
     * @param int    $order_id   WooCommerce order ID.
     * @param int    $item_id    WooCommerce order-item ID.
     * @param string $master_key S3 key of the audio master.
     * @param int    $attempt    Which retry this is (1-indexed).
     */
    public function retry_watermark( int $order_id, int $item_id, string $master_key, int $attempt ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            error_log( "[Audio WM] retry_watermark: could not load order #{$order_id}" );
            return;
        }

        try {
            $result = $this->call_service( '/watermark', [
                'master_key' => $master_key,
                'order_id'   => $order_id,
                'item_id'    => $item_id,
            ] );

            // On success: store master key in item meta (same as the initial flow).
            $item = $order->get_item( $item_id );
            if ( $item instanceof WC_Order_Item_Product ) {
                $item->update_meta_data( '_audio_wm_master_key', $master_key );
                $item->save();
            }

            // Ensure the order-level flag is set so download buttons appear.
            if ( ! $order->get_meta( '_watermark_code' ) ) {
                $order->update_meta_data( '_watermark_code', $order_id );
                $order->save_meta_data();
            }

            $order->add_order_note( sprintf(
                /* translators: 1: retry attempt number, 2: item ID, 3: watermark code */
                __( '[Audio WM] Retry #%1$d succeeded — item #%2$d watermarked (code %3$s).', 'audio-watermark-woo' ),
                $attempt, $item_id, $result['watermark_code'] ?? 'n/a'
            ) );

            error_log( sprintf(
                '[Audio WM] Retry #%d succeeded — order #%d, item #%d',
                $attempt, $order_id, $item_id
            ) );

        } catch ( \Exception $e ) {
            $err     = $e->getMessage();
            $delays  = [ 300, 1800, 7200 ];
            $next    = $attempt + 1;

            error_log( sprintf(
                '[Audio WM] Retry #%d failed — order #%d, item #%d: %s',
                $attempt, $order_id, $item_id, $err
            ) );

            if ( $next <= self::MAX_RETRIES && function_exists( 'as_schedule_single_action' ) ) {
                $delay = $delays[ $attempt ] ?? 7200;
                $order->add_order_note( sprintf(
                    /* translators: 1: attempt, 2: item ID, 3: error, 4: next attempt, 5: delay minutes */
                    __( '[Audio WM] Retry #%1$d failed for item #%2$d: %3$s. Retry #%4$d scheduled in %5$d min.', 'audio-watermark-woo' ),
                    $attempt, $item_id, $err, $next, (int) ( $delay / 60 )
                ) );
                as_schedule_single_action(
                    time() + $delay,
                    'audio_wm_retry_watermark',
                    [ $order_id, $item_id, $master_key, $next ]
                );
            } else {
                $order->add_order_note( sprintf(
                    /* translators: 1: attempt, 2: item ID, 3: error */
                    __( '[Audio WM] All %1$d retry attempts failed for item #%2$d: %3$s. Manual action required.', 'audio-watermark-woo' ),
                    $attempt, $item_id, $err
                ) );
            }
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
