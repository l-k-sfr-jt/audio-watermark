<?php
/**
 * "Audiobook Download" WooCommerce email.
 *
 * Sent to the buyer when an order with watermark-enabled items reaches a
 * downloadable state (processing or completed). The email carries one signed,
 * time-limited download link per audio file/part, plus a durable "request a new
 * link" link the customer can use after the download links expire.
 *
 * Registered via the woocommerce_email_classes filter (see audio-watermark-woo.php)
 * so it appears under WooCommerce > Settings > Emails, where the admin can edit the
 * subject/heading and enable/disable it.
 *
 * The download/resend URLs are built by Audio_WM_Download_Handler (HMAC over the
 * order key); this class only assembles and sends them.
 *
 * @package Audio_Watermark_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WC_Email' ) ) {
    return;
}

class Audio_WM_Email_Download extends WC_Email {

    public function __construct() {
        $this->id             = 'audio_wm_download';
        $this->title          = __( 'Audiobook download', 'audio-watermark-woo' );
        $this->description    = __( 'Sent to the customer with secure download links for their watermarked audiobook files.', 'audio-watermark-woo' );
        $this->customer_email = true;

        // Namespaced under audio-watermark-woo/ so a theme override lives at
        // yourtheme/woocommerce/audio-watermark-woo/emails/… and never clashes
        // with another plugin's emails/audiobook-download.php.
        $this->template_html  = 'audio-watermark-woo/emails/audiobook-download.php';
        $this->template_plain = 'audio-watermark-woo/emails/plain/audiobook-download.php';
        $this->template_base  = AUDIO_WM_PLUGIN_DIR . 'templates/';

        $this->placeholders = [
            '{order_number}' => '',
            '{order_date}'   => '',
        ];

        // Deliver as soon as payment is confirmed (processing) and also on completed,
        // mirroring Audio_WM_Order_Handler. Both hooks point at maybe_trigger(), which
        // sends only once per order (guarded by _audio_wm_email_sent) so a later
        // processing→completed transition does not email the customer twice.
        add_action( 'woocommerce_order_status_processing_notification', [ $this, 'maybe_trigger' ], 10, 2 );
        add_action( 'woocommerce_order_status_completed_notification', [ $this, 'maybe_trigger' ], 10, 2 );

        parent::__construct();
    }

    /**
     * Default subject (admin-overridable in WC settings).
     */
    public function get_default_subject(): string {
        return __( 'Your audiobook download for order {order_number}', 'audio-watermark-woo' );
    }

    /**
     * Default heading (admin-overridable in WC settings).
     */
    public function get_default_heading(): string {
        return __( 'Your audiobook is ready', 'audio-watermark-woo' );
    }

    /**
     * Notification-hook entry point: send the delivery email at most once per order.
     *
     * Hooked to both the processing and completed notifications; the
     * _audio_wm_email_sent guard ensures a single automatic delivery even when an
     * order transitions processing → completed. The self-service resend path calls
     * trigger() directly (bypassing this guard) so it always re-sends.
     *
     * @param int           $order_id Order ID.
     * @param WC_Order|null $order    Order object (passed by WC notification hooks).
     */
    public function maybe_trigger( $order_id, $order = null ): void {
        if ( $order_id && ! is_a( $order, 'WC_Order' ) ) {
            $order = wc_get_order( $order_id );
        }
        if ( ! is_a( $order, 'WC_Order' ) ) {
            return;
        }
        if ( $order->get_meta( '_audio_wm_email_sent' ) ) {
            return;
        }

        $this->trigger( $order_id, $order );

        $order->update_meta_data( '_audio_wm_email_sent', time() );
        $order->save_meta_data();
    }

    /**
     * Trigger the email for an order (unconditional — used by maybe_trigger() and
     * by the self-service resend flow).
     *
     * @param int           $order_id Order ID.
     * @param WC_Order|null $order    Order object (passed by WC notification hooks).
     */
    public function trigger( $order_id, $order = null ): void {
        $this->setup_locale();

        if ( $order_id && ! is_a( $order, 'WC_Order' ) ) {
            $order = wc_get_order( $order_id );
        }

        if ( is_a( $order, 'WC_Order' ) ) {
            $this->object    = $order;
            $this->recipient = $order->get_billing_email();

            $this->placeholders['{order_number}'] = $order->get_order_number();
            $this->placeholders['{order_date}']   = wc_format_datetime( $order->get_date_created() );
        }

        // Only send if enabled, we have a recipient, and the order has audio links.
        if ( $this->is_enabled() && $this->get_recipient() && $this->has_audio_links( $order ) ) {
            $this->send(
                $this->get_recipient(),
                $this->get_subject(),
                $this->get_content(),
                $this->get_headers(),
                $this->get_attachments()
            );
        }

        $this->restore_locale();
    }

    /**
     * Public entry point used by the "request a new link" resend flow.
     *
     * @param WC_Order $order
     */
    public static function trigger_for_order( WC_Order $order ): void {
        $mailer = WC()->mailer();
        $emails = $mailer->get_emails();
        if ( isset( $emails['Audio_WM_Email_Download'] ) ) {
            $emails['Audio_WM_Email_Download']->trigger( $order->get_id(), $order );
        }
    }

    /**
     * Build the list of download links for the order: one per watermark-enabled
     * file/part. Returns [ [ 'label' => …, 'url' => … ], … ].
     *
     * @param WC_Order|null $order
     * @return array
     */
    protected function get_links( $order ): array {
        $links = [];
        if ( ! is_a( $order, 'WC_Order' ) ) {
            return $links;
        }

        foreach ( $order->get_items() as $item_id => $item ) {
            if ( ! ( $item instanceof WC_Order_Item_Product ) ) {
                continue;
            }
            $product_id = (int) $item->get_product_id();
            if ( 'yes' !== get_post_meta( $product_id, '_audio_wm_enabled', true ) ) {
                continue;
            }

            $keys = $this->product_master_keys( $product_id );
            if ( empty( $keys ) ) {
                continue;
            }

            $multi = count( $keys ) > 1;
            foreach ( $keys as $key ) {
                $stem  = Audio_WM_Download_Handler::stem_of( $key );
                $part  = $multi ? $stem : '';
                $label = $multi
                    ? sprintf(
                        /* translators: 1: product name, 2: file/part name */
                        __( '%1$s — %2$s', 'audio-watermark-woo' ),
                        $item->get_name(),
                        $stem
                      )
                    : $item->get_name();

                $links[] = [
                    'label' => $label,
                    'url'   => Audio_WM_Download_Handler::download_link( $order, (int) $item_id, $part ),
                ];
            }
        }

        return $links;
    }

    /** Whether the order has at least one audio download link. */
    protected function has_audio_links( $order ): bool {
        return ! empty( $this->get_links( $order ) );
    }

    /**
     * Read a product's configured master keys (new JSON array; legacy single).
     *
     * @param int $product_id
     * @return string[]
     */
    private function product_master_keys( int $product_id ): array {
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
     * HTML body.
     */
    public function get_content_html(): string {
        return wc_get_template_html(
            $this->template_html,
            [
                'order'         => $this->object,
                'links'         => $this->get_links( $this->object ),
                'resend_url'    => is_a( $this->object, 'WC_Order' ) ? Audio_WM_Download_Handler::resend_link( $this->object ) : '',
                'email_heading' => $this->get_heading(),
                'sent_to_admin' => false,
                'plain_text'    => false,
                'email'         => $this,
            ],
            '',
            $this->template_base
        );
    }

    /**
     * Plain-text body.
     */
    public function get_content_plain(): string {
        return wc_get_template_html(
            $this->template_plain,
            [
                'order'         => $this->object,
                'links'         => $this->get_links( $this->object ),
                'resend_url'    => is_a( $this->object, 'WC_Order' ) ? Audio_WM_Download_Handler::resend_link( $this->object ) : '',
                'email_heading' => $this->get_heading(),
                'sent_to_admin' => false,
                'plain_text'    => true,
                'email'         => $this,
            ],
            '',
            $this->template_base
        );
    }
}
