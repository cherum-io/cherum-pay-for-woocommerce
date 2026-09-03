<?php
/**
 * Cart & Checkout Blocks support.
 *
 * WooCommerce ships two checkouts. The classic one is a PHP template; the one
 * every store created since WooCommerce 8.3 gets by default is built from
 * blocks and rendered by JavaScript. A gateway that only implements the classic
 * side does not fail loudly on the block checkout — it simply is not there. The
 * shop owner enables Cherum Pay, sees it listed in the admin, and no customer
 * is ever offered it.
 *
 * That is the worst failure a payment plugin can have, so the block side is not
 * optional and not "phase two".
 *
 * The script below is written as plain JavaScript on purpose — no build step,
 * no bundler, no minified blob. It reads the same as it ships, which is what a
 * reviewer and a curious shop owner both deserve.
 *
 * @package Cherum_Pay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Registers Cherum Pay with the block-based Cart and Checkout.
 */
final class Cherum_Pay_Blocks extends AbstractPaymentMethodType {

	/**
	 * Must match the gateway id, or the block checkout cannot pair the two.
	 *
	 * @var string
	 */
	protected $name = 'cherum_pay';

	/**
	 * The gateway instance, loaded once and reused.
	 *
	 * @var WC_Payment_Gateway|null
	 */
	private $gateway = null;

	/**
	 * Read the gateway's own settings — the block checkout must show exactly
	 * what the shop owner configured, not a second copy of the defaults.
	 */
	public function initialize() {
		$this->settings = get_option( 'woocommerce_cherum_pay_settings', array() );
	}

	/**
	 * Whether to offer the method at all.
	 *
	 * Delegates to the gateway so there is ONE definition of "available".
	 * A second copy here would drift, and the drift would show as a method
	 * that appears on one checkout and not the other.
	 */
	public function is_active() {
		$gateway = $this->get_gateway();
		return $gateway && $gateway->is_available();
	}

	/**
	 * Register the front-end script.
	 *
	 * @return string[] Handles the block checkout should enqueue.
	 */
	public function get_payment_method_script_handles() {
		$handle = 'cherum-pay-blocks';
		$path   = 'assets/js/blocks.js';
		$file   = CHERUM_PAY_PATH . $path;

		wp_register_script(
			$handle,
			CHERUM_PAY_URL . $path,
			/* `wc-settings` is listed even though `wc-blocks-registry` happens to
			   pull it in today: the script reads window.wc.wcSettings directly,
			   and leaning on somebody else's transitive dependency means the
			   method disappears the day WooCommerce reorganises its own graph.
			   WooCommerce also disables any payment method whose declared
			   dependencies are not registered, so the list must be complete. */
			array( 'wc-blocks-registry', 'wc-blocks-checkout', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-i18n' ),
			// Version from the file itself: a cached old script against new PHP
			// is a bug that only appears for people who visited before.
			file_exists( $file ) ? (string) filemtime( $file ) : CHERUM_PAY_VERSION,
			true
		);

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( $handle, 'cherum-pay-for-woocommerce' );
		}

		return array( $handle );
	}

	/**
	 * Data handed to the script.
	 *
	 * @return array<string, mixed>
	 */
	public function get_payment_method_data() {
		$gateway = $this->get_gateway();

		return array(
			'title'       => $gateway ? $gateway->get_title() : __( 'Pay with crypto', 'cherum-pay-for-woocommerce' ),
			'description' => $gateway ? $gateway->get_description() : '',
			/* The block checkout shows no gateway icon unless the method draws one
			   itself; the classic checkout got it from $gateway->icon for free. */
			'icon'        => $gateway ? (string) $gateway->icon : '',
			'coinIcons'   => $gateway && method_exists( $gateway, 'coin_icon_urls' ) ? $gateway->coin_icon_urls() : array(),
			'supports'    => $gateway && is_array( $gateway->supports )
				? array_values( $gateway->supports )
				: array( 'products' ),
		);
	}

	/**
	 * Load the gateway once.
	 *
	 * @return WC_Payment_Gateway|null
	 */
	private function get_gateway() {
		if ( null !== $this->gateway ) {
			return $this->gateway;
		}
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
			return null;
		}
		$gateways = WC()->payment_gateways()->payment_gateways();
		if ( isset( $gateways[ $this->name ] ) ) {
			$this->gateway = $gateways[ $this->name ];
		}
		return $this->gateway;
	}
}
