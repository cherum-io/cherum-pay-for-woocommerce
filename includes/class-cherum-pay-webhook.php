<?php
/**
 * Incoming notifications from Cherum Pay.
 *
 * This is the money-critical file of the plugin: everything else can be wrong
 * and the shop still works, but a webhook handler that trusts the wrong body
 * marks unpaid orders as paid.
 *
 * @package Cherum_Pay
 */

defined( 'ABSPATH' ) || exit;

/**
 * Verifies and applies Cherum Pay notifications.
 */
class Cherum_Pay_Webhook {

	/**
	 * How far apart the sender's clock and ours may be.
	 *
	 * Without this window a captured request can be replayed forever: the
	 * signature stays valid because the body never changes. Five minutes is
	 * the Standard Webhooks recommendation and it survives ordinary clock
	 * drift on shared hosting, which is worse than people expect.
	 */
	const TOLERANCE_SECONDS = 300;

	/**
	 * Register the route.
	 */
	public static function init() {
		add_action(
			'rest_api_init',
			static function () {
				register_rest_route(
					'cherum-pay/v1',
					'/webhook',
					array(
						'methods'             => 'POST',
						'callback'            => array( __CLASS__, 'handle' ),
						// The signature IS the authentication. A cookie or a
						// nonce would be wrong here: the caller is a server,
						// not a browser session.
						'permission_callback' => '__return_true',
					)
				);
			}
		);
	}

	/**
	 * Constant-time comparison that also works on ancient PHP builds.
	 *
	 * A plain === on a MAC leaks its bytes through timing. The leak is small
	 * and the fix is free, so there is no reason to accept it.
	 *
	 * @param string $known    Expected value.
	 * @param string $supplied Value from the request.
	 * @return bool
	 */
	private static function equals( $known, $supplied ) {
		if ( function_exists( 'hash_equals' ) ) {
			return hash_equals( $known, $supplied );
		}
		if ( strlen( $known ) !== strlen( $supplied ) ) {
			return false;
		}
		$diff = 0;
		for ( $i = 0, $n = strlen( $known ); $i < $n; $i++ ) {
			$diff |= ord( $known[ $i ] ) ^ ord( $supplied[ $i ] );
		}
		return 0 === $diff;
	}

