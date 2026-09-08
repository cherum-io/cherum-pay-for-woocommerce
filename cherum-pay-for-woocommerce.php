<?php
/**
 * Plugin Name:       Cherum Pay for WooCommerce
 * Plugin URI:        https://cherum.io/woocommerce
 * Description:       Accept stablecoin payments in your WooCommerce store through Cherum Pay. The buyer picks the coin and the network; you get paid in the asset you chose.
 * Version:           1.3.6
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * WC requires at least: 9.0
 * WC tested up to:      11.0
 * Author:            Cherum
 * Author URI:        https://cherum.io
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       cherum-pay-for-woocommerce
 * Domain Path:       /languages
 *
 * This plugin talks to a third-party service (Cherum Pay, https://cherum.io).
 * Nothing leaves the store until a customer chooses this payment method; what
 * is sent then is listed in readme.txt under "External services".
 */

defined( 'ABSPATH' ) || exit;

define( 'CHERUM_PAY_VERSION', '1.3.6' );
define( 'CHERUM_PAY_FILE', __FILE__ );
define( 'CHERUM_PAY_PATH', plugin_dir_path( __FILE__ ) );
define( 'CHERUM_PAY_URL', plugin_dir_url( __FILE__ ) );

/**
 * WooCommerce may be absent or too old. Saying so in the admin beats a fatal
 * error on someone else's shop: a payment plugin that white-screens the store
 * is worse than a payment plugin that does not load.
 */
add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-error"><p>'
						. esc_html__( 'Cherum Pay needs WooCommerce to be installed and active.', 'cherum-pay-for-woocommerce' )
						. '</p></div>';
				}
			);
			return;
		}

		require_once CHERUM_PAY_PATH . 'includes/class-cherum-pay-api.php';
		require_once CHERUM_PAY_PATH . 'includes/class-cherum-pay-gateway.php';
		require_once CHERUM_PAY_PATH . 'includes/class-cherum-pay-webhook.php';
		require_once CHERUM_PAY_PATH . 'includes/class-cherum-pay-order-box.php';

		add_filter(
			'woocommerce_payment_gateways',
			static function ( $gateways ) {
				$gateways[] = 'Cherum_Pay_Gateway';
				return $gateways;
			}
		);

		Cherum_Pay_Webhook::init();
		if ( is_admin() ) {
			Cherum_Pay_Order_Box::init();
		}

		/* DELETING THE REFUND LINE IS A DECISION, AND IT REACHES CHERUM (1.3.6).
		 *
		 * The note this plugin writes when a refund dies tells the shop owner
		 * to delete the line WooCommerce recorded — and until now that was the
		 * end of it: the order stayed On hold for ever, and if the Cherum
		 * refund was still open the payout could yet reach the buyer with no
		 * record of a refund anywhere in the store. Deleting the line now
		 * calls the open refund off and puts the order back where the failed
		 * refund took it from.
		 *
		 * Registered outside is_admin(): WooCommerce fires this from its AJAX
		 * handler (admin) but a refund can also be deleted from WP-CLI, and
		 * money must not depend on which door the request came through. */
		add_action( 'woocommerce_refund_deleted', array( 'Cherum_Pay_Gateway', 'on_refund_deleted' ), 10, 2 );

		/* THE CRYPTO DISCOUNT, HOOKED WHERE IT ACTUALLY FIRES (1.3.3).
		 *
		 * Until now this lived in the gateway's constructor, and on the classic
		 * checkout the gateway is not built yet at the moment the cart is
		 * totalled: WC_Payment_Gateways is created lazily on the first call to
		 * WC()->payment_gateways(), while the checkout shortcode,
		 * ?wc-ajax=update_order_review and — the expensive one —
		 * ?wc-ajax=checkout all call calculate_totals() before anything asks for
		 * a gateway. The fee was therefore never added on a classic checkout,
		 * and the order was written at the full price with no sign that a
		 * discount had been set. Registering here, on plugins_loaded, needs no
		 * object at all; the handler reads the setting from the option and
		 * re-checks the same availability rules the gateway does.
		 *
		 * The block checkout kept working either way; it is covered by the same
		 * hook now instead of by a second one. */
		add_action( 'woocommerce_cart_calculate_fees', array( 'Cherum_Pay_Gateway', 'add_crypto_discount' ) );

		/* AND THE CLASSIC CHECKOUT HAS TO BE TOLD TO RE-TOTAL (1.3.5).
		 *
		 * Since 1.3.3 the fee above appears on the classic checkout — but the
		 * classic checkout never refreshes its totals when the buyer changes
		 * the payment method (WooCommerce binds only its own
		 * payment_method_selected there), so the figure on screen froze at
		 * whichever method was selected when the page last updated. Both
		 * directions were seen on the live demo: the order button pressed under
		 * "Total $54.00" wrote an order of $52.92, and a buyer who picked crypto
		 * and then a cheque was left looking at "−$1.08 / Total $52.92" while
		 * the order was written at $54.00. The script below asks WooCommerce for
		 * its own update on that change; hooked on the classic payment template
		 * because is_checkout() is true on a block checkout too.
		 *
		 * The block checkout never needed this — it re-totals through the Store
		 * API on every change and reports the choice itself (see the update
		 * callback at the bottom of this file). */
		add_action( 'woocommerce_review_order_before_payment', array( 'Cherum_Pay_Gateway', 'enqueue_classic_checkout' ) );

		/* Translations are not bundled. Since WordPress 4.6 the core loads a
		   plugin's translations just in time from translate.wordpress.org, so
		   neither a languages/ directory with .po/.mo files nor a
		   textdomain-loading call is needed for a directory-hosted plugin
		   (both were flagged by the WordPress.org review of 5 Sep 2026). The
		   template languages/cherum-pay-for-woocommerce.pot stays for translators. */

		/* SAFETY-NET POLL. A webhook can be lost (secret unset, host firewall,
		   downtime) and 1.1.0 left such orders pending forever. A lost
		   notification now costs minutes, not a support ticket: a cron pass
		   asks Cherum for the status of stale pending orders and applies it
		   through the same code path the webhook uses. */
		add_action( 'cherum_pay_poll_pending', array( 'Cherum_Pay_Gateway', 'poll_pending_orders' ) );
		add_action(
			'init',
			static function () {
				if ( ! wp_next_scheduled( 'cherum_pay_poll_pending' ) ) {
					wp_schedule_event( time() + 300, 'cherum_pay_15min', 'cherum_pay_poll_pending' );
				}
			}
		);
	}
);

