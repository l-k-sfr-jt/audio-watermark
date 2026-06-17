<?php
/**
 * Audiobook download email (HTML).
 *
 * Override by copying to yourtheme/woocommerce/audio-watermark-woo/emails/audiobook-download.php
 *
 * @var WC_Order $order
 * @var array    $links       Array of [ 'label' => string, 'url' => string ].
 * @var string   $resend_url  Durable "request a new link" URL.
 * @var string   $email_heading
 * @var WC_Email $email
 *
 * @package Audio_Watermark_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/*
 * @hooked WC_Emails::email_header() Output the email header
 */
do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<p><?php esc_html_e( 'Thank you for your purchase! Your watermarked audiobook files are ready to download using the secure links below.', 'audio-watermark-woo' ); ?></p>

<?php if ( ! empty( $links ) ) : ?>
    <table cellspacing="0" cellpadding="6" style="width:100%;margin-bottom:24px;" border="0">
        <tbody>
        <?php foreach ( $links as $link ) : ?>
            <tr>
                <td style="padding:8px 0;">
                    <a href="<?php echo esc_url( $link['url'] ); ?>"
                       style="display:inline-block;padding:10px 18px;background:#2b6cb0;color:#ffffff;text-decoration:none;border-radius:4px;font-weight:bold;">
                        <?php
                        printf(
                            /* translators: %s: file/product label */
                            esc_html__( 'Download: %s', 'audio-watermark-woo' ),
                            esc_html( $link['label'] )
                        );
                        ?>
                    </a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<p style="font-size:13px;color:#666;">
    <?php
    printf(
        /* translators: %d: number of days the links remain valid */
        esc_html__( 'These download links stay active for %d days. After that, request a fresh one below — your files are always regenerated on demand.', 'audio-watermark-woo' ),
        (int) ( Audio_WM_Download_Handler::LINK_TTL / DAY_IN_SECONDS )
    );
    ?>
</p>

<?php if ( ! empty( $resend_url ) ) : ?>
    <p>
        <a href="<?php echo esc_url( $resend_url ); ?>">
            <?php esc_html_e( 'Request a new download link', 'audio-watermark-woo' ); ?>
        </a>
    </p>
<?php endif; ?>

<?php
/*
 * @hooked WC_Emails::email_footer() Output the email footer
 */
do_action( 'woocommerce_email_footer', $email );
