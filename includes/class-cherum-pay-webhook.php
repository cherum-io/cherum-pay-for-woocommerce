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
		/* A SECOND LOCK, ON WHAT THE EVENT SAYS (1.3.2).
		 *
		 * The id above is per DELIVERY, and the sender gives every endpoint its
		 * own id for the same happening — so a store registered twice (once from
		 * the dashboard, once by this plugin) receives one event as two
		 * deliveries that no id can tell apart. Locking on the body's own id
		 * would change nothing: it is the very same value as the webhook-id
		 * header, checked against the sender. What is identical across the
		 * copies is the event itself — its type, its creation instant and its
		 * payload — so that is what the second lock is made of.
		 *
		 * It cannot swallow a real second event: two events of one type about
		 * one invoice, carrying the same payload and stamped to the same
		 * millisecond, are one event fanned out. */
		$body_lock = 'cherum_pay_ev_' . md5(
			(string) $event['type'] . '|'
			. (string) ( isset( $event['createdAt'] ) ? $event['createdAt'] : '' ) . '|'
			. (string) wp_json_encode( isset( $event['data'] ) ? $event['data'] : null )
		);
		if ( get_transient( $seen ) || get_transient( $body_lock ) ) {
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
			/* SAID OUT LOUD (1.3.3). This branch used to return before the log
			   line below, so a store with logging on saw nothing at all for an
			   event it had just dropped — the one case where the shop owner
			   most needs a trace. */
			Cherum_Pay_Gateway::log(
				'webhook ignored: ' . (string) $event['type'] . ' for ' . $invoice_id
				. ' — no order in this store carries that invoice'
			);
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
		set_transient( $body_lock, 1, DAY_IN_SECONDS );
		try {
			self::apply_event( $order, (string) $event['type'], $event['data'] );
		} catch ( \Throwable $e ) {
			delete_transient( $seen );
			delete_transient( $body_lock );
			Cherum_Pay_Gateway::log( 'webhook apply failed, lock released for retry: ' . $e->getMessage() );
			return new WP_REST_Response( array( 'error' => 'apply_failed' ), 500 );
		}
		Cherum_Pay_Gateway::log( 'webhook applied: ' . (string) $event['type'] . ' for ' . $invoice_id );
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * Find the order that carries this invoice id.
	 *
	 * TWO KEYS, NOT ONE (1.3.3). `_cherum_invoice_id` is the CURRENT invoice
	 * and a retry overwrites it, so an order that has been paid for twice —
	 * "Leave it pending", the buyer comes back an hour later — no longer
	 * answered to its first invoice at all. Everything about that invoice was
	 * then dropped with "unknown_order": money arriving late on its deposit
	 * address, and also the ordinary `invoice.confirmed` / `invoice.credited`
	 * of an expired invoice that IS paid inside the 24-hour late window, which
	 * left a paid order sitting unpaid in WooCommerce with no note and no log
	 * line. `_cherum_invoice_seen` holds every invoice the order ever had, so
	 * the old id still finds it.
	 *
	 * @param string $invoice_id Cherum invoice id.
	 * @return WC_Order|null
	 */
	private static function find_order( $invoice_id ) {
		if ( '' === $invoice_id ) {
			return null;
		}
		foreach ( array( '_cherum_invoice_id', Cherum_Pay_Gateway::INVOICE_LOG_META ) as $meta_key ) {
			$orders = wc_get_orders(
				array(
					'limit'      => 1,
					'meta_key'   => $meta_key,   // phpcs:ignore WordPress.DB.SlowDBQuery
					'meta_value' => $invoice_id, // phpcs:ignore WordPress.DB.SlowDBQuery
				)
			);
			if ( ! empty( $orders ) && $orders[0] instanceof WC_Order ) {
				return $orders[0];
			}
		}
		return null;
	}

	/**
	 * A dollar figure a person reads, from a number with six decimals.
	 *
	 * @param mixed $raw Amount as the service sent it.
	 * @return string
	 */
	private static function usd( $raw ) {
		return is_numeric( $raw ) ? '$' . number_format( (float) $raw, 2, '.', ',' ) : (string) $raw;
	}

	/**
	 * The same figure with nothing dropped, for the books.
	 *
	 * @param mixed $raw Amount as the service sent it.
	 * @return string
	 */
	private static function exact( $raw ) {
		if ( ! is_numeric( $raw ) ) {
			return (string) $raw;
		}
		$s = rtrim( rtrim( number_format( (float) $raw, 6, '.', '' ), '0' ), '.' );
		return '' === $s ? '0' : $s;
	}

	/**
	 * What a dollar figure is worth in the shop's own currency, at THIS
	 * order's rate — the pair stored when the invoice was created.
	 *
	 * Empty for a shop already in dollars, and empty for an order minted
	 * before the rate was recorded: a converted number invented from nothing
	 * is worse than no number.
	 *
	 * @param WC_Order $order Order.
	 * @param mixed    $usd   Amount in dollars.
	 * @return string Sentence to append to a note, or ''.
	 */
	private static function in_store_currency( $order, $usd ) {
		if ( ! is_numeric( $usd ) || 'USD' === $order->get_currency() ) {
			return '';
		}
		$invoice_usd = (float) $order->get_meta( '_cherum_invoice_usd' );
		$order_total = (float) $order->get_meta( '_cherum_order_total' );
		if ( $invoice_usd <= 0 || $order_total <= 0 ) {
			return '';
		}
		return ' ' . sprintf(
			/* translators: %s: the same amount in the shop's currency. */
			__( 'At this order\'s own rate that is %s.', 'cherum-pay-for-woocommerce' ),
			wp_strip_all_tags( wc_price( (float) $usd * $order_total / $invoice_usd ) )
		);
	}

	/**
	 * How much arrived late, in the plainest terms the event allows.
	 *
	 * `usd` is the figure a shop owner can act on; `amountAtomic` is the exact
	 * chain amount and is kept when there is no price. The event carries no
	 * decimals for the asset, so the atomic number is shown as what it is
	 * rather than dressed up as a coin amount.
	 *
	 * @param array $data Event payload.
	 * @return string
	 */
	private static function late_amount( $data ) {
		if ( isset( $data['usd'] ) && is_numeric( $data['usd'] ) ) {
			return '~' . self::usd( $data['usd'] );
		}
		if ( isset( $data['amount'] ) && '' !== (string) $data['amount'] ) {
			return (string) $data['amount']; // older services, and the cabinet's own simulator
		}
		if ( isset( $data['amountAtomic'] ) && '' !== (string) $data['amountAtomic'] ) {
			return sprintf(
				/* translators: %s: amount in the coin's smallest units. */
				__( '%s in the coin\'s smallest units', 'cherum-pay-for-woocommerce' ),
				(string) $data['amountAtomic']
			);
		}
		return __( 'amount in dashboard', 'cherum-pay-for-woocommerce' );
	}

	/**
	 * Which coin on which network arrived late.
	 *
	 * @param array $data Event payload.
	 * @return string
	 */
	private static function late_rail( $data ) {
		$asset = strtoupper( (string) ( $data['asset'] ?? '' ) );
		$chain = (string) ( $data['chain'] ?? '' );
		if ( '' !== $asset && '' !== $chain ) {
			return $asset . ' · ' . $chain;
		}
		$one = '' !== $asset ? $asset : $chain;
		return '' !== $one ? $one : __( 'coin shown in the dashboard', 'cherum-pay-for-woocommerce' );
	}

	/**
	 * E-mail the buyer WooCommerce's own order details, which carry the "pay"
	 * link for a pending order.
	 *
	 * Once per order, and only when it can really be sent: no address, the
	 * e-mail switched off in WooCommerce, or an e-mail already sent all mean
	 * "no", and the caller says so in the note rather than promising.
	 *
	 * @param WC_Order $order Order.
	 * @return bool Whether an e-mail was sent.
	 */
	private static function mail_payment_link( $order ) {
		if ( '' !== (string) $order->get_meta( '_cherum_pay_link_mailed' ) ) {
			return false;
		}
		if ( ! function_exists( 'is_email' ) || ! is_email( $order->get_billing_email() ) ) {
			return false;
		}
		if ( ! function_exists( 'WC' ) ) {
			return false;
		}
		$mailer = WC()->mailer();
		$email  = ( $mailer && isset( $mailer->emails['WC_Email_Customer_Invoice'] ) )
			? $mailer->emails['WC_Email_Customer_Invoice'] : null;
		if ( ! $email || ! $email->is_enabled() ) {
			return false;
		}
		$order->update_meta_data( '_cherum_pay_link_mailed', (string) time() );
		$order->save();
		$email->trigger( $order->get_id(), $order );
		Cherum_Pay_Gateway::log( 'order ' . $order->get_id() . ': invoice expired, order details e-mailed to the buyer' );
		return true;
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
					/* The shop owner's choice of the after-payment status. In
					   1.1.0 this setting existed in the form and in the
					   changelog but was wired to nothing — the class of lie
					   this plugin exists to avoid. 'default' keeps whatever
					   payment_complete() decided.
					   TOLD TO payment_complete(), NOT CORRECTED AFTERWARDS
					   (1.3.2). Setting the status after the fact meant two
					   transitions and two e-mails to the buyer for one payment:
					   "Processing order" from payment_complete, then "Completed
					   order" from the correction, one second apart. WooCommerce
					   asks a filter which status a completed payment leads to —
					   answering it gives one transition and one e-mail. */
					$wanted = Cherum_Pay_Gateway::setting( 'paid_status', 'default' );
					$force  = in_array( $wanted, array( 'processing', 'completed' ), true );
					$pick   = null;
					if ( $force ) {
						$pick = static function () use ( $wanted ) {
							return $wanted;
						};
						add_filter( 'woocommerce_payment_complete_order_status', $pick, 99 );
					}
					$order->payment_complete( sanitize_text_field( (string) ( $data['id'] ?? '' ) ) );
					if ( $force ) {
						remove_filter( 'woocommerce_payment_complete_order_status', $pick, 99 );
						/* Belt and braces: another plugin may filter the same
						   status at a later priority. Then, and only then, the
						   correction happens — with its second e-mail — because
						   a shop whose fulfilment depends on the status must
						   get the status. */
						if ( ! $order->has_status( $wanted ) ) {
							$order->update_status(
								$wanted,
								__( 'Cherum Pay: status set per the gateway setting "Order status once paid".', 'cherum-pay-for-woocommerce' )
							);
						}
					}
				}
				if ( isset( $data['creditedUsd'] ) ) {
					/* MONEY WITH ITS UNIT ON IT (1.3.3). The note used to print
					   the raw number — "60.82758 credited to your balance" —
					   with no currency and six decimal places. A shop owner in
					   euros read that as euros beside an order of €52.92 and
					   their books looked inflated. Cherum credits in US
					   dollars, to six decimals on purpose (the atomic amount
					   travels in the same message and rounding to cents would
					   contradict it), so the note now names the currency, leads
					   with cents, and keeps the exact figure beside it. */
					$order->add_order_note(
						sprintf(
							/* translators: 1: amount in dollars, 2: exact amount to six decimals. */
							__( 'Cherum Pay: %1$s credited to your Cherum balance after fees. Cherum credits in US dollars; the exact amount is %2$s USD.', 'cherum-pay-for-woocommerce' ),
							esc_html( self::usd( $data['creditedUsd'] ) ),
							esc_html( self::exact( $data['creditedUsd'] ) )
						)
						. self::in_store_currency( $order, $data['creditedUsd'] )
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
				/* WHAT ACTUALLY HAPPENS TO THE SURPLUS (1.3.3). This note used
				   to say the surplus goes back "automatically — you do
				   nothing", while the payment page told the buyer the opposite
				   in the same minute: a refund of an overpayment is started BY
				   THE BUYER, who has to name a wallet on the payment page, and
				   a surplus below Cherum's minimum refund amount is not sent
				   back at all. A shop owner who believed the note would tell a
				   complaining customer to wait for money that was never
				   coming. */
				$order->add_order_note(
					__( 'Cherum Pay: more than the invoice was received. The surplus does NOT go back on its own — the buyer is offered it on the payment page and has to give a wallet address for it, and a surplus below Cherum\'s minimum refund amount is not returned at all. Nothing is owed by you; if the buyer asks, the surplus is under Accept → Refunds in the Cherum dashboard.', 'cherum-pay-for-woocommerce' )
				);
				break;

			case 'invoice.expired':
				if ( 'keep' === Cherum_Pay_Gateway::setting( 'expired_action', 'cancel' ) ) {
					/* THE E-MAIL THE NOTE PROMISED (1.3.3). Both this note and
					   the setting's own description told the shop owner the
					   buyer could "pay again from the link in their e-mail" —
					   and WooCommerce sends a customer no e-mail whatsoever
					   for an order that stays pending. The buyer's only way
					   back was a browser tab. WooCommerce's own "Customer
					   invoice / Order details" e-mail is exactly that link, so
					   the plugin sends it, once per order; when it cannot (no
					   address, the e-mail switched off in WooCommerce, already
					   sent) the note says so instead of promising. */
					$order->add_order_note(
						self::mail_payment_link( $order )
							? __( 'Cherum Pay: the invoice expired without payment. The order stays pending, as set in the gateway settings, and the buyer has been e-mailed the order details with a link to pay it.', 'cherum-pay-for-woocommerce' )
							: __( 'Cherum Pay: the invoice expired without payment. The order stays pending, as set in the gateway settings. The buyer was NOT e-mailed a payment link — send one with Order actions → "Send order details to customer", or they have no way back to this order.', 'cherum-pay-for-woocommerce' )
					);
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
				   everything needed to decide.
				   READ FROM THE FIELDS THE SERVICE ACTUALLY SENDS (1.3.3). It
				   read `amount` and `txHash`; this event carries neither. The
				   documented body is
				   {id, ledgerId, asset, chain, amountAtomic, usd, arrivedAt,
				   decideBy}, so every live note in a shop read "(amount in
				   dashboard, tx —)" — the one note whose whole job is to carry
				   the figures. */
				$order->add_order_note(
					sprintf(
						/* translators: 1: amount, 2: coin and network, 3: the date by which the shop owner has to decide. */
						__( 'Cherum Pay: A PAYMENT ARRIVED AFTER THE INVOICE CLOSED (%1$s, %2$s). The money is safe but NOT credited. Decide in the Cherum dashboard under Accept → Refunds by %3$s: credit it to this order or send it back.', 'cherum-pay-for-woocommerce' ),
						esc_html( self::late_amount( $data ) ),
						esc_html( self::late_rail( $data ) ),
						esc_html( (string) ( $data['decideBy'] ?? __( 'the date shown in the dashboard', 'cherum-pay-for-woocommerce' ) ) )
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
