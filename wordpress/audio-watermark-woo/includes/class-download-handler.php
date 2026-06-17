<?php
/**
 * Download handler for Audio Watermark.
 *
 * Serves watermarked audiobook files to buyers through three paths:
 *
 *  1. Logged-in My Account / order-details page — nonce-authenticated buttons
 *     (woocommerce_order_details_after_order_table → add_download_buttons).
 *  2. Guest order-received / thank-you page — signed-token buttons
 *     (woocommerce_thankyou → render_thankyou_downloads).
 *  3. Email download links — signed tokens carried in the order email
 *     (see Audio_WM_Email_Download).
 *
 * Because the shop checks customers out as guests (no WP account), email/thank-you
 * links cannot rely on a logged-in session or a WP nonce (nonces don't survive in
 * email). Instead they carry an HMAC signature computed over the WooCommerce order
 * key ($order->get_order_key()) — a per-order secret WooCommerce already issues for
 * guest order access. The raw key never appears in any URL; only the signature does.
 *
 * Master-key resolution: the download endpoint mints the watermarked MP3 on demand
 * via the idempotent /watermark service call, so a link works even before
 * process_order() has populated the order item's _audio_wm_master_keys meta. When
 * item meta is empty we fall back to the product's configured _audio_wm_s3_keys.
 *
 * Security model:
 *  - Two auth paths: (a) logged-in owner + per-order nonce; (b) valid HMAC token.
 *  - Download tokens expire after LINK_TTL (30 days — matches the S3 buyer-copy
 *    lifecycle). Expired links render a friendly page with a "request a new link"
 *    button that re-emails a fresh link (throttled to once per hour).
 *  - Status guard blocks refunded / cancelled / failed orders.
 *  - download_url from the service is validated to an amazonaws.com host before
 *    redirecting, to prevent open-redirect abuse.
 *
 * Note on `woocommerce_order_details_after_order_table`: this hook also fires inside
 * WooCommerce HTML order emails. The get_current_user_id() === 0 check inside
 * add_download_buttons() suppresses output in that context (no user is logged in
 * when WC renders an email), so no link leaks into another email's body.
 *
 * @package Audio_Watermark_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class Audio_WM_Download_Handler {

    /**
     * Lifetime of an emailed/thank-you download token, in seconds.
     * Set to 30 days to match the S3 ExpireBuyerCopies lifecycle in template.yaml:
     * within the window the cached MP3 exists (fast path); at expiry the link and
     * the S3 copy lapse together and the customer requests a fresh link.
     */
    const LINK_TTL = 30 * DAY_IN_SECONDS;

    /** Minimum interval between "request a new link" resends, per order. */
    const RESEND_THROTTLE = HOUR_IN_SECONDS;

    public function __construct() {
        // My Account / order-details (logged-in, nonce auth).
        add_action( 'woocommerce_order_details_after_order_table', [ $this, 'add_download_buttons' ] );

        // Order-received / thank-you page (guest-capable, signed-token buttons).
        add_action( 'woocommerce_thankyou', [ $this, 'render_thankyou_downloads' ] );

        // Download endpoint — available to logged-in users AND guests (token auth).
        add_action( 'wp_ajax_audio_wm_download',        [ $this, 'handle_download' ] );
        add_action( 'wp_ajax_nopriv_audio_wm_download', [ $this, 'handle_download' ] );

        // Resend endpoint — re-emails a fresh download link (guest-capable).
        add_action( 'wp_ajax_audio_wm_resend',        [ $this, 'handle_resend' ] );
        add_action( 'wp_ajax_nopriv_audio_wm_resend', [ $this, 'handle_resend' ] );
    }

    /* ===================================================================== *
     *  Signed-link helpers (shared with Audio_WM_Email_Download)
     * ===================================================================== */

    /**
     * Build a time-limited, HMAC-signed download URL for a guest/email link.
     *
     * @param WC_Order $order   The order.
     * @param int      $item_id Order-item ID.
     * @param string   $part    Sanitized file stem, or '' for single-file items.
     * @return string admin-ajax.php URL with order_id, item_id, part, expires, sig.
     */
    public static function download_link( WC_Order $order, int $item_id, string $part ): string {
        $expires = time() + self::LINK_TTL;
        $args    = [
            'action'   => 'audio_wm_download',
            'order_id' => $order->get_id(),
            'item_id'  => $item_id,
            'expires'  => $expires,
            'sig'      => self::sign_download( $order, $item_id, $part, $expires ),
        ];
        if ( '' !== $part ) {
            $args['part'] = $part;
        }
        return add_query_arg( $args, admin_url( 'admin-ajax.php' ) );
    }

    /**
     * Build the durable (no-expiry) HMAC-signed "request a new link" URL.
     *
     * Safe even if leaked: it only triggers an email to the address stored on the
     * order, never to an attacker-supplied address, and is throttled server-side.
     *
     * @param WC_Order $order The order.
     * @return string admin-ajax.php URL with order_id, sig.
     */
    public static function resend_link( WC_Order $order ): string {
        return add_query_arg(
            [
                'action'   => 'audio_wm_resend',
                'order_id' => $order->get_id(),
                'sig'      => self::sign_resend( $order ),
            ],
            admin_url( 'admin-ajax.php' )
        );
    }

    /** Compute the download-link signature. */
    private static function sign_download( WC_Order $order, int $item_id, string $part, int $expires ): string {
        $data = 'dl|' . $order->get_id() . '|' . $item_id . '|' . $part . '|' . $expires;
        return hash_hmac( 'sha256', $data, (string) $order->get_order_key() );
    }

    /** Compute the resend-link signature. */
    private static function sign_resend( WC_Order $order ): string {
        $data = 'resend|' . $order->get_id();
        return hash_hmac( 'sha256', $data, (string) $order->get_order_key() );
    }

    /** Constant-time verification of a download signature. */
    public static function verify_download_sig( WC_Order $order, int $item_id, string $part, int $expires, string $sig ): bool {
        return hash_equals( self::sign_download( $order, $item_id, $part, $expires ), $sig );
    }

    /** Constant-time verification of a resend signature. */
    public static function verify_resend_sig( WC_Order $order, string $sig ): bool {
        return hash_equals( self::sign_resend( $order ), $sig );
    }

    /* ===================================================================== *
     *  Master-key resolution
     * ===================================================================== */

    /**
     * Filename stem (no extension) of an S3 key, sanitized for use as `part`.
     * e.g. "masters/123/chapter-01.wav" → "chapter-01".
     *
     * @param string $key
     * @return string
     */
    public static function stem_of( string $key ): string {
        $base = basename( $key );
        $dot  = strrpos( $base, '.' );
        return sanitize_file_name( false !== $dot ? substr( $base, 0, $dot ) : $base );
    }

    /**
     * Resolve the list of master S3 keys to offer for an order item.
     *
     * Precedence:
     *  1. Item's _audio_wm_master_keys (JSON array) — keys already watermarked.
     *  2. Legacy _audio_wm_master_key (single string).
     *  3. Product's _audio_wm_s3_keys (JSON array) — fallback for on-demand minting
     *     when process_order() hasn't run yet (the /watermark call is idempotent).
     *  4. Legacy product _audio_wm_s3_key (single string).
     *
     * @param WC_Order_Item_Product $item
     * @return string[]
     */
    private function resolve_keys_for_item( WC_Order_Item_Product $item ): array {
        $json = $item->get_meta( '_audio_wm_master_keys' );
        $keys = $json ? ( json_decode( $json, true ) ?: [] ) : [];

        if ( empty( $keys ) ) {
            $legacy = $item->get_meta( '_audio_wm_master_key' );
            if ( $legacy ) {
                $keys = [ $legacy ];
            }
        }

        if ( empty( $keys ) ) {
            $product_id = (int) $item->get_product_id();
            $pjson      = get_post_meta( $product_id, '_audio_wm_s3_keys', true );
            if ( $pjson ) {
                $pkeys = json_decode( $pjson, true );
                if ( is_array( $pkeys ) ) {
                    $keys = $pkeys;
                }
            }
            if ( empty( $keys ) ) {
                $legacy_p = get_post_meta( $product_id, '_audio_wm_s3_key', true );
                if ( $legacy_p ) {
                    $keys = [ $legacy_p ];
                }
            }
        }

        return array_values( array_filter( (array) $keys, 'is_string' ) );
    }

    /* ===================================================================== *
     *  Button rendering
     * ===================================================================== */

    /**
     * Build the per-file download button HTML fragments for an order.
     *
     * @param WC_Order $order The order.
     * @param string   $mode  'nonce' (logged-in My Account) or 'token' (guest links).
     * @return string[] Array of escaped <p><a>…</a></p> fragments.
     */
    private function build_button_sections( WC_Order $order, string $mode ): array {
        $order_id = $order->get_id();
        $nonce    = ( 'nonce' === $mode ) ? wp_create_nonce( 'audio_wm_download_' . $order_id ) : '';
        $sections = [];

        foreach ( $order->get_items() as $item_id => $item ) {
            if ( ! ( $item instanceof WC_Order_Item_Product ) ) {
                continue;
            }

            $product_id = (int) $item->get_product_id();
            if ( 'yes' !== get_post_meta( $product_id, '_audio_wm_enabled', true ) ) {
                continue;
            }

            $keys = $this->resolve_keys_for_item( $item );
            if ( empty( $keys ) ) {
                continue;
            }

            $multi = count( $keys ) > 1;

            foreach ( $keys as $key ) {
                $stem = self::stem_of( $key );
                $part = $multi ? $stem : '';

                if ( 'token' === $mode ) {
                    $url = self::download_link( $order, (int) $item_id, $part );
                } else {
                    $args = [
                        'action'   => 'audio_wm_download',
                        'order_id' => $order_id,
                        'item_id'  => $item_id,
                        '_wpnonce' => $nonce,
                    ];
                    if ( $multi ) {
                        $args['part'] = $stem;
                    }
                    $url = add_query_arg( $args, admin_url( 'admin-ajax.php' ) );
                }

                $label = $multi
                    ? sprintf(
                        /* translators: 1: product name, 2: file stem/part name */
                        __( 'Download: %1$s — %2$s', 'audio-watermark-woo' ),
                        $item->get_name(),
                        $stem
                      )
                    : sprintf(
                        /* translators: %s: product name */
                        __( 'Download: %s', 'audio-watermark-woo' ),
                        $item->get_name()
                      );

                $sections[] = sprintf(
                    '<p><a href="%s" class="button" target="_blank" rel="nofollow">%s</a></p>',
                    esc_url( $url ),
                    esc_html( $label )
                );
            }
        }

        return $sections;
    }

    /**
     * My Account / order-details page: nonce-authenticated download buttons.
     * Rendered only for the logged-in owner of the order (also suppresses output
     * when WC renders this hook inside an email, where no user is logged in).
     *
     * @param WC_Order $order The order being displayed.
     */
    public function add_download_buttons( WC_Order $order ): void {
        $current_user_id = get_current_user_id();
        if ( ! $current_user_id || $current_user_id !== (int) $order->get_customer_id() ) {
            return;
        }

        $sections = $this->build_button_sections( $order, 'nonce' );
        if ( empty( $sections ) ) {
            return;
        }

        $this->echo_downloads_section( $sections );
    }

    /**
     * Order-received / thank-you page: signed-token download buttons (guest-capable).
     *
     * @param int $order_id The order ID passed by woocommerce_thankyou.
     */
    public function render_thankyou_downloads( $order_id ): void {
        $order = wc_get_order( (int) $order_id );
        if ( ! $order ) {
            return;
        }

        // The logged-in owner already gets nonce buttons from add_download_buttons()
        // (woocommerce_order_details_after_order_table also fires on this page), so
        // skip here to avoid rendering the section twice. Guests fall through.
        $current_user_id = get_current_user_id();
        if ( $current_user_id && $current_user_id === (int) $order->get_customer_id() ) {
            return;
        }

        // Only show once payment has put the order into a downloadable state.
        if ( ! in_array( $order->get_status(), [ 'completed', 'processing' ], true ) ) {
            return;
        }

        $sections = $this->build_button_sections( $order, 'token' );
        if ( empty( $sections ) ) {
            return;
        }

        $this->echo_downloads_section( $sections );
    }

    /** Echo the wrapping <section> for a set of button fragments. */
    private function echo_downloads_section( array $sections ): void {
        echo '<section class="audio-wm-downloads" style="margin-top:1.5em;">';
        echo '<h2>' . esc_html__( 'Audiobook Downloads', 'audio-watermark-woo' ) . '</h2>';
        echo implode( '', $sections ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each fragment is already escaped in build_button_sections()
        echo '</section>';
    }

    /* ===================================================================== *
     *  Download endpoint
     * ===================================================================== */

    /**
     * AJAX handler: authenticate (nonce OR signed token), fetch a fresh presigned
     * URL from the watermark service, validate the URL domain, and redirect.
     *
     * Called via: admin-ajax.php?action=audio_wm_download&order_id=…&item_id=…
     *   - logged-in path: &_wpnonce=…
     *   - guest/email path: &expires=…&sig=…
     */
    public function handle_download(): void {
        $order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
        $item_id  = isset( $_GET['item_id'] )  ? absint( $_GET['item_id'] )  : 0;

        if ( ! $order_id || ! $item_id ) {
            wp_die( esc_html__( 'Invalid download request.', 'audio-watermark-woo' ), '', [ 'response' => 400 ] );
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_die( esc_html__( 'Order not found.', 'audio-watermark-woo' ), '', [ 'response' => 404 ] );
            return;
        }

        // Sanitize/validate `part` (used both for auth signing and key resolution).
        $part = isset( $_GET['part'] ) ? sanitize_file_name( wp_unslash( $_GET['part'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- auth handled below
        if ( '' !== $part && ! preg_match( '/^[A-Za-z0-9._\-]+$/', $part ) ) {
            wp_die( esc_html__( 'Invalid download request.', 'audio-watermark-woo' ), '', [ 'response' => 400 ] );
            return;
        }

        // ── Authenticate: signed token (guest/email) OR nonce + ownership ─────
        $has_token = isset( $_GET['sig'], $_GET['expires'] );

        if ( $has_token ) {
            $expires = absint( $_GET['expires'] );
            $sig     = sanitize_text_field( wp_unslash( $_GET['sig'] ) );

            if ( time() > $expires ) {
                // Expired: friendly page with a "request a new link" button.
                $this->render_expired_page( $order );
                return;
            }
            if ( ! self::verify_download_sig( $order, $item_id, $part, $expires, $sig ) ) {
                wp_die( esc_html__( 'This download link is invalid.', 'audio-watermark-woo' ), '', [ 'response' => 403 ] );
                return;
            }
        } else {
            // Logged-in path: per-order nonce + ownership.
            if ( ! check_admin_referer( 'audio_wm_download_' . $order_id ) ) {
                // check_admin_referer() dies on failure; this is belt-and-braces.
                wp_die( esc_html__( 'Security check failed. Please go back and try again.', 'audio-watermark-woo' ), '', [ 'response' => 403 ] );
                return;
            }
            $current_user_id = get_current_user_id();
            if ( ! $current_user_id || $current_user_id !== (int) $order->get_customer_id() ) {
                wp_die( esc_html__( 'You do not have permission to download this file.', 'audio-watermark-woo' ), '', [ 'response' => 403 ] );
                return;
            }
        }

        // ── Status guard ──────────────────────────────────────────────────────
        if ( ! in_array( $order->get_status(), [ 'completed', 'processing' ], true ) ) {
            wp_die( esc_html__( 'This order is no longer eligible for downloads.', 'audio-watermark-woo' ), '', [ 'response' => 403 ] );
            return;
        }

        // ── Resolve the item and which master key to serve ────────────────────
        $item = $order->get_item( $item_id );
        if ( ! ( $item instanceof WC_Order_Item_Product ) ) {
            wp_die( esc_html__( 'Invalid download request.', 'audio-watermark-woo' ), '', [ 'response' => 400 ] );
            return;
        }

        $keys = $this->resolve_keys_for_item( $item );
        if ( empty( $keys ) ) {
            wp_die( esc_html__( 'Could not generate download link. Please contact support.', 'audio-watermark-woo' ), '', [ 'response' => 404 ] );
            return;
        }

        $master_key = '';
        if ( '' !== $part ) {
            foreach ( $keys as $k ) {
                if ( self::stem_of( $k ) === $part ) {
                    $master_key = $k;
                    break;
                }
            }
        } else {
            $master_key = $keys[0];
        }

        if ( ! $master_key ) {
            wp_die( esc_html__( 'Could not generate download link. Please contact support.', 'audio-watermark-woo' ), '', [ 'response' => 404 ] );
            return;
        }

        // ── Call watermark service (idempotent — returns a fresh presigned URL) ──
        try {
            $payload = [
                'master_key' => $master_key,
                'order_id'   => $order_id,
                'item_id'    => $item_id,
            ];
            if ( '' !== $part ) {
                $payload['part'] = $part;
            }
            $result = Audio_WM_Order_Handler::call_service( '/watermark', $payload );
        } catch ( \Exception $e ) {
            error_log( "[Audio WM] Download failed — order #{$order_id}, item #{$item_id}: " . $e->getMessage() );
            wp_die( esc_html__( 'Could not generate download link. Please try again.', 'audio-watermark-woo' ), '', [ 'response' => 503 ] );
            return;
        }

        if ( empty( $result['download_url'] ) ) {
            error_log( "[Audio WM] Download failed — order #{$order_id}, item #{$item_id}: service returned no download_url" );
            wp_die( esc_html__( 'Could not generate download link. Please try again.', 'audio-watermark-woo' ), '', [ 'response' => 502 ] );
            return;
        }

        // ── Validate download_url domain (open-redirect guard) ────────────────
        $download_url = $result['download_url'];
        $host         = (string) wp_parse_url( $download_url, PHP_URL_HOST );
        if ( ! $host
            || 'https' !== wp_parse_url( $download_url, PHP_URL_SCHEME )
            || substr( $host, -strlen( '.amazonaws.com' ) ) !== '.amazonaws.com'
        ) {
            error_log( "[Audio WM] Rejected non-S3 download_url for order #{$order_id}: {$download_url}" );
            wp_die( esc_html__( 'Invalid download URL returned by service.', 'audio-watermark-woo' ), '', [ 'response' => 502 ] );
            return;
        }

        wp_redirect( $download_url, 302 );
        exit;
    }

    /* ===================================================================== *
     *  Resend endpoint
     * ===================================================================== */

    /**
     * AJAX handler: verify the durable resend signature, throttle, and re-email a
     * fresh set of download links to the address on the order.
     *
     * Called via: admin-ajax.php?action=audio_wm_resend&order_id=…&sig=…
     */
    public function handle_resend(): void {
        $order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
        $sig      = isset( $_GET['sig'] ) ? sanitize_text_field( wp_unslash( $_GET['sig'] ) ) : '';

        if ( ! $order_id || '' === $sig ) {
            wp_die( esc_html__( 'Invalid request.', 'audio-watermark-woo' ), '', [ 'response' => 400 ] );
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_die( esc_html__( 'Order not found.', 'audio-watermark-woo' ), '', [ 'response' => 404 ] );
            return;
        }

        if ( ! self::verify_resend_sig( $order, $sig ) ) {
            wp_die( esc_html__( 'This link is invalid.', 'audio-watermark-woo' ), '', [ 'response' => 403 ] );
            return;
        }

        if ( ! in_array( $order->get_status(), [ 'completed', 'processing' ], true ) ) {
            wp_die( esc_html__( 'This order is no longer eligible for downloads.', 'audio-watermark-woo' ), '', [ 'response' => 403 ] );
            return;
        }

        // ── Throttle: at most one resend per RESEND_THROTTLE per order ─────────
        $last = (int) $order->get_meta( '_audio_wm_last_resend' );
        if ( $last && ( time() - $last ) < self::RESEND_THROTTLE ) {
            wp_die(
                esc_html__( 'We already sent a download link recently — please check your inbox (including the spam folder). You can request another one a little later.', 'audio-watermark-woo' ),
                esc_html__( 'Please wait', 'audio-watermark-woo' ),
                [ 'response' => 429 ]
            );
            return;
        }

        // ── Send a fresh email ─────────────────────────────────────────────────
        if ( ! class_exists( 'Audio_WM_Email_Download' ) ) {
            require_once AUDIO_WM_PLUGIN_DIR . 'includes/class-email-download.php';
        }
        Audio_WM_Email_Download::trigger_for_order( $order );

        $order->update_meta_data( '_audio_wm_last_resend', time() );
        $order->save_meta_data();

        $masked = self::mask_email( (string) $order->get_billing_email() );
        wp_die(
            sprintf(
                '<p>%s</p>',
                esc_html(
                    sprintf(
                        /* translators: %s: partially masked email address */
                        __( 'A fresh download link has been sent to %s. Please check your inbox.', 'audio-watermark-woo' ),
                        $masked
                    )
                )
            ),
            esc_html__( 'Link sent', 'audio-watermark-woo' ),
            [ 'response' => 200 ]
        );
    }

    /* ===================================================================== *
     *  Friendly pages / helpers
     * ===================================================================== */

    /**
     * Render the "this link has expired" page with a resend button.
     *
     * @param WC_Order $order
     */
    private function render_expired_page( WC_Order $order ): void {
        $resend = self::resend_link( $order );
        wp_die(
            sprintf(
                '<p>%s</p><p><a href="%s" class="button">%s</a></p>',
                esc_html__( 'This download link has expired.', 'audio-watermark-woo' ),
                esc_url( $resend ),
                esc_html__( 'Email me a new link', 'audio-watermark-woo' )
            ),
            esc_html__( 'Link expired', 'audio-watermark-woo' ),
            [ 'response' => 410 ]
        );
    }

    /**
     * Partially mask an email for display: "buyer@example.com" → "b••••@example.com".
     *
     * @param string $email
     * @return string
     */
    private static function mask_email( string $email ): string {
        $at = strpos( $email, '@' );
        if ( false === $at || $at < 1 ) {
            return '•••';
        }
        $name   = substr( $email, 0, $at );
        $domain = substr( $email, $at );
        return substr( $name, 0, 1 ) . str_repeat( '•', max( 1, strlen( $name ) - 1 ) ) . $domain;
    }
}
