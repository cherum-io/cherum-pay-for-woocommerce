<?php
/**
 * The "Cherum Pay" box on the order screen.
 *
 * Everything the shop owner might otherwise open another dashboard for:
 * the invoice, what the buyer paid with, what landed on the balance, and
 * the link to the payment page. Refunds stay where WooCommerce puts them,
 * in the Refund button below the items.
 *
 * @package Cherum_Pay
 */

defined( 'ABSPATH' ) || exit;

/**
 * Order screen meta box.
 */
class Cherum_Pay_Order_Box {

	/** The Order actions entry that calls an open refund off. */
	const CANCEL_ACTION = 'cherum_pay_cancel_refund';

	/**
	 * Hook it up for both order storage modes.
	 */
	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'register' ) );
		/* CANCELLING A REFUND LIVES IN WOOCOMMERCE'S OWN ORDER ACTIONS (1.3.6).
		   Not a link or a form of our own: the meta box is rendered inside the
		   order edit form, so a second form there is invalid markup, and a
		   route of our own would mean re-implementing the nonce and capability
		   check WooCommerce already does for this box. The entry appears only
		   when there IS something to cancel — an action that is always there
		   and usually does nothing teaches people to ignore it. */
		add_filter( 'woocommerce_order_actions', array( __CLASS__, 'order_actions' ), 10, 2 );
		add_action( 'woocommerce_order_action_' . self::CANCEL_ACTION, array( __CLASS__, 'run_cancel_refund' ) );
	}

	/**
	 * Offer "Cancel Cherum refund" while a refund is open on this order.
	 *
	 * @param array         $actions Actions WooCommerce is about to show.
	 * @param WC_Order|null $order   The order, since WooCommerce 5.8.
	 * @return array
	 */
	public static function order_actions( $actions, $order = null ) {
		if ( $order instanceof WC_Order
			&& 'cherum_pay' === $order->get_payment_method()
			&& '' !== Cherum_Pay_Gateway::open_refund_id( $order ) ) {
			$actions[ self::CANCEL_ACTION ] = __( 'Cancel Cherum refund', 'cherum-pay-for-woocommerce' );
		}
		return $actions;
	}

	/**
	 * Run it. The outcome is written on the order by the gateway, which is
	 * where it survives the redirect this screen does next.
	 *
	 * @param WC_Order $order Order WooCommerce hands over.
	 */
	public static function run_cancel_refund( $order ) {
		if ( $order instanceof WC_Order ) {
			Cherum_Pay_Gateway::cancel_refund( $order );
		}
	}

	/**
	 * Register the box on the order edit screen.
	 */
	public static function register() {
		$screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		add_meta_box(
			'cherum-pay-order',
			'Cherum Pay',
			array( __CLASS__, 'render' ),
			$screen,
			'side',
			'high'
		);
	}

	/**
	 * Draw the box.
	 *
	 * @param WP_Post|WC_Order $post_or_order What WooCommerce hands over.
	 */
	public static function render( $post_or_order ) {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order || 'cherum_pay' !== $order->get_payment_method() ) {
			echo '<p>' . esc_html__( 'This order was not paid with Cherum Pay.', 'cherum-pay-for-woocommerce' ) . '</p>';
			return;
		}
		$id      = (string) $order->get_meta( '_cherum_invoice_id' );
		$url     = (string) $order->get_meta( '_cherum_checkout_url' );
		$coin    = (string) $order->get_meta( '_cherum_paid_coin' );
		$network = (string) $order->get_meta( '_cherum_paid_network' );
		$amount  = self::human_amount( (string) $order->get_meta( '_cherum_paid_amount' ), (string) $order->get_meta( '_cherum_paid_decimals' ) );
		$usd     = (string) $order->get_meta( '_cherum_credited_usd' );
		$mode    = 0 === strpos( (string) Cherum_Pay_Gateway::setting( 'api_key' ), 'chm_test_' )
			? __( 'test', 'cherum-pay-for-woocommerce' )
			: __( 'live', 'cherum-pay-for-woocommerce' );

		echo '<table class="widefat" style="border:0"><tbody>';
		self::row( __( 'Invoice', 'cherum-pay-for-woocommerce' ), $id ? '<code>' . esc_html( $id ) . '</code>' : '—' );
		self::row( __( 'Mode', 'cherum-pay-for-woocommerce' ), esc_html( $mode ) );
		if ( $coin ) {
			self::row(
				__( 'Paid with', 'cherum-pay-for-woocommerce' ),
				esc_html( trim( $amount . ' ' . strtoupper( $coin ) ) ) . ( $network ? ' · ' . esc_html( $network ) : '' )
			);
		}
		if ( '' !== $usd ) {
			/* THE CURRENCY IS PART OF THE NUMBER (1.3.3). This printed the raw
			   figure the service sends — "$60.82758" — six decimals and a
			   dollar sign glued to a value a shop in euros reads as euros.
			   Cents first, the exact figure beside it, and the word USD said
			   once so nobody has to assume it. */
			self::row(
				__( 'Credited', 'cherum-pay-for-woocommerce' ),
				is_numeric( $usd )
					? esc_html( '$' . number_format( (float) $usd, 2, '.', ',' ) . ' USD' )
						. ' <span style="color:#646970">(' . esc_html( self::exact( $usd ) ) . ')</span>'
					: esc_html( $usd )
			);
		}
		echo '</tbody></table>';
		if ( $url ) {
			echo '<p style="margin:10px 0 4px"><a class="button" href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">'
				. esc_html__( 'Open the payment page', 'cherum-pay-for-woocommerce' ) . '</a></p>';
		}
		/* AN OPEN REFUND IS SAID OUT LOUD (1.3.6). Cherum allows one open
		   refund per invoice, and an open one can sit for days waiting for the
		   buyer's wallet or for the network cost to come back down. Without
		   this line the shop owner met the rule only as a refusal — "a refund
		   is already open on this payment" — with nothing on the screen naming
		   it and no way to end it. */
		$open = Cherum_Pay_Gateway::open_refund_id( $order );
		if ( '' !== $open ) {
			echo '<p style="margin:10px 0 0"><strong>' . esc_html__( 'A refund is open', 'cherum-pay-for-woocommerce' ) . '</strong> <code>' . esc_html( $open ) . '</code></p>';
			echo '<p class="description">' . esc_html__( 'Cherum is waiting for the buyer to give a wallet on the payment page, or for the payout to go out. Until it ends, this order cannot start another refund. To call it off: Order actions → "Cancel Cherum refund" → Update. Deleting the refund line under the items cancels it too.', 'cherum-pay-for-woocommerce' ) . '</p>';
		} elseif ( $order->is_paid() ) {
			echo '<p class="description">' . esc_html__( 'To refund, use the Refund button under the items. Cherum returns the money to the buyer and the note here says what happened.', 'cherum-pay-for-woocommerce' ) . '</p>';
		}
	}

	/**
	 * The credited figure with nothing dropped: Cherum credits to six decimal
	 * places, and rounding it away in the only place a shop owner reads it
	 * would put the order screen at odds with the books.
	 *
	 * @param string $raw Amount as the service sent it.
	 * @return string
	 */
	private static function exact( $raw ) {
		$s = rtrim( rtrim( number_format( (float) $raw, 6, '.', '' ), '0' ), '.' );
		return '' === $s ? '0' : $s;
	}

	/**
	 * The amount arrives in the coin's smallest units (the way chains count);
	 * people read "52.985531", not "52985531". Without a known decimals value
	 * the raw number is shown rather than a guessed one.
	 *
	 * @param string $atomic   Integer string in smallest units.
	 * @param string $decimals Decimals of the coin, '' when unknown.
	 * @return string
	 */
	private static function human_amount( $atomic, $decimals ) {
		if ( '' === $atomic || '' === $decimals || ! ctype_digit( $atomic ) || ! ctype_digit( $decimals ) ) {
			return $atomic;
		}
		$d = (int) $decimals;
		if ( 0 === $d ) {
			return $atomic;
		}
		$padded = str_pad( $atomic, $d + 1, '0', STR_PAD_LEFT );
		$whole  = substr( $padded, 0, -$d );
		$frac   = rtrim( substr( $padded, -$d ), '0' );
		return '' === $frac ? $whole : $whole . '.' . $frac;
	}

	/**
	 * One labelled row.
	 *
	 * @param string $label Left.
	 * @param string $html  Right, already escaped.
	 */
	private static function row( $label, $html ) {
		echo '<tr><td style="padding:4px 0;color:#646970;width:40%">' . esc_html( $label ) . '</td><td style="padding:4px 0">' . wp_kses_post( $html ) . '</td></tr>';
	}
}