add_filter(
	'cron_schedules', // phpcs:ignore WordPress.WP.CronInterval
	static function ( $schedules ) {
		$schedules['cherum_pay_15min'] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 15 minutes (Cherum Pay safety-net poll)', 'cherum-pay-for-woocommerce' ),
		);
		return $schedules;
	}
);

/**
 * Text for the site's privacy policy page (Settings → Privacy → Policy guide),
 * so the shop owner does not have to work out what the plugin shares.
 */
add_action(
	'admin_init',
	static function () {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		/* THE PARAGRAPH FOLLOWS THE SETTING (1.3.3). It used to say, flatly,
		   that no e-mail address is sent — while the "Receipt from Cherum"
		   setting right next door sends exactly that. A privacy notice that is
		   true only on the default settings is worse than none: the shop owner
		   publishes it and is then wrong about their own store. */
		$settings = get_option( 'woocommerce_cherum_pay_settings', array() );
		$sends_email = is_array( $settings ) && isset( $settings['send_email'] ) && 'yes' === $settings['send_email'];
		$text = __( 'When a customer chooses to pay with Cherum Pay, the order total, the store currency, the order number and a short line naming the items are sent to Cherum (https://cherum.io) to create a payment invoice. No customer name or postal address is sent.', 'cherum-pay-for-woocommerce' )
			. ' '
			. ( $sends_email
				? __( 'Because the "Receipt from Cherum" setting is on, the customer\'s e-mail address is sent as well, and is used only to send them a receipt for the payment.', 'cherum-pay-for-woocommerce' )
				: __( 'The customer\'s e-mail address is not sent, unless you turn on the "Receipt from Cherum" setting in the payment method options.', 'cherum-pay-for-woocommerce' ) )
			. ' '
			. __( 'Cherum\'s privacy policy: https://cherum.io/legal/privacy', 'cherum-pay-for-woocommerce' );
		wp_add_privacy_policy_content( 'Cherum Pay for WooCommerce', wp_kses_post( $text ) );
	}
);

register_deactivation_hook(
	__FILE__,
	static function () {
		wp_clear_scheduled_hook( 'cherum_pay_poll_pending' );
	}
);

/**
 * Declare compatibility with the WooCommerce features this plugin was built
 * against.
 *
 * WooCommerce only reads these declarations from plugins that carry a
 * "WC tested up to" header, which is why that header is in the block above:
 * without it both declarations below are inert.
 *
 * What an undeclared feature actually costs — checked against WooCommerce
 * 11.0.1, not assumed: High-Performance Order Storage warns the shop owner on
 * the plugins screen and blocks them from turning the feature on; the block
 * checkout lists the plugin as incompatible in the editor and in an admin
 * notice. Neither hides the gateway. Declaring is still right — the shop owner
 * should not have to wonder — but it is not the thing that keeps the method
 * visible.
 */
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				CHERUM_PAY_FILE,
				true
			);
			/* Tells the store owner, in the editor and on the plugins screen,
			   that this gateway works with the block checkout. What actually
			   puts the method ON that checkout is the registration at the
			   bottom of this file, not this declaration. */
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'cart_checkout_blocks',
				CHERUM_PAY_FILE,
				true
			);
		}
	}
);

/**
 * Register the payment method with the block-based Cart and Checkout.
 *
 * Runs on its own hook, which only fires when WooCommerce Blocks is present:
 * on a store still using the classic checkout nothing here loads at all.
 */
add_action(
	'woocommerce_blocks_loaded',
	static function () {
		if ( ! class_exists( \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType::class ) ) {
			return;
		}
		require_once CHERUM_PAY_PATH . 'includes/class-cherum-pay-blocks.php';

		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			static function ( $registry ) {
				$registry->register( new Cherum_Pay_Blocks() );
			}
		);

		/* THE DISCOUNT ON THE BLOCK CHECKOUT. The classic checkout writes the
		   chosen payment method into the session on every change; the block
		   checkout does not — it keeps the choice in the browser until the
		   order is placed. So the cart fee behind "Discount for paying in
		   crypto" never appeared on a block store (found on the first live
		   buy, 02.09): the buyer chose us and saw no saving. The block script
		   now reports the choice through the Store API extension channel, and
		   this callback puts it where the fee hook already looks. */
		if ( function_exists( 'woocommerce_store_api_register_update_callback' ) ) {
			woocommerce_store_api_register_update_callback(
				array(
					'namespace' => 'cherum-pay',
					'callback'  => static function ( $data ) {
						if ( ! function_exists( 'WC' ) || ! WC()->session ) {
							return;
						}
						$chosen = ! empty( $data['chosen'] );
						$current = (string) WC()->session->get( 'chosen_payment_method' );
						if ( $chosen ) {
							WC()->session->set( 'chosen_payment_method', 'cherum_pay' );
						} elseif ( 'cherum_pay' === $current ) {
							WC()->session->set( 'chosen_payment_method', '' );
						}
					},
				)
			);
		}
	}
);
