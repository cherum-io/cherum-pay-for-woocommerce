<?php
/**
 * Plugin Name:       Cherum Pay for WooCommerce
 * Plugin URI:        https://cherum.io/woocommerce
 * Description:       Accept stablecoin payments in your WooCommerce store through Cherum Pay. The buyer picks the coin and the network; you get paid in the asset you chose.
 * Version:           1.3.2
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

define( 'CHERUM_PAY_VERSION', '1.3.2' );
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

		/* Translations. Without this call the bundled .po/.mo never load on a
		   self-installed copy — "Russian included" was factually untrue in
		   1.1.0 and the settings stayed English for everyone. */
		load_plugin_textdomain(
			'cherum-pay-for-woocommerce',
			false,
			dirname( plugin_basename( CHERUM_PAY_FILE ) ) . '/languages'
		);

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
		wp_add_privacy_policy_content(
			'Cherum Pay for WooCommerce',
			wp_kses_post( __( 'When a customer chooses to pay with Cherum Pay, the order total, the store currency, the order number and a short line naming the items are sent to Cherum (https://cherum.io) to create a payment invoice. No customer name, e-mail or postal address is sent. Cherum\'s privacy policy: https://cherum.io/legal/privacy', 'cherum-pay-for-woocommerce' ) )
		);
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