	/**
	 * Check the Standard Webhooks signature.
	 *
	 * The signed string is "{id}.{timestamp}.{body}" and the secret is
	 * base64 after the whsec_ prefix — both taken from the sender's own
	 * implementation, not guessed.
	 *
	 * The header may carry SEVERAL space-separated signatures: that is how a
	 * secret is rotated without downtime. Accepting the first one only would
	 * break every rotation, so each is tried.
	 *
	 * @param string $secret    Endpoint secret (whsec_...).
	 * @param string $msg_id    webhook-id header.
	 * @param string $timestamp webhook-timestamp header.
	 * @param string $body      Raw request body.
	 * @param string $header    webhook-signature header.
	 * @return bool
	 */
	public static function verify( $secret, $msg_id, $timestamp, $body, $header ) {
		if ( '' === $secret || '' === $msg_id || '' === $timestamp || '' === $header ) {
			return false;
		}
		if ( ! preg_match( '/^\d{1,12}$/', (string) $timestamp ) ) {
			return false;
		}
		$age = abs( time() - (int) $timestamp );
		if ( $age > self::TOLERANCE_SECONDS ) {
			return false;
		}

		$key = ( 0 === strpos( $secret, 'whsec_' ) )
			? base64_decode( substr( $secret, 6 ), true )
			: $secret;
		if ( false === $key || '' === $key ) {
			return false;
		}

		$expected = base64_encode( hash_hmac( 'sha256', $msg_id . '.' . $timestamp . '.' . $body, $key, true ) );

		foreach ( preg_split( '/\s+/', trim( $header ) ) as $candidate ) {
			// Each entry looks like "v1,<base64>". An entry of an unknown
			// version is skipped rather than refused: a future version must
			// not break a store running today's plugin.
			if ( 0 !== strpos( $candidate, 'v1,' ) ) {
				continue;
			}
			if ( self::equals( $expected, substr( $candidate, 3 ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Handle one notification.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle( $request ) {
		$gateways = WC()->payment_gateways()->payment_gateways();
		if ( empty( $gateways['cherum_pay'] ) ) {
			return new WP_REST_Response( array( 'error' => 'gateway_off' ), 503 );
		}
		$gateway = $gateways['cherum_pay'];
		$secret  = (string) $gateway->get_option( 'webhook_secret' );

		$body = $request->get_body();
		$ok   = self::verify(
			$secret,
			(string) $request->get_header( 'webhook-id' ),
			(string) $request->get_header( 'webhook-timestamp' ),
			$body,
			(string) $request->get_header( 'webhook-signature' )
		);
		if ( ! $ok ) {
			// No detail in the answer: a caller who cannot sign has no business
			// learning WHY the signature failed. The log may say more: the
			// reader of the log is the shop owner, not the caller.
			Cherum_Pay_Gateway::log( 'webhook rejected: bad signature' . ( '' === $secret ? ' (no secret configured)' : '' ) );
			return new WP_REST_Response( array( 'error' => 'bad_signature' ), 401 );
		}

		$event = json_decode( $body, true );
		if ( ! is_array( $event ) || empty( $event['type'] ) ) {
			return new WP_REST_Response( array( 'error' => 'bad_body' ), 400 );
		}

		// REPLAY GUARD. Retries are normal — the sender repeats until it gets a
		// 2xx — so the same event id will arrive more than once. Applying it
		// twice would add two "paid" notes and could move an order backwards.
		$msg_id = (string) $request->get_header( 'webhook-id' );
		$seen   = 'cherum_pay_seen_' . md5( $msg_id );
		if ( get_transient( $seen ) ) {
			// 200, not an error: this delivery succeeded, we simply did the
			// work already. An error here would make the sender retry forever.
			return new WP_REST_Response( array( 'ok' => true, 'duplicate' => true ), 200 );
		}

		/* Invoice events carry the invoice id in data.id; refund events carry
		   the REFUND id there and the invoice in data.invoiceId. 1.3.0 read
		   data.id for both, so every refund event answered "unknown order" —
		   found on the first live refund rehearsal (02.09): the payout note and
		   the loud failure note never reached the order. */
		$invoice_id = isset( $event['data']['invoiceId'] )
			? sanitize_text_field( (string) $event['data']['invoiceId'] )
			: ( isset( $event['data']['id'] ) ? sanitize_text_field( (string) $event['data']['id'] ) : '' );
		$order      = self::find_order( $invoice_id );
		if ( ! $order ) {
			// 200 on purpose: the invoice may belong to another store sharing
			// the key, and making the sender retry an order we will never have
			// is noise for both sides.
			return new WP_REST_Response( array( 'ok' => true, 'unknown_order' => true ), 200 );
		}

		/* The lock is taken BEFORE the work and RELEASED on failure (1.2.1):
		   1.1.0 locked first and lost the event on a crash; 1.2.0 locked
		   after and left a window where two concurrent deliveries of the
		   same event could both apply. Lock-then-work-then-release-on-failure
		   closes both: a crash releases the lock so the retry does the work,
		   and a concurrent duplicate sees the lock and answers 200. */
		set_transient( $seen, 1, DAY_IN_SECONDS );
		try {
			self::apply_event( $order, (string) $event['type'], $event['data'] );
		} catch ( \Throwable $e ) {
			delete_transient( $seen );
			Cherum_Pay_Gateway::log( 'webhook apply failed, lock released for retry: ' . $e->getMessage() );
			return new WP_REST_Response( array( 'error' => 'apply_failed' ), 500 );
		}
		Cherum_Pay_Gateway::log( 'webhook applied: ' . (string) $event['type'] . ' for ' . $invoice_id );
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * Find the order that carries this invoice id.
	 *
	 * @param string $invoice_id Cherum invoice id.
	 * @return WC_Order|null
	 */
	private static function find_order( $invoice_id ) {
		if ( '' === $invoice_id ) {
			return null;
		}
		$orders = wc_get_orders(
			array(
				'limit'      => 1,
				'meta_key'   => '_cherum_invoice_id', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value' => $invoice_id,          // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		return ( ! empty( $orders ) && $orders[0] instanceof WC_Order ) ? $orders[0] : null;
	}

	/**
	 * Apply one event to an order.
	 *
	 * ONE place decides what an event means. Spreading this across handlers is
	 * how an order ends up paid by one path and refunded by another.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $type  Event type.
	 * @param array    $data  Event payload.
	 */
	public static function apply_event( $order, $type, $data ) {
		switch ( $type ) {
			case 'invoice.confirmed':
			case 'invoice.credited':
				/* What the buyer paid with, kept on the order for the shop owner's
				   eyes: the order screen shows it, reports can read it. */
				foreach ( array( 'coin' => '_cherum_paid_coin', 'network' => '_cherum_paid_network', 'amountCrypto' => '_cherum_paid_amount', 'tokenDecimals' => '_cherum_paid_decimals', 'creditedUsd' => '_cherum_credited_usd' ) as $field => $meta ) {
					if ( isset( $data[ $field ] ) && '' !== (string) $data[ $field ] ) {
						$order->update_meta_data( $meta, sanitize_text_field( (string) $data[ $field ] ) );
					}
				}
				$order->save();
				// A paid order never goes back to pending on a later event: the
				// order of arrival is not guaranteed, and an out-of-order
				// delivery must not undo money that already landed.
				if ( ! $order->is_paid() ) {
					$order->payment_complete( sanitize_text_field( (string) ( $data['id'] ?? '' ) ) );
					/* The shop owner's choice of the after-payment status. In
					   1.1.0 this setting existed in the form and in the
					   changelog but was wired to nothing — the class of lie
					   this plugin exists to avoid. 'default' keeps whatever
					   payment_complete() decided. */
					$wanted = Cherum_Pay_Gateway::setting( 'paid_status', 'default' );
					if ( in_array( $wanted, array( 'processing', 'completed' ), true )
						&& ! $order->has_status( $wanted ) ) {
						$order->update_status(
							$wanted,
							__( 'Cherum Pay: status set per the gateway setting "Order status once paid".', 'cherum-pay-for-woocommerce' )
						);
					}
				}
				if ( isset( $data['creditedUsd'] ) ) {
					$order->add_order_note(
						sprintf(
							/* translators: %s: amount credited, in US dollars. */
							__( 'Cherum Pay: %s credited to your balance after fees.', 'cherum-pay-for-woocommerce' ),
							esc_html( (string) $data['creditedUsd'] )
						)
					);
				}
				break;

			case 'invoice.underpaid':
				// Not a failure: the invoice stays open and the buyer can top
				// it up. Cancelling here would throw away money already sent.
				// A PAID order is never demoted by a late or re-ordered
				// underpaid event (audit 02.09): the top-up already landed.
				if ( $order->is_paid() ) {
					$order->add_order_note( __( 'Cherum Pay: an "underpaid" event arrived after the payment completed — ignored, the payment stands.', 'cherum-pay-for-woocommerce' ) );
					break;
				}
				$order->update_status(
					'on-hold',
					__( 'Cherum Pay: less than the invoice was received. The invoice stays open until the rest arrives.', 'cherum-pay-for-woocommerce' )
				);
				break;

			case 'invoice.overpaid':
				$order->add_order_note(
					__( 'Cherum Pay: more than the invoice was received. The surplus is returned to the buyer automatically — you do nothing.', 'cherum-pay-for-woocommerce' )
				);
				break;

			case 'invoice.expired':
				if ( 'keep' === Cherum_Pay_Gateway::setting( 'expired_action', 'cancel' ) ) {
					$order->add_order_note( __( 'Cherum Pay: the invoice expired without payment. The order stays pending, as set in the gateway settings; the buyer can pay again from the link in their e-mail.', 'cherum-pay-for-woocommerce' ) );
					break;
				}
				if ( ! $order->is_paid() ) {
					$order->update_status(
						'cancelled',
						__( 'Cherum Pay: the invoice expired without payment.', 'cherum-pay-for-woocommerce' )
					);
				}
				break;

			case 'invoice.created':
				// Our own plugin created this invoice a moment ago; a note
				// saying so on every order would be pure noise. Silence is the
				// deliberate handling, not an omission.
				break;

			case 'invoice.seen':
				/* Payment detected on chain, not yet confirmed. On-hold is the
				   WooCommerce word for exactly this (bank transfer uses it):
				   the shop owner sees movement without anyone claiming the
				   money arrived. A paid order is never demoted. */
				if ( ! $order->is_paid() && $order->has_status( 'pending' ) ) {
					$order->update_status(
						'on-hold',
						__( 'Cherum Pay: payment detected, waiting for network confirmation.', 'cherum-pay-for-woocommerce' )
					);
				}
				break;

			case 'invoice.canceled':
				// Cancelled on the Cherum side (dashboard or API). The order
				// follows — unless it is already paid, in which case the money
				// wins and the note records the contradiction.
				if ( ! $order->is_paid() ) {
					$order->update_status(
						'cancelled',
						__( 'Cherum Pay: the invoice was cancelled on the Cherum side.', 'cherum-pay-for-woocommerce' )
					);
				} else {
					$order->add_order_note(
						__( 'Cherum Pay: a cancel event arrived for an already-paid order — the payment stands, nothing was changed.', 'cherum-pay-for-woocommerce' )
					);
				}
				break;

			case 'invoice.repriced':
				$order->add_order_note(
					__( 'Cherum Pay: the rate window expired and the invoice was re-quoted. The buyer sees the new amount on the payment page; the order total is unchanged.', 'cherum-pay-for-woocommerce' )
				);
				break;

			case 'invoice.late_inflow':
				/* The most expensive silence of the old version: money arrived
				   AFTER the invoice closed — typically after this very plugin
				   cancelled the order on invoice.expired. Nothing is credited
				   automatically; the shop owner decides. The note must carry
				   everything needed to decide. */
				$order->add_order_note(
					sprintf(
						/* translators: 1: amount, 2: transaction hash. */
						__( 'Cherum Pay: A PAYMENT ARRIVED AFTER THE INVOICE CLOSED (%1$s, tx %2$s). The money is safe but NOT credited. Decide in the Cherum dashboard under Accept → Refunds: credit it to this order or send it back.', 'cherum-pay-for-woocommerce' ),
						esc_html( (string) ( $data['amount'] ?? __( 'amount in dashboard', 'cherum-pay-for-woocommerce' ) ) ),
						esc_html( (string) ( $data['txHash'] ?? '—' ) )
					)
				);
				break;

			case 'refund.created':
				$order->add_order_note(
					sprintf(
						/* translators: %s: refund id. */
						__( 'Cherum Pay: refund %s is open. The payer is asked for a wallet on the payment page.', 'cherum-pay-for-woocommerce' ),
						esc_html( (string) ( $data['id'] ?? '—' ) )
					)
				);
				break;

			case 'refund.completed':
				$order->add_order_note(
					sprintf(
						/* translators: 1: refund id, 2: transaction hash. */
						__( 'Cherum Pay: refund %1$s is PAID OUT (tx %2$s). The books on this order now match reality.', 'cherum-pay-for-woocommerce' ),
						esc_html( (string) ( $data['id'] ?? '—' ) ),
						esc_html( (string) ( $data['txHash'] ?? '—' ) )
					)
				);
				break;

			case 'refund.failed':
			case 'refund.canceled':
			case 'refund.expired':
				/* THE LOUDEST NOTE IN THE PLUGIN, on purpose. When the admin
				   pressed Refund, WooCommerce recorded a refund line at once —
				   but our refund is asynchronous, and on this event the money
				   did NOT leave: the reserve went back to the merchant balance.
				   From this moment the WooCommerce books LIE about this order,
				   and only the shop owner can make them honest again. Deleting
				   the refund line ourselves would silently rewrite their books —
				   telling them beats surprising them. */
				$order->update_status(
					'on-hold',
					sprintf(
						/* translators: 1: event type, 2: refund id. */
						__( 'Cherum Pay: REFUND DID NOT GO THROUGH (%1$s, %2$s). The money stayed on your Cherum balance. The refund line WooCommerce recorded on this order no longer matches reality — delete it (Order → Refunds → ×) or retry the refund from the Cherum dashboard.', 'cherum-pay-for-woocommerce' ),
						esc_html( $type ),
						esc_html( (string) ( $data['id'] ?? '—' ) )
					)
				);
				break;

			default:
				// Unknown types are recorded, not dropped: a new event type
				// must never look like nothing happened.
				$order->add_order_note(
					sprintf(
						/* translators: %s: event type name. */
						__( 'Cherum Pay: received an event this plugin version does not handle (%s).', 'cherum-pay-for-woocommerce' ),
						esc_html( $type )
					)
				);
		}
	}
}
