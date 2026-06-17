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
        // Watermark as soon as payment is confirmed. Digital goods normally land
        // in "processing" and only move to "completed" on manual admin action (or
        // not at all), so hooking only "completed" means the watermark Lambda may
        // never fire. Hook both; the per-key idempotency guard makes the second
        // event a no-op.
        add_action( 'woocommerce_order_status_processing', [ $this, 'process_order' ], 10, 1 );
        add_action( 'woocommerce_order_status_completed', [ $this, 'process_order' ], 10, 1 );
        // Action Scheduler hook for deferred retry (ships with WooCommerce ≥ 3.5).
        // 5 args: order_id, item_id, master_key, part, attempt.
        add_action( 'audio_wm_retry_watermark', [ $this, 'retry_watermark' ], 10, 5 );
        // Manual re-watermark from WC Admin → Orders → Order Actions dropdown.
        add_filter( 'woocommerce_order_actions',                        [ $this, 'add_rewatermark_action' ] );
        add_action( 'woocommerce_order_action_audio_wm_rewatermark',    [ $this, 'rewatermark_order' ] );
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

            if ( 'yes' !== $enabled ) {
                continue;
            }

            // Read the ordered list of master keys for this product.
            // New format: _audio_wm_s3_keys holds a JSON array.
            // Falls back to legacy _audio_wm_s3_key (single string).
            $master_keys = $this->get_product_master_keys( $product_id );
            if ( empty( $master_keys ) ) {
                continue;
            }

            // Keys already successfully watermarked for this item (per-key idempotency).
            $done_keys = $this->get_item_done_keys( $item );

            $multi = count( $master_keys ) > 1;

            foreach ( $master_keys as $master_key ) {
                // Skip this key if already done (e.g. hook fired twice).
                if ( in_array( $master_key, $done_keys, true ) ) {
                    continue;
                }

                $part = $multi ? $this->part_for( $master_key ) : '';

                try {
                    $payload = [
                        'master_key' => $master_key,
                        'order_id'   => $order_id,
                        'item_id'    => $item_id,
                    ];
                    if ( '' !== $part ) {
                        $payload['part'] = $part;
                    }

                    $result = $this->call_service( '/watermark', $payload );

                    // Record this key as done in item meta.
                    $done_keys[] = $master_key;
                    $item->update_meta_data( '_audio_wm_master_keys', wp_json_encode( $done_keys ) );
                    $item->save();
                    $any_watermarked = true;

                    $cache_label = ! empty( $result['from_cache'] ) ? ' (from cache)' : '';
                    $note_part   = '' !== $part ? sprintf( ' [%s]', $part ) : '';
                    $order->add_order_note( sprintf(
                        /* translators: 1: item ID, 2: product ID, 3: part label, 4: watermark code, 5: cache label */
                        __( '[Audio WM] Watermarked — item #%1$d (product #%2$d)%3$s, code %4$s%5$s', 'audio-watermark-woo' ),
                        $item_id, $product_id, $note_part,
                        $result['watermark_code'] ?? 'n/a', $cache_label
                    ) );

                } catch ( \Exception $e ) {
                    $err = $e->getMessage();
                    error_log( sprintf(
                        '[Audio WM] Watermark failed — order #%d, item #%d, product #%d, part "%s": %s',
                        $order_id, $item_id, $product_id, $part, $err
                    ) );

                    if ( function_exists( 'as_schedule_single_action' ) ) {
                        $delays  = [ 300, 1800, 7200 ];
                        $attempt = 1;
                        $order->add_order_note( sprintf(
                            /* translators: 1: item ID, 2: part label, 3: error, 4: delay */
                            __( '[Audio WM] Failed for item #%1$d%2$s: %3$s. Retry #4 in %4$d min.', 'audio-watermark-woo' ),
                            $item_id, '' !== $part ? " [{$part}]" : '', $err, (int) ( $delays[0] / 60 )
                        ) );
                        as_schedule_single_action(
                            time() + $delays[0],
                            'audio_wm_retry_watermark',
                            [ $order_id, $item_id, $master_key, $part, $attempt ]
                        );
                    } else {
                        $order->add_order_note( sprintf(
                            /* translators: 1: item ID, 2: part label, 3: error */
                            __( '[Audio WM] Failed for item #%1$d%2$s: %3$s. Please retry manually.', 'audio-watermark-woo' ),
                            $item_id, '' !== $part ? " [{$part}]" : '', $err
                        ) );
                    }
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
    public function retry_watermark( int $order_id, int $item_id, string $master_key, string $part, int $attempt ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            error_log( "[Audio WM] retry_watermark: could not load order #{$order_id}" );
            return;
        }

        try {
            $payload = [
                'master_key' => $master_key,
                'order_id'   => $order_id,
                'item_id'    => $item_id,
            ];
            if ( '' !== $part ) {
                $payload['part'] = $part;
            }
            $result = $this->call_service( '/watermark', $payload );

            // On success: append this master key to the item's done-keys list.
            $item = $order->get_item( $item_id );
            if ( $item instanceof WC_Order_Item_Product ) {
                $done_keys   = $this->get_item_done_keys( $item );
                $done_keys[] = $master_key;
                $item->update_meta_data( '_audio_wm_master_keys', wp_json_encode( array_unique( $done_keys ) ) );
                $item->save();
            }

            // Ensure the order-level flag is set so download buttons appear.
            if ( ! $order->get_meta( '_watermark_code' ) ) {
                $order->update_meta_data( '_watermark_code', $order_id );
                $order->save_meta_data();
            }

            $note_part = '' !== $part ? " [{$part}]" : '';
            $order->add_order_note( sprintf(
                /* translators: 1: retry attempt, 2: item ID, 3: part label, 4: watermark code */
                __( '[Audio WM] Retry #%1$d succeeded — item #%2$d%3$s watermarked (code %4$s).', 'audio-watermark-woo' ),
                $attempt, $item_id, $note_part, $result['watermark_code'] ?? 'n/a'
            ) );

        } catch ( \Exception $e ) {
            $err    = $e->getMessage();
            $delays = [ 300, 1800, 7200 ];
            $next   = $attempt + 1;

            error_log( sprintf(
                '[Audio WM] Retry #%d failed — order #%d, item #%d, part "%s": %s',
                $attempt, $order_id, $item_id, $part, $err
            ) );

            $note_part = '' !== $part ? " [{$part}]" : '';
            if ( $next <= self::MAX_RETRIES && function_exists( 'as_schedule_single_action' ) ) {
                $delay = $delays[ $attempt ] ?? 7200;
                $order->add_order_note( sprintf(
                    /* translators: 1: attempt, 2: item ID, 3: part, 4: error, 5: next, 6: delay */
                    __( '[Audio WM] Retry #%1$d failed for item #%2$d%3$s: %4$s. Retry #%5$d in %6$d min.', 'audio-watermark-woo' ),
                    $attempt, $item_id, $note_part, $err, $next, (int) ( $delay / 60 )
                ) );
                as_schedule_single_action(
                    time() + $delay,
                    'audio_wm_retry_watermark',
                    [ $order_id, $item_id, $master_key, $part, $next ]
                );
            } else {
                $order->add_order_note( sprintf(
                    /* translators: 1: attempt, 2: item ID, 3: part, 4: error */
                    __( '[Audio WM] All %1$d retries failed for item #%2$d%3$s: %4$s. Manual action required.', 'audio-watermark-woo' ),
                    $attempt, $item_id, $note_part, $err
                ) );
            }
        }
    }

    /**
     * Return the ordered list of master S3 keys for a product.
     * New format: _audio_wm_s3_keys (JSON array). Falls back to legacy _audio_wm_s3_key.
     *
     * @param int $product_id
     * @return string[]
     */
    private function get_product_master_keys( int $product_id ): array {
        $json = get_post_meta( $product_id, '_audio_wm_s3_keys', true );
        if ( $json ) {
            $keys = json_decode( $json, true );
            if ( is_array( $keys ) && ! empty( $keys ) ) {
                return array_values( array_filter( $keys, 'is_string' ) );
            }
        }
        $legacy = get_post_meta( $product_id, '_audio_wm_s3_key', true );
        return $legacy ? [ $legacy ] : [];
    }

    /**
     * Return the list of master keys that have already been watermarked for this item.
     * New format: _audio_wm_master_keys (JSON array). Falls back to legacy _audio_wm_master_key.
     *
     * @param WC_Order_Item_Product $item
     * @return string[]
     */
    private function get_item_done_keys( WC_Order_Item_Product $item ): array {
        $json = $item->get_meta( '_audio_wm_master_keys' );
        if ( $json ) {
            $keys = json_decode( $json, true );
            if ( is_array( $keys ) ) {
                return array_values( array_filter( $keys, 'is_string' ) );
            }
        }
        $legacy = $item->get_meta( '_audio_wm_master_key' );
        return $legacy ? [ $legacy ] : [];
    }

    /**
     * Derive the `part` identifier for a master key: the filename without its extension.
     * e.g. "masters/123/chapter-01.wav" → "chapter-01"
     *
     * @param string $master_key
     * @return string
     */
    private function part_for( string $master_key ): string {
        $base = basename( $master_key );
        $dot  = strrpos( $base, '.' );
        return sanitize_file_name( $dot !== false ? substr( $base, 0, $dot ) : $base );
    }

    /**
     * Add "Re-watermark audiobook(s)" to the WC Admin order actions dropdown.
     *
     * @param array $actions Existing order actions.
     * @return array
     */
    public function add_rewatermark_action( array $actions ): array {
        $actions['audio_wm_rewatermark'] = __( 'Re-watermark audiobook(s)', 'audio-watermark-woo' );
        return $actions;
    }

    /**
     * Handle the manual re-watermark order action triggered from WC Admin.
     *
     * Clears the per-item idempotency guard (_audio_wm_master_key) and the
     * order-level _watermark_code flag so process_order() runs a fresh pass.
     * Use this to recover after all automatic retries have been exhausted.
     *
     * @param \WC_Order $order The order being acted upon.
     */
    public function rewatermark_order( \WC_Order $order ): void {
        foreach ( $order->get_items() as $item ) {
            if ( ! ( $item instanceof WC_Order_Item_Product ) ) {
                continue;
            }
            $item->delete_meta_data( '_audio_wm_master_key' );  // legacy
            $item->delete_meta_data( '_audio_wm_master_keys' ); // new
            $item->save();
        }
        $order->delete_meta_data( '_watermark_code' );
        $order->save();

        $order->add_order_note(
            __( '[Audio WM] Manual re-watermark triggered by admin.', 'audio-watermark-woo' )
        );

        $this->process_order( $order->get_id() );
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
            // The Lambda returns its reason under an "error" key; API Gateway and
            // other layers may use "message". Surface whichever is present.
            $detail  = '';
            $decoded = json_decode( $raw_body, true );
            if ( is_array( $decoded ) ) {
                if ( isset( $decoded['error'] ) ) {
                    $detail = ' — ' . $decoded['error'];
                } elseif ( isset( $decoded['message'] ) ) {
                    $detail = ' — ' . $decoded['message'];
                }
            } elseif ( '' !== trim( (string) $raw_body ) ) {
                $detail = ' — ' . substr( trim( (string) $raw_body ), 0, 200 );
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
