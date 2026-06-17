<?php
/**
 * Audiobook download email (plain text).
 *
 * Override by copying to yourtheme/woocommerce/audio-watermark-woo/emails/plain/audiobook-download.php
 *
 * @var WC_Order $order
 * @var array    $links       Array of [ 'label' => string, 'url' => string ].
 * @var string   $resend_url  Durable "request a new link" URL.
 * @var string   $email_heading
 *
 * @package Audio_Watermark_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

echo "= " . esc_html( wp_strip_all_tags( $email_heading ) ) . " =\n\n";

echo esc_html__( 'Thank you for your purchase! Your watermarked audiobook files are ready to download using the secure links below.', 'audio-watermark-woo' ) . "\n\n";

if ( ! empty( $links ) ) {
    foreach ( $links as $link ) {
        echo '- ' . esc_html( $link['label'] ) . ":\n";
        echo '  ' . esc_url_raw( $link['url'] ) . "\n\n";
    }
}

printf(
    /* translators: %d: number of days the links remain valid */
    esc_html__( 'These download links stay active for %d days. After that, request a fresh one using the link below — your files are always regenerated on demand.', 'audio-watermark-woo' ),
    (int) ( Audio_WM_Download_Handler::LINK_TTL / DAY_IN_SECONDS )
);
echo "\n\n";

if ( ! empty( $resend_url ) ) {
    echo esc_html__( 'Request a new download link:', 'audio-watermark-woo' ) . "\n";
    echo esc_url_raw( $resend_url ) . "\n\n";
}

echo "\n----------------------------------------\n\n";
echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
