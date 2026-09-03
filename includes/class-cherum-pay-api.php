<?php
/**
 * The only place in the plugin that talks to the network.
 *
 * One place on purpose: a second copy of "how we call Cherum" drifts from the
 * first exactly when someone fixes a bug in one of them.
 *
 * @package Cherum_Pay
 */

defined( 'ABSPATH' ) || exit;

/**
 * Thin client over the Cherum Pay merchant API.
 */
class Cherum_Pay_Api {

	const BASE = 'https://app.cherum.io/api/pay/v1';

	/**
	 * Merchant API key (chm_live_... or chm_test_...).
	 *
	 * @var string
	 */
	private $key;

	/**
	 * Constructor.
	 *
	 * @param string $key API key.
	 */
	public function __construct( $key ) {
		$this->key = (string) $key;
	}

	/**
	 * Create an invoice.
	 *
	 * @param array $body Request body.
	 * @param string $idempotency_key Retry-safe key.
	 * @return array{ok:bool,data:array,error:string,status:int}
	 */
	public function create_invoice( array $body, $idempotency_key = '' ) {
		$headers = array(
			'Authorization' => 'token ' . $this->key,
			'Content-Type'  => 'application/json',
		);
		// WooCommerce retries a failed checkout, and a shopper double-clicks.
		// Without this header each attempt would mint a NEW invoice and the
		// buyer could pay a stale one.
		if ( '' !== $idempotency_key ) {
			$headers['Idempotency-Key'] = $idempotency_key;
		}

		$res = wp_remote_post(
			self::BASE . '/invoices',
			array(
				'headers' => $headers,
				'body'    => wp_json_encode( $body ),
				'timeout' => 20,
			)
		);
		return $this->unwrap( $res );
	}

	/**
	 * Read one invoice.
	 *
	 * @param string $id Invoice id.
	 * @return array{ok:bool,data:array,error:string,status:int}
	 */
	public function get_invoice( $id ) {
		$res = wp_remote_get(
			self::BASE . '/invoices/' . rawurlencode( $id ),
			array(
				'headers' => array( 'Authorization' => 'token ' . $this->key ),
				'timeout' => 15,
			)
		);
		return $this->unwrap( $res );
	}

	/**
	 * Ask what a refund on this invoice would cost before promising one.
	 *
	 * The shop owner types an amount in the WordPress refund box and expects it
	 * to happen. It may not: the balance can be short, the network cost can
	 * exceed the amount, the invoice may never have settled. Asking first turns
	 * "Refund failed" into a sentence that says why.
	 *
	 * @param string $invoice_id Invoice id.
	 * @param float  $amount_usd Amount to check, in dollars.
	 * @return array{ok:bool,data:array,error:string,status:int}
	 */
	public function refund_quote( $invoice_id, $amount_usd ) {
		$res = wp_remote_get(
			self::BASE . '/invoices/' . rawurlencode( $invoice_id ) . '/refund-quote'
				. '?amountUsd=' . rawurlencode( (string) $amount_usd ),
			array(
				'headers' => array( 'Authorization' => 'token ' . $this->key ),
				'timeout' => 15,
			)
		);
		return $this->unwrap( $res );
	}

	/**
	 * Create the refund.
	 *
	 * The idempotency key is not optional here in spirit: WooCommerce lets an
	 * admin click "Refund" twice, and a second refund on the same order is
	 * money leaving twice. The key makes the retry return the SAME refund.
	 *
	 * @param string $invoice_id Invoice id.
	 * @param float  $amount_usd Amount in dollars.
	 * @param string $reason     Reason typed by the shop owner; may be empty.
	 * @param string $idem       Idempotency key.
	 * @return array{ok:bool,data:array,error:string,status:int}
	 */
	public function create_refund( $invoice_id, $amount_usd, $reason, $idem ) {
		$body = array(
			'invoiceId' => $invoice_id,
			'amountUsd' => (float) $amount_usd,
		);
		if ( '' !== $reason ) {
			$body['reason'] = $reason;
		}
		$res = wp_remote_post(
			self::BASE . '/refunds',
			array(
				'headers' => array(
					'Authorization'   => 'token ' . $this->key,
					'Content-Type'    => 'application/json',
					'Idempotency-Key' => $idem,
				),
				'body'    => wp_json_encode( $body ),
				'timeout' => 25,
			)
		);
		return $this->unwrap( $res );
	}

	/**
	 * The notification endpoints registered for this key.
	 *
	 * Doubles as the connection check: it is the cheapest call that proves
	 * the key is real and carries the webhooks.manage permission.
	 *
	 * @return array{ok:bool,data:array,error:string,status:int}
	 */
	public function list_webhooks() {
		$res = wp_remote_get(
			self::BASE . '/webhooks',
			array(
				'headers' => array( 'Authorization' => 'token ' . $this->key ),
				'timeout' => 15,
			)
		);
		return $this->unwrap( $res );
	}

	/**
	 * Register this store as a notification endpoint. The secret comes back
	 * exactly once, in this response.
	 *
	 * @param string $url Public https address of the store's webhook route.
	 * @return array{ok:bool,data:array,error:string,status:int}
	 */
	public function create_webhook( $url ) {
		$res = wp_remote_post(
			self::BASE . '/webhooks',
			array(
				'headers' => array(
					'Authorization' => 'token ' . $this->key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( array( 'url' => $url ) ),
				'timeout' => 20,
			)
		);
		return $this->unwrap( $res );
	}

	/**
	 * Issue a fresh secret for an endpoint that already exists.
	 *
	 * Used when the store knows the endpoint but not its secret (a re-install,
	 * a restored backup). The old secret stays valid for a day, so nothing in
	 * flight is lost.
	 *
	 * @param int $id Endpoint id.
	 * @return array{ok:bool,data:array,error:string,status:int}
	 */
	public function rotate_webhook_secret( $id ) {
		$res = wp_remote_post(
			self::BASE . '/webhooks/' . rawurlencode( (string) (int) $id ) . '/rotate-secret',
			array(
				'headers' => array(
					'Authorization' => 'token ' . $this->key,
					'Content-Type'  => 'application/json',
				),
				'body'    => '{}',
				'timeout' => 20,
			)
		);
		return $this->unwrap( $res );
	}

	/**
	 * Turn a WordPress HTTP result into a plain answer.
	 *
	 * The shape is always the same so no caller has to remember whether an
	 * error arrives as an exception, a false, or a body — the mistake that
	 * makes a plugin swallow failures.
	 *
	 * @param array|WP_Error $res Response.
	 * @return array{ok:bool,data:array,error:string,status:int}
	 */
	private function unwrap( $res ) {
		if ( is_wp_error( $res ) ) {
			// A network failure is not a payment failure: the invoice may well
			// have been created. The caller must say "try again", never
			// "declined".
			return array(
				'ok'     => false,
				'data'   => array(),
				'error'  => $res->get_error_message(),
				'status' => 0,
			);
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $body ) ) {
			$body = array();
		}
		if ( $code >= 200 && $code < 300 ) {
			return array( 'ok' => true, 'data' => $body, 'error' => '', 'status' => $code );
		}
		// The service answers {error:{code,message}} and the message is written
		// for a human — passing it through beats inventing our own wording.
		$message = isset( $body['error']['message'] )
			? (string) $body['error']['message']
			: sprintf(
				/* translators: %d: HTTP status code. */
				__( 'Cherum Pay returned status %d.', 'cherum-pay-for-woocommerce' ),
				$code
			);
		return array( 'ok' => false, 'data' => $body, 'error' => $message, 'status' => $code );
	}
}
