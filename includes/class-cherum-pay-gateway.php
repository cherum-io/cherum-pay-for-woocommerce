<?php
/**
 * The payment method itself.
 *
 * @package Cherum_Pay
 */

defined( 'ABSPATH' ) || exit;

/**
 * Cherum Pay gateway: creates an invoice and sends the buyer to pay it.
 */
class Cherum_Pay_Gateway extends WC_Payment_Gateway {

	/**
	 * Currencies Cherum can price an invoice in.
	 *
	 * The list is explicit, not "whatever the store is set to": an invoice in a
	 * currency we cannot price would be created with a wrong amount, and the
	 * buyer finds out at the worst moment. A shop in an unsupported currency is
	 * told so in the admin instead of being quietly broken at checkout.
	 *
	 * @var string[]
	 */
	const CURRENCIES = array(
		'USD', 'EUR', 'GBP', 'CHF', 'JPY', 'CAD', 'AUD', 'NZD',
		'SEK', 'NOK', 'DKK', 'PLN', 'CZK', 'HUF', 'RON', 'BGN',
		'TRY', 'BRL', 'MXN', 'INR', 'ZAR', 'SGD', 'HKD', 'KRW',
		'CNY', 'IDR', 'MYR', 'PHP', 'THB', 'ILS', 'AED', 'SAR',
	);

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id                 = 'cherum_pay';
		$this->method_title       = __( 'Cherum Pay', 'cherum-pay-for-woocommerce' );
		$this->method_description = __( 'Accept stablecoin payments. The buyer picks the coin and the network on the payment page; you are paid in the asset you chose.', 'cherum-pay-for-woocommerce' );
		$this->has_fields         = false;
		/* 'refunds' is the rare one: of the 24 crypto gateways in the directory,
		   two can refund from the WordPress admin. Without this entry WooCommerce
		   never shows the refund amount box on an order, and the shop owner has
		   to go and refund in somebody else's dashboard. */
		$this->supports           = array( 'products', 'refunds' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title', __( 'Pay with crypto', 'cherum-pay-for-woocommerce' ) );
		$this->description = $this->get_option( 'description' );
		$this->enabled     = $this->get_option( 'enabled', 'no' );
		/* The classic checkout shows methods with logos; ours had none in
		   1.1.0 and looked like the odd one out on the very screen where the
		   buyer decides. */
		$this->icon = CHERUM_PAY_URL . 'assets/icon-128x128.png';

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		/* THE DISCOUNT IS NOT HOOKED HERE ANY MORE (1.3.3). It used to be, and
		   on the classic checkout it therefore never fired: WooCommerce builds
		   the gateway objects lazily, on the first call to
		   WC()->payment_gateways(), and all three classic paths total the cart
		   BEFORE that happens — the checkout shortcode (calculate_totals, then
		   the payment section), ?wc-ajax=update_order_review (calculate_totals
		   four lines above woocommerce_order_review) and, worst of all,
		   ?wc-ajax=checkout, where WC_Checkout::update_session() totals the cart
		   and the order is written from those totals. So a shop with "Discount
		   for paying in crypto" set collected the FULL price on the classic
		   checkout, silently, for ever. The hook now lives in the plugin's main
		   file, on plugins_loaded, where nothing has to be instantiated first;
		   the handler is static and reads the setting from the option. */
		/* Safety-net poll on the "order received" page: the one moment the
		   buyer is guaranteed to come back. A lost webhook stops costing the
		   buyer a "pending" screen right when they have just paid. */
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'poll_on_thankyou' ) );
		/* After the poll, so the line reports the status the poll just applied
		   rather than the one the page was rendered with. */
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_note' ), 20 );
	}

	/**
	 * Coins the buyer can pick from, in the order they appear on the payment
	 * page. Logos ship with the plugin; nothing is loaded from elsewhere.
	 *
	 * @return array<string,string> slug => label
	 */
	public static function coin_marks() {
		return array(
			'btc' => 'Bitcoin',
			'eth' => 'Ethereum',
			'sol' => 'Solana',
			'ton' => 'TON',
			'trx' => 'TRON',
			'bnb' => 'BNB',
			'pol' => 'Polygon',
		);
	}

	/**
	 * Logo URLs for the checkout, or an empty list when the shop owner turned
	 * the row off.
	 *
	 * @return array<int,array{src:string,alt:string}>
	 */
	public function coin_icon_urls() {
		if ( 'yes' !== $this->get_option( 'show_coin_icons', 'yes' ) ) {
			return array();
		}
		$out = array();
		foreach ( self::coin_marks() as $slug => $label ) {
			$out[] = array( 'src' => CHERUM_PAY_URL . 'assets/coins/' . $slug . '.svg', 'alt' => $label );
		}
		return $out;
	}

	/**
	 * The classic checkout draws the method's icon HTML itself. With the row
	 * on, it gets the coin logos; with it off, the Cherum mark alone.
	 *
	 * @return string
	 */
	public function get_icon() {
		$coins = $this->coin_icon_urls();
		if ( ! $coins ) {
			return parent::get_icon();
		}
		$html = '<span class="cherum-pay-coins">';
		foreach ( $coins as $c ) {
			$html .= '<img src="' . esc_url( $c['src'] ) . '" alt="' . esc_attr( $c['alt'] ) . '" width="20" height="20" style="width:20px;height:20px;margin-left:4px;vertical-align:middle" />';
		}
		$html .= '</span>';
		return $html;
	}

	/**
	 * Meta key holding EVERY invoice this order has ever had, one row each.
	 *
	 * `_cherum_invoice_id` is the CURRENT invoice and is overwritten on a
	 * retry; this is the memory that is never overwritten.
	 */
	const INVOICE_LOG_META = '_cherum_invoice_seen';

	/**
	 * Every Cherum invoice ever minted for this order, oldest first.
	 *
	 * @param WC_Order $order Order.
	 * @return string[]
	 */
	public static function invoice_history( $order ) {
		$out = array();
		$rows = $order->get_meta( self::INVOICE_LOG_META, false );
		foreach ( (array) $rows as $row ) {
			if ( is_object( $row ) ) {
				$value = isset( $row->value ) ? $row->value : '';
			} elseif ( is_array( $row ) ) {
				$value = isset( $row['value'] ) ? $row['value'] : '';
			} else {
				$value = $row;
			}
			$value = (string) $value;
			if ( '' !== $value && ! in_array( $value, $out, true ) ) {
				$out[] = $value;
			}
		}
		return $out;
	}

	/**
	 * Tie an invoice to this order for good.
	 *
	 * THE ORDER MUST OUTLIVE ITS INVOICES (1.3.3). A retry mints a new invoice
	 * and overwrites `_cherum_invoice_id`; from that moment the store had no
	 * way back from the OLD invoice id to the order, and every notification
	 * about it — money arriving late on its address, and worse, the plain
	 * `invoice.confirmed` and `invoice.credited` of an expired invoice paid
	 * inside the 24-hour late window — was answered "unknown_order" and
	 * dropped without so much as a log line. A deposit address belongs to one
	 * invoice for ever, so the event ALWAYS carries the old id; the order has
	 * to remember it. Kept as repeated meta rows rather than a list in one
	 * value so the lookup is an exact match on both order stores (a LIKE over
	 * order meta is not something the classic storage can do).
	 *
	 * The caller saves the order.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $id    Cherum invoice id.
	 */
	public static function remember_invoice( $order, $id ) {
		$id = (string) $id;
		if ( '' === $id ) {
			return;
		}
		if ( ! in_array( $id, self::invoice_history( $order ), true ) ) {
			$order->add_meta_data( self::INVOICE_LOG_META, $id, false );
		}
	}

	/**
	 * One gateway setting, readable from static contexts (webhook, cron).
	 *
	 * @param string $name    Option name.
	 * @param string $default Fallback.
	 * @return string
	 */
	public static function setting( $name, $default = '' ) {
		$all = get_option( 'woocommerce_cherum_pay_settings', array() );
		return isset( $all[ $name ] ) && '' !== $all[ $name ] ? (string) $all[ $name ] : $default;
	}

	/**
	 * Optional log, gated by the "Write a log" setting.
	 *
	 * In 1.1.0 the setting existed and did nothing. Keys and signatures are
	 * never written — only what happened and when.
	 *
	 * @param string $message Line to record.
	 */
	public static function log( $message ) {
		if ( 'yes' !== self::setting( 'debug', 'no' ) ) {
			return;
		}
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->info( (string) $message, array( 'source' => 'cherum-pay' ) );
		}
	}

	/**
	 * Settings shown to the shop owner.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'        => array(
				'title'   => __( 'Enable', 'cherum-pay-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Offer Cherum Pay at checkout', 'cherum-pay-for-woocommerce' ),
				'default' => 'no',
			),
			'title'          => array(
				'title'       => __( 'Name at checkout', 'cherum-pay-for-woocommerce' ),
				'type'        => 'text',
				'default'     => __( 'Pay with crypto', 'cherum-pay-for-woocommerce' ),
				'desc_tip'    => true,
				'description' => __( 'What the buyer sees in the list of payment methods.', 'cherum-pay-for-woocommerce' ),
			),
			'description'    => array(
				'title'   => __( 'Description at checkout', 'cherum-pay-for-woocommerce' ),
				'type'    => 'textarea',
				'default' => __( 'Bitcoin, Ethereum, USDC, USDT, Solana, TON and more — on the network you already use. No account, no card, no app to install.', 'cherum-pay-for-woocommerce' ),
			),
			'api_key'        => array(
				'title'       => __( 'API key', 'cherum-pay-for-woocommerce' ),
				'type'        => 'password',
				'description' => __( 'Create one in your Cherum dashboard under Developers and paste it here. Tick invoices, webhooks and refunds when you create it. A key that starts with chm_test_ is a rehearsal: orders look real, no money moves. Save, and the plugin connects the store on its own.', 'cherum-pay-for-woocommerce' ),
			),
			'connection'     => array(
				'title'       => __( 'Connection', 'cherum-pay-for-woocommerce' ),
				'type'        => 'title',
				'description' => $this->connection_summary(),
			),
			'webhook_secret' => array(
				'title'       => __( 'Notification secret', 'cherum-pay-for-woocommerce' ),
				'type'        => 'password',
				'description' => __( 'Filled in for you when you save the key. Paste one yourself only if you registered this store in the dashboard by hand.', 'cherum-pay-for-woocommerce' ),
			),
			'expires_min'    => array(
				'title'       => __( 'Invoice lifetime, minutes', 'cherum-pay-for-woocommerce' ),
				'type'        => 'number',
				'default'     => '20',
				'desc_tip'    => true,
				// The browser refuses out-of-range numbers before the form is
				// sent; validate_expires_min_field below is what makes it true
				// for a field filled in by anything other than this screen.
				'custom_attributes' => array( 'min' => '5', 'max' => '1440', 'step' => '1' ),
				'description' => sprintf(
					/* translators: %d is the store's own stock-hold window in minutes. */
					__( 'How long the buyer has to pay before the invoice expires. Between 5 and 1440. Note: WooCommerce holds stock for an unpaid order for %d minutes — set a longer window here and the goods can be sold to someone else while your invoice is still open.', 'cherum-pay-for-woocommerce' ),
					(int) get_option( 'woocommerce_hold_stock_minutes', 60 )
				),
			),
			'discount_pct'   => array(
				'title'       => __( 'Discount for paying in crypto, %', 'cherum-pay-for-woocommerce' ),
				'type'        => 'number',
				'default'     => '0',
				'desc_tip'    => true,
				'custom_attributes' => array( 'min' => '0', 'max' => '90', 'step' => '0.1' ),
				/* Why this exists at all: taking crypto costs the shop less than
				   taking a card, and part of that difference can go back to the
				   buyer. They see the saving BEFORE choosing the method, which is
				   what actually moves them. */
				'description' => __( 'Take this much off the cart when the buyer chooses Cherum Pay. Card processing costs you 2–3%; crypto costs 0.90%, and this is where you can hand part of that back. 0 turns it off.', 'cherum-pay-for-woocommerce' ),
			),
			'discount_label' => array(
				'title'       => __( 'Discount wording', 'cherum-pay-for-woocommerce' ),
				'type'        => 'text',
				'default'     => __( 'Crypto payment discount', 'cherum-pay-for-woocommerce' ),
				'desc_tip'    => true,
				'description' => __( 'The line the buyer sees in the cart totals. Say what it is, not that it is a promotion.', 'cherum-pay-for-woocommerce' ),
			),
			'paid_status'    => array(
				'title'       => __( 'Order status once paid', 'cherum-pay-for-woocommerce' ),
				'type'        => 'select',
				'default'     => 'default',
				'desc_tip'    => true,
				'description' => __( 'What the order becomes when the payment confirms. Leave the default unless your fulfilment depends on a particular status.', 'cherum-pay-for-woocommerce' ),
				'options'     => array(
					'default'    => __( 'WooCommerce decides (processing, or completed for downloads)', 'cherum-pay-for-woocommerce' ),
					'processing' => __( 'Processing', 'cherum-pay-for-woocommerce' ),
					'completed'  => __( 'Completed', 'cherum-pay-for-woocommerce' ),
				),
			),
			'show_coin_icons' => array(
				'title'   => __( 'Coin icons at checkout', 'cherum-pay-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Show a row of coin logos next to the method name', 'cherum-pay-for-woocommerce' ),
				'default' => 'yes',
			),
			'expired_action' => array(
				'title'       => __( 'When the invoice expires unpaid', 'cherum-pay-for-woocommerce' ),
				'type'        => 'select',
				'default'     => 'cancel',
				'desc_tip'    => true,
				/* The e-mail this sentence promises is now actually sent — see
				   Cherum_Pay_Webhook::mail_payment_link(). Until 1.3.3 it was
				   not: WooCommerce sends a customer nothing for an order that
				   stays pending, and the buyer's only way back was a browser
				   tab they had probably closed. */
				'description' => __( 'Cancelling frees the stock. Leaving the order pending keeps the order alive and e-mails the buyer its details, with a link to pay it, when the invoice expires — as long as WooCommerce\'s "Customer invoice / Order details" e-mail is switched on.', 'cherum-pay-for-woocommerce' ),
				'options'     => array(
					'cancel' => __( 'Cancel the order', 'cherum-pay-for-woocommerce' ),
					'keep'   => __( 'Leave it pending', 'cherum-pay-for-woocommerce' ),
				),
			),
			'send_email'     => array(
				'title'       => __( 'Receipt from Cherum', 'cherum-pay-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Send the buyer\'s e-mail address to Cherum so they get a payment receipt', 'cherum-pay-for-woocommerce' ),
				'default'     => 'no',
				'description' => __( 'Off by default: with it off, no personal data leaves your store. Cherum has to have buyer receipts switched on for the letter to be sent — the plugin checks when you save and tells you if it does not.', 'cherum-pay-for-woocommerce' ),
			),
			'debug'          => array(
				'title'       => __( 'Write a log', 'cherum-pay-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Record requests and notifications in WooCommerce → Status → Logs', 'cherum-pay-for-woocommerce' ),
				'default'     => 'no',
				'desc_tip'    => true,
				/* Keys and signatures are never written to the log, and the field
				   says so: "turn on debugging" too often means "put your secrets
				   in a file the whole server can read". */
				'description' => __( 'Off by default. Keys and signatures are never written — only what happened and when.', 'cherum-pay-for-woocommerce' ),
			),
		);
	}

	/**
	 * The invoice window the service will accept, in minutes.
	 *
	 * One definition for the settings screen and for the invoice itself: while
	 * they were two, the shop owner could save "2" and get a 5-minute invoice
	 * without ever being told.
	 *
	 * @param mixed $raw Whatever is in the setting.
	 * @return int
	 */
	private static function clamp_minutes( $raw ) {
		return max( 5, min( 1440, (int) $raw ) );
	}

	/**
	 * A saved setting must be a setting that works (1.3.2).
	 *
	 * Both number fields were plain inputs that accepted anything and were
	 * silently corrected — or silently ignored — later: "95" in the discount
	 * saved fine and no discount ever appeared (over 90% is refused where the
	 * cart fee is added), and "2" minutes was quietly turned into 5 when the
	 * invoice was created. A shop owner who sees their number in the form is
	 * entitled to believe it is the number in force. These clamp on save and
	 * say what was actually written.
	 *
	 * @param string $key   Field id.
	 * @param string $value Value posted by the form.
	 * @return string
	 */
	public function validate_discount_pct_field( $key, $value ) {
		$given = (float) $this->validate_text_field( $key, $value );
		$kept  = max( 0, min( 90, $given ) );
		if ( abs( $kept - $given ) > 0.0001 && class_exists( 'WC_Admin_Settings' ) ) {
			WC_Admin_Settings::add_error(
				sprintf(
					/* translators: 1: the number typed, 2: the number stored. */
					__( 'Cherum Pay: a crypto discount of %1$s%% cannot be offered; %2$s%% was saved instead. The discount has to stay between 0 and 90.', 'cherum-pay-for-woocommerce' ),
					$given,
					$kept
				)
			);
		}
		return (string) $kept;
	}

	/**
	 * Invoice lifetime: clamped to the window the service accepts.
	 *
	 * @param string $key   Field id.
	 * @param string $value Value posted by the form.
	 * @return string
	 */
	public function validate_expires_min_field( $key, $value ) {
		$given = (int) $this->validate_text_field( $key, $value );
		$kept  = self::clamp_minutes( $given );
		if ( $kept !== $given && class_exists( 'WC_Admin_Settings' ) ) {
			WC_Admin_Settings::add_error(
				sprintf(
					/* translators: 1: the number typed, 2: the number stored. */
					__( 'Cherum Pay: an invoice lifetime of %1$d minutes is outside what Cherum accepts; %2$d minutes was saved instead. It has to stay between 5 and 1440.', 'cherum-pay-for-woocommerce' ),
					$given,
					$kept
				)
			);
		}
		return (string) $kept;
	}

	/**
	 * The crypto discount, as a cart fee.
	 *
	 * A NEGATIVE fee rather than a coupon on purpose: a coupon shows up in
	 * reports as a marketing campaign and can be combined, removed or shared —
	 * this is neither a campaign nor something the buyer can keep. It appears
	 * only while Cherum Pay is the selected method and disappears the moment it
	 * is not, which is exactly what it means.
	 *
	 * STATIC, AND HOOKED FROM THE MAIN FILE (1.3.3). See the constructor: an
	 * instance method hooked from the constructor is a method that never runs
	 * on the classic checkout, because the gateway object does not exist yet
	 * when the classic checkout totals the cart. Nothing here needs an
	 * instance — the two settings it reads come from the option.
	 */
	public static function add_crypto_discount() {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}
		/* The gateway's own availability, re-asked without an instance. The
		   constructor hook used to imply this: no gateway, no discount. A
		   disabled method, a store with no key or a currency Cherum cannot
		   price must not take money off a cart nobody can pay with here. */
		if ( 'yes' !== self::setting( 'enabled', 'no' ) || '' === self::setting( 'api_key' ) ) {
			return;
		}
		if ( ! in_array( get_woocommerce_currency(), self::CURRENCIES, true ) ) {
			return;
		}
		$pct = (float) self::setting( 'discount_pct', '0' );
		if ( $pct <= 0 || $pct > 90 ) {
			return;
		}
		if ( ! function_exists( 'WC' ) || ! WC()->session
			|| WC()->session->get( 'chosen_payment_method' ) !== 'cherum_pay' ) {
			return;
		}
		$cart = WC()->cart;
		if ( ! $cart || $cart->is_empty() ) {
			return;
		}
		/* WHAT THE BUYER IS ACTUALLY PAYING FOR THE GOODS, AFTER COUPONS
		   (1.3.3). The base used to be the subtotal, which is the price BEFORE
		   coupons: with a 50% coupon and a 2% crypto discount the shop handed
		   back 2% of the full price — twice what it meant to — and at 90% next
		   to a 50% coupon the cart total reached 0.00, at which point
		   WooCommerce completes the order without calling any gateway at all.
		   cart_contents_total is the post-coupon goods total and it is already
		   final when fees are calculated (WC_Cart_Totals::calculate() runs
		   calculate_item_totals before calculate_fee_totals), so no ordering
		   assumption is being made here. Shipping stays out, as before: the
		   discount is on the goods. */
		$base = (float) $cart->get_cart_contents_total() + (float) $cart->get_cart_contents_tax();
		if ( $base <= 0 ) {
			return;
		}
		$off = round( $base * $pct / 100, wc_get_price_decimals() );
		if ( $off <= 0 ) {
			return;
		}
		$label = self::setting( 'discount_label' );
		$cart->add_fee( '' !== $label ? $label : __( 'Crypto payment discount', 'cherum-pay-for-woocommerce' ), -$off, false );
	}

	/**
	 * Kept so anything that hooked the old instance method still works.
	 *
	 * @deprecated 1.3.3 Use Cherum_Pay_Gateway::add_crypto_discount().
	 */
	public function maybe_add_discount() {
		self::add_crypto_discount();
	}

	/**
	 * Whether the method may be offered at all.
	 *
	 * Refusing loudly in the admin beats a checkout that fails after the buyer
	 * has already chosen how to pay.
	 *
	 * @return bool
	 */
	public function is_available() {
		if ( 'yes' !== $this->enabled ) {
			return false;
		}
		if ( '' === (string) $this->get_option( 'api_key' ) ) {
			return false;
		}
		return in_array( get_woocommerce_currency(), self::CURRENCIES, true );
	}

	/**
	 * Notice in the settings screen when the shop currency is not supported.
	 */
	public function admin_options() {
		if ( ! in_array( get_woocommerce_currency(), self::CURRENCIES, true ) ) {
			echo '<div class="notice notice-warning inline"><p>'
				. sprintf(
					/* translators: %s: the shop's currency code. */
					esc_html__( 'Your shop is in %s, which Cherum Pay cannot price an invoice in yet. The method stays hidden at checkout until the shop currency is one it supports.', 'cherum-pay-for-woocommerce' ),
					esc_html( get_woocommerce_currency() )
				)
				. '</p></div>';
		}
		$key = (string) $this->get_option( 'api_key' );
		if ( '' !== $key && 0 === strpos( $key, 'chm_test_' ) ) {
			/* THE EDGE OF THE REHEARSAL IS NAMED (1.3.3). The notice used to
			   say orders look real and no money moves, and stopped there — so
			   the first the shop owner heard of the one thing rehearsal cannot
			   do was a refusal from the service after pressing Refund on a real
			   order. Refunds move real money and Cherum will not open, price or
			   cancel one for a test key. */
			echo '<div class="notice notice-info inline"><p>'
				. esc_html__( 'Rehearsal mode: this is a test key. Orders look real, the payment page shows a practice address, and no money moves. Swap in a live key when you are ready.', 'cherum-pay-for-woocommerce' )
				. ' '
				. esc_html__( 'Refunds are the one thing rehearsal does not cover: they move real money, so Cherum accepts them only from a live key.', 'cherum-pay-for-woocommerce' )
				. '</p></div>';
		}
		/* The answer from the last save, repeated on every load: the shop owner
		   who ignores an error message once should still see the state of the
		   thing they switched on. */
		if ( 'off' === (string) $this->get_option( 'receipt_feature' ) && 'yes' === $this->get_option( 'send_email', 'no' ) ) {
			echo '<div class="notice notice-warning inline"><p>'
				. esc_html__( 'Cherum is not sending buyer receipts at the moment, so "Receipt from Cherum" hands over the buyer\'s e-mail address for a letter that will not be sent. Turn it off until Cherum enables receipts.', 'cherum-pay-for-woocommerce' )
				. '</p></div>';
		}
		/* WHOSE NAME THE BUYER SEES. The payment page prints the name and logo
		   from the Cherum account, not from WordPress — an account with neither
		   is headed "Cherum Pay", and the buyer meets a seller they have never
		   heard of at the moment they are about to send money. Nothing here can
		   fix that for them, but nobody told them either. */
		if ( '' !== $key ) {
			echo '<p class="description">'
				. esc_html__( 'The payment page shows the business name and logo from your Cherum account, under Settings → Checkout branding. Set them once, or your buyers see "Cherum Pay" where your shop name belongs.', 'cherum-pay-for-woocommerce' )
				. '</p>';
		}
		/* A number saved by an older version (or by WP-CLI) can still be outside
		   what works. Say so where it is set, instead of leaving the shop owner
		   with a discount that never appears at checkout. */
		$pct = (float) $this->get_option( 'discount_pct', '0' );
		if ( $pct > 90 || $pct < 0 ) {
			echo '<div class="notice notice-warning inline"><p>'
				. esc_html(
					sprintf(
						/* translators: %s: the stored discount percentage. */
						__( 'The crypto discount is set to %s%%, which is outside 0–90, so no discount is being applied at checkout. Save this screen to correct it.', 'cherum-pay-for-woocommerce' ),
						$pct
					)
				)
				. '</p></div>';
		}
		$mins_set = (int) $this->get_option( 'expires_min', 20 );
		if ( $mins_set !== self::clamp_minutes( $mins_set ) ) {
			echo '<div class="notice notice-warning inline"><p>'
				. esc_html(
					sprintf(
						/* translators: 1: the stored lifetime, 2: the lifetime in force. */
						__( 'The invoice lifetime is set to %1$d minutes, which Cherum does not accept; invoices are being created with %2$d. Save this screen to correct it.', 'cherum-pay-for-woocommerce' ),
						$mins_set,
						self::clamp_minutes( $mins_set )
					)
				)
				. '</p></div>';
		}
		$status = (string) $this->get_option( 'webhook_status' );
		if ( 'yes' === $this->get_option( 'enabled' ) && '' !== $key && '' === (string) $this->get_option( 'webhook_secret' ) ) {
			echo '<div class="notice notice-warning inline"><p>'
				. esc_html__( 'Notifications are not connected yet, so orders update through the fallback check every 15 minutes instead of within seconds.', 'cherum-pay-for-woocommerce' )
				. ( '' !== $status ? ' ' . esc_html( $status ) : '' )
				. '</p></div>';
		}
		parent::admin_options();
	}

	/**
	 * Save the settings, then connect the store.
	 *
	 * Every other gateway in the directory makes the shop owner register a
	 * webhook by hand and copy a secret across two tabs. The key already has
	 * the permission to do that, so the plugin does it: find this store among
	 * the key's endpoints, create it if it is missing, keep the secret.
	 *
	 * @return bool
	 */
	public function process_admin_options() {
		$saved = parent::process_admin_options();
		$this->init_settings();
		$this->connect_notifications();
		$this->check_receipt_feature();
		return $saved;
	}

	/**
	 * Does Cherum actually send buyer receipts right now?
	 *
	 * WHY THIS ASKS (1.3.3). "Receipt from Cherum" sends the buyer's e-mail
	 * address out of the store so Cherum can e-mail them a receipt. Whether
	 * Cherum sends that receipt is an operational switch on Cherum's side, and
	 * it has been OFF in production since 29 August: every store that ticked
	 * the box handed over a personal e-mail address for a letter that could
	 * not be sent. The store had no way to know — so the key introspection
	 * (`GET /me`) now reports it, and the answer is kept in the settings for
	 * the notice on this screen. Asked only when the box is ticked: a store
	 * that sends nothing personal has no question to ask.
	 */
	private function check_receipt_feature() {
		if ( 'yes' !== $this->get_option( 'send_email', 'no' ) || '' === (string) $this->get_option( 'api_key' ) ) {
			$this->update_option( 'receipt_feature', '' );
			return;
		}
		$res = ( new Cherum_Pay_Api( (string) $this->get_option( 'api_key' ) ) )->me();
		if ( ! $res['ok'] || ! isset( $res['data']['features'] ) ) {
			// An older service, or no answer: say nothing rather than guess.
			$this->update_option( 'receipt_feature', '' );
			return;
		}
		$on = ! empty( $res['data']['features']['buyerReceipt'] );
		$this->update_option( 'receipt_feature', $on ? 'on' : 'off' );
		if ( ! $on && class_exists( 'WC_Admin_Settings' ) ) {
			WC_Admin_Settings::add_error(
				__( 'Cherum Pay: "Receipt from Cherum" is on, but Cherum is not sending buyer receipts at the moment. The buyer\'s e-mail address would leave your store for a letter that will not be sent — turn the setting off until Cherum enables receipts.', 'cherum-pay-for-woocommerce' )
			);
		}
	}

	/**
	 * Make sure Cherum knows where to send notifications for this store.
	 *
	 * Outcomes are written into the settings (endpoint id, secret, a sentence
	 * for the settings screen) so the next page load can say what happened
	 * without calling anyone.
	 */
	public function connect_notifications() {
		$key = (string) $this->get_option( 'api_key' );
		if ( '' === $key ) {
			// The only case that really clears the endpoint number: with no key
			// there is no account this store could be connected to.
			$this->remember_connection( 0, '' );
			return;
		}
		$url = untrailingslashit( rest_url( 'cherum-pay/v1/webhook' ) );
		if ( 0 !== strpos( $url, 'https://' ) || preg_match( '#^https://(localhost|127\.|10\.|192\.168\.|[^/]+\.local)#i', $url ) ) {
			$this->remember_connection( '', __( 'Notifications need a public https address, which this site does not have yet. Orders will update through the 15-minute check until it does.', 'cherum-pay-for-woocommerce' ) );
			return;
		}
		$api  = new Cherum_Pay_Api( $key );
		$list = $api->list_webhooks();
		if ( ! $list['ok'] ) {
			if ( 401 === $list['status'] ) {
				$this->remember_connection( '', __( 'Cherum does not recognise this key. Check it for a missing character and save again.', 'cherum-pay-for-woocommerce' ) );
			} elseif ( 403 === $list['status'] ) {
				$this->remember_connection( '', __( 'This key can create invoices but is not allowed to manage notifications. Create a key with the webhooks permission, or register the store in the dashboard by hand and paste the secret below.', 'cherum-pay-for-woocommerce' ) );
			} else {
				$this->remember_connection(
					'',
					sprintf(
						/* translators: %s: the message from the service or the network. */
						__( 'Could not reach Cherum to connect the store: %s. Save again in a minute.', 'cherum-pay-for-woocommerce' ),
						$list['error']
					)
				);
			}
			return;
		}
		$mine = null;
		foreach ( (array) ( isset( $list['data']['webhooks'] ) ? $list['data']['webhooks'] : array() ) as $row ) {
			if ( isset( $row['url'] ) && untrailingslashit( (string) $row['url'] ) === $url ) {
				$mine = $row;
				break;
			}
		}
		$secret = (string) $this->get_option( 'webhook_secret' );
		$known  = (int) $this->get_option( 'webhook_endpoint_id' );
		if ( $mine && '' !== $secret && $known === (int) $mine['id'] ) {
			$this->remember_connection( (int) $mine['id'], '' );
			return;
		}
		if ( $mine ) {
			/* The store exists on Cherum's side but this copy of WordPress does
			   not hold its secret (a fresh install, a restored backup): issue a
			   new one. The previous secret keeps working for a day. */
			$rot = $api->rotate_webhook_secret( (int) $mine['id'] );
			if ( ! $rot['ok'] ) {
				$this->remember_connection(
					'',
					sprintf(
						/* translators: %s: the message from the service. */
						__( 'The store is registered but a new secret could not be issued: %s', 'cherum-pay-for-woocommerce' ),
						$rot['error']
					)
				);
				return;
			}
			$this->remember_connection( (int) $mine['id'], '', (string) ( isset( $rot['data']['webhook']['secret'] ) ? $rot['data']['webhook']['secret'] : '' ) );
			return;
		}
		$made = $api->create_webhook( $url );
		if ( ! $made['ok'] ) {
			$this->remember_connection(
				'',
				sprintf(
					/* translators: %s: the message from the service. */
					__( 'Cherum refused to register this store: %s', 'cherum-pay-for-woocommerce' ),
					$made['error']
				)
			);
			return;
		}
		$this->remember_connection(
			(int) ( isset( $made['data']['webhook']['id'] ) ? $made['data']['webhook']['id'] : 0 ),
			'',
			(string) ( isset( $made['data']['webhook']['secret'] ) ? $made['data']['webhook']['secret'] : '' )
		);
	}

	/**
	 * Write the connection outcome into the settings.
	 *
	 * A FAILURE DOES NOT ERASE WHAT WE KNOW (1.3.2). Every error branch used to
	 * pass '' as the endpoint id, so one unreachable minute wiped a perfectly
	 * good endpoint number — and because the secret survived, the next
	 * successful save no longer recognised its own endpoint and rolled the
	 * secret for nothing. The id is a fact about a connection that did happen;
	 * only an empty API key (nothing to be connected to) clears it.
	 *
	 * @param int|string $endpoint_id Endpoint id, '' to keep the stored one,
	 *                                or 0 to clear it (no key configured).
	 * @param string     $problem     Sentence for the shop owner, '' when fine.
	 * @param string     $secret      New secret to keep, '' to leave as is.
	 */
	private function remember_connection( $endpoint_id, $problem, $secret = '' ) {
		if ( '' !== $endpoint_id ) {
			$this->settings['webhook_endpoint_id'] = 0 === (int) $endpoint_id ? '' : (string) (int) $endpoint_id;
		}
		$this->settings['webhook_status']     = (string) $problem;
		$this->settings['webhook_checked_at'] = (string) time();
		if ( '' !== $secret ) {
			$this->settings['webhook_secret'] = $secret;
		}
		update_option( $this->get_option_key(), $this->settings, 'yes' );
		self::log( '' !== $problem ? 'connect: ' . $problem : 'connect: endpoint #' . $this->settings['webhook_endpoint_id'] );
	}

	/**
	 * One sentence for the settings screen: what the connection looks like.
	 *
	 * Read straight from the option, because the form fields are built before
	 * the gateway's own settings are loaded.
	 *
	 * @return string
	 */
	private function connection_summary() {
		$o   = (array) get_option( 'woocommerce_cherum_pay_settings', array() );
		$key = (string) ( isset( $o['api_key'] ) ? $o['api_key'] : '' );
		if ( '' === $key ) {
			return __( 'Not connected. Paste an API key and save.', 'cherum-pay-for-woocommerce' );
		}
		$mode = 0 === strpos( $key, 'chm_test_' )
			? __( 'test key', 'cherum-pay-for-woocommerce' )
			: __( 'live key', 'cherum-pay-for-woocommerce' );
		$status = (string) ( isset( $o['webhook_status'] ) ? $o['webhook_status'] : '' );
		if ( '' !== $status ) {
			return sprintf(
				/* translators: 1: "test key" or "live key", 2: what went wrong. */
				__( 'Key saved (%1$s). Notifications: %2$s', 'cherum-pay-for-woocommerce' ),
				$mode,
				$status
			);
		}
		if ( '' !== (string) ( isset( $o['webhook_secret'] ) ? $o['webhook_secret'] : '' ) ) {
			return sprintf(
				/* translators: 1: "test key" or "live key", 2: endpoint id. */
				__( 'Connected (%1$s). Cherum sends notifications to this store, endpoint #%2$s.', 'cherum-pay-for-woocommerce' ),
				$mode,
				(string) ( isset( $o['webhook_endpoint_id'] ) ? $o['webhook_endpoint_id'] : '?' )
			);
		}
		return sprintf(
			/* translators: %s: "test key" or "live key". */
			__( 'Key saved (%s). Notifications are not connected yet: save once more to connect.', 'cherum-pay-for-woocommerce' ),
			$mode
		);
	}

	/**
	 * Create the invoice and send the buyer to it.
	 *
	 * @param int $order_id Order id.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return array( 'result' => 'failure' );
		}

		$api  = new Cherum_Pay_Api( (string) $this->get_option( 'api_key' ) );
		$mins = self::clamp_minutes( $this->get_option( 'expires_min', 20 ) );

		/* A SECOND ATTEMPT MUST NOT LAND ON A DEAD INVOICE (1.3.2).
		 *
		 * The idempotency key below is per order and stable, and the service
		 * replays the stored answer for it for 24 hours. That is right for a
		 * double click and wrong for the buyer who comes back the next hour:
		 * with "Leave it pending" set, the order is still payable while its
		 * invoice has expired, and "Pay for order" walked them into a page
		 * reading "This invoice has expired" whose only way out led back to
		 * the same page. Found on the live demo store, 02.09.
		 *
		 * So: ask what the stored invoice is doing now. Still payable — send
		 * them back to it (one invoice, no second note). Dead — count the
		 * attempt, which changes the key and mints a fresh invoice. Already
		 * paid — apply it instead of creating anything: minting here would let
		 * the same order be paid twice. Unreachable — keep the old key: one
		 * stale invoice beats two live ones.
		 */
		$attempt = (int) $order->get_meta( '_cherum_pay_attempt' );
		$known   = (string) $order->get_meta( '_cherum_invoice_id' );
		if ( '' !== $known ) {
			$cur    = $api->get_invoice( $known );
			$status = isset( $cur['data']['invoice']['status'] ) ? (string) $cur['data']['invoice']['status'] : '';
			if ( in_array( $status, array( 'new', 'seen' ), true ) ) {
				$url = isset( $cur['data']['invoice']['checkoutUrl'] )
					? (string) $cur['data']['invoice']['checkoutUrl']
					: (string) $order->get_meta( '_cherum_checkout_url' );
				if ( '' !== $url ) {
					self::log( 'order ' . $order->get_id() . ': invoice ' . $known . ' is still ' . $status . ' — buyer sent back to it' );
					return array( 'result' => 'success', 'redirect' => self::with_lang( $url ) );
				}
			} elseif ( in_array( $status, array( 'confirmed', 'settled' ), true ) ) {
				/* Paid already — the notification has not reached us yet. Apply
				   it through the same path the webhook uses and take the buyer
				   to the "order received" page instead of a new invoice. */
				self::log( 'order ' . $order->get_id() . ': invoice ' . $known . ' is ' . $status . ' — applying instead of creating a new one' );
				self::poll_order( $order );
				return array( 'result' => 'success', 'redirect' => $this->get_return_url( $order ) );
			} elseif ( '' !== $status ) {
				++$attempt;
				$order->update_meta_data( '_cherum_pay_attempt', (string) $attempt );
				/* The invoice about to be replaced is written into the order's
				   memory HERE, before it stops being the current one: an order
				   created by an older version has no history yet, and this is
				   the last moment its old invoice can still be recorded. */
				self::remember_invoice( $order, $known );
				$order->save();
				self::log( 'order ' . $order->get_id() . ': invoice ' . $known . ' is ' . $status . ' — asking for a new one (attempt ' . $attempt . ')' );
			}
		}

		$body = array(
			'amount'      => (float) $order->get_total(),
			'currency'    => $order->get_currency(),
			'orderId'     => (string) $order->get_id(),
			// What the buyer is paying for. Without it the payment page shows
			// an amount and a seller but never the one line a person looks for.
			'description' => $this->describe( $order ),
			// Where the buyer goes after paying, and where the "back to the
			// shop" link on the payment page points. The service checks both
			// for shape, not for ownership (corrected in 1.3.2, the comment
			// here used to claim more than the service does): a public https
			// address with no credentials in it, not inside cherum.io and not
			// dressed up to look like it, up to 1024 characters. Whether the
			// address belongs to this shop is on us — both come from
			// WooCommerce's own order methods below, never from a request.
			//
			// `cancelUrl` IS THE WAY BACK, NOT A CANCELLATION (1.3.1). Until now
			// it carried get_cancel_order_url_raw() — a nonced link that cancels
			// the order the instant it is opened. The payment page renders it as
			// a quiet "Back to the shop", so a buyer who wanted to look at the
			// basket once more silently lost the order and came back to an empty
			// checkout. The address now goes to the order's own pay page, which
			// shows the order, lets them pay again or pick another method, and
			// changes no state. Cancelling stays an explicit action inside the
			// shop, where WooCommerce asks first.
			'returnUrl'   => $this->get_return_url( $order ),
			'cancelUrl'   => $order->get_checkout_payment_url(),
			'expiresInMinutes' => $mins,
		);

		/* The buyer's e-mail leaves the store only when the shop owner asked for
		   Cherum's receipt; the default sends nothing personal. */
		if ( 'yes' === $this->get_option( 'send_email', 'no' ) && is_email( $order->get_billing_email() ) ) {
			$body['customerEmail'] = $order->get_billing_email();
		}
		/* The key is per order and stable WITHIN ONE ATTEMPT, so a double click
		   reuses the invoice instead of minting a second one the buyer might
		   pay. The attempt number above is what makes a later, deliberate retry
		   a new invoice rather than a replay of a dead one. */
		$idem = 'wc-' . $order->get_id() . '-' . $order->get_order_key();
		if ( $attempt > 0 ) {
			$idem .= '-' . $attempt;
		}
		$res = $api->create_invoice( $body, $idem );

		if ( ! $res['ok'] ) {
			/* A NETWORK FAILURE IS NOT A REFUSAL (1.3.2). Until now the buyer
			   was shown whatever came back, so a timeout read as "Could not
			   start the payment: cURL error 28: Operation timed out" — our
			   plumbing, in their face, with no idea whether they owe money.
			   Status 0 is the one case that means "we never got an answer";
			   the technical line goes to the log, where the shop owner is. */
			$buyer_message = 0 === (int) $res['status']
				? __( 'The payment service did not answer just now. Nothing has been charged — please try again in a moment.', 'cherum-pay-for-woocommerce' )
				: sprintf(
					/* translators: %s: message from the payment service. */
					__( 'Could not start the payment: %s', 'cherum-pay-for-woocommerce' ),
					$res['error']
				);
			wc_add_notice( esc_html( $buyer_message ), 'error' );
			$order->add_order_note(
				sprintf(
					/* translators: %s: message from the payment service. */
					__( 'Cherum Pay: creating the invoice failed — %s', 'cherum-pay-for-woocommerce' ),
					esc_html( $res['error'] )
				)
			);
			self::log( 'invoice creation failed for order ' . $order->get_id() . ': ' . $res['error'] );
			return array( 'result' => 'failure' );
		}

		$invoice = isset( $res['data']['invoice'] ) ? $res['data']['invoice'] : array();
		$url     = isset( $invoice['checkoutUrl'] ) ? (string) $invoice['checkoutUrl'] : '';
		$id      = isset( $invoice['id'] ) ? (string) $invoice['id'] : '';
		if ( '' === $url || '' === $id ) {
			wc_add_notice( __( 'The payment service answered without a payment link. Nothing was charged — please try again.', 'cherum-pay-for-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		// The id is how a notification finds this order later. Stored before
		// the redirect: the buyer may pay before the browser comes back.
		$is_new_invoice = ( $id !== $known );
		$order->update_meta_data( '_cherum_invoice_id', $id );
		self::remember_invoice( $order, $id );
		$order->update_meta_data( '_cherum_checkout_url', $url );
		/* The invoice's own USD value and the order total AT CREATION. Refunds
		   are quoted in dollars; for a shop in another currency the pair gives
		   the exact per-order rate — the same one the buyer was charged at. */
		if ( isset( $invoice['amountUsd'] ) && is_numeric( $invoice['amountUsd'] ) ) {
			$order->update_meta_data( '_cherum_invoice_usd', (string) $invoice['amountUsd'] );
			$order->update_meta_data( '_cherum_order_total', (string) $order->get_total() );
		}
		$order->save();
		self::log( 'invoice ' . $id . ' created for order ' . $order->get_id() );
		/* A note, not a status change: the order is already "pending" by the
		   time a gateway is called — the classic checkout creates it that way
		   and the Store API sets it just before calling us. Re-setting the same
		   status changes nothing and only adds a second entry to the order's
		   history every time.
		   ONLY WHEN THE INVOICE IS ACTUALLY NEW (1.3.2): the service answers a
		   repeated attempt with the invoice it already has, and writing "invoice
		   created" again turned the order history into a list of events that
		   never happened. */
		if ( $is_new_invoice ) {
			$order->add_order_note( __( 'Cherum Pay: invoice created, waiting for payment.', 'cherum-pay-for-woocommerce' ) );
		}

		/* THE CART IS LEFT ALONE ON PURPOSE, and this is a correction: it used
		   to be emptied right here, before the buyer had even seen the payment
		   page. Abandon the payment — decide the fee is too high, lose the tab,
		   change your mind — and the basket was gone too. The buyer then has to
		   rebuild an order they never cancelled.
		
		   WooCommerce empties the cart itself on the "order received" page once
		   the order is actually placed and paid, which is the right moment.
		   Emptying it here was a gateway doing the checkout's job, early.
		
		   Stock is left alone for the same reason. WooCommerce reduces it when
		   an order reaches on-hold / processing / completed, and calling
		   wc_reduce_stock_levels() at "pending" holds goods for every invoice
		   nobody ever pays — a shop with a slow day would show items as sold
		   out on the strength of abandoned checkouts. Our own webhook calls
		   payment_complete() when the money actually arrives, and that reduces
		   stock through the core path. */

		return array( 'result' => 'success', 'redirect' => self::with_lang( $url ) );
	}

	/**
	 * Add the store's language to a Cherum payment link.
	 *
	 * The payment page carries six translations and picks one from `?lang=`
	 * first. Without the parameter a Russian shop sent its buyers to an English
	 * payment page — the translations were there and nobody could reach them.
	 *
	 * Only languages the payment page actually has are named; anything else is
	 * left off, and the page then falls back to the buyer's own browser.
	 * Guessing a locale it cannot serve would be worse than saying nothing.
	 *
	 * @param string $url Payment page address as the service returned it.
	 * @return string
	 */
	public static function with_lang( $url ) {
		if ( '' === (string) $url ) {
			return (string) $url;
		}
		/* determine_locale() is get_locale() on a plain shop and the language
		   the buyer is actually reading on a translated one. */
		$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
		$map    = array(
			'ru'    => 'ru',
			'es'    => 'es',
			'id'    => 'id',
			'ar'    => 'ar',
			'pt_BR' => 'pt-BR',
			'zh_CN' => 'zh-CN',
		);
		$lang = '';
		if ( isset( $map[ $locale ] ) ) {
			$lang = $map[ $locale ];
		} else {
			$short = substr( (string) $locale, 0, 2 );
			/* Spanish ships as es_ES, es_MX, es_AR…; the page has one Spanish.
			   Portuguese and Chinese do NOT collapse this way: pt_PT and zh_TW
			   are not what we translated, so only the exact locales above. */
			if ( isset( $map[ $short ] ) ) {
				$lang = $map[ $short ];
			}
		}
		if ( '' === $lang ) {
			return (string) $url;
		}
		return add_query_arg( 'lang', $lang, (string) $url );
	}

	/**
	 * A line on "order received" for an order paid with Cherum.
	 *
	 * The buyer has just come back from the payment page and the WooCommerce
	 * text says nothing about crypto: whether the transfer arrived, and where
	 * the receipt lives. Both are one sentence, and the receipt is the payment
	 * page itself — it keeps the transaction, the amount and the address, and
	 * it opens the same in a month.
	 *
	 * @param int $order_id Order id.
	 */
	public function thankyou_note( $order_id ) {
		/* Re-read: the safety-net poll above may have just closed this order. */
		$order = wc_get_order( $order_id );
		if ( ! $order || 'cherum_pay' !== $order->get_payment_method() ) {
			return;
		}
		$url = self::with_lang( (string) $order->get_meta( '_cherum_checkout_url' ) );
		if ( $order->is_paid() ) {
			$text = __( 'Your crypto payment is confirmed.', 'cherum-pay-for-woocommerce' );
			$link = __( 'Open the payment receipt', 'cherum-pay-for-woocommerce' );
		} else {
			$text = __( 'Your crypto payment has not arrived yet. This page and your order update by themselves once the network confirms it — usually within minutes.', 'cherum-pay-for-woocommerce' );
			$link = __( 'Open the payment page', 'cherum-pay-for-woocommerce' );
		}
		echo '<p class="cherum-pay-thankyou">' . esc_html( $text );
		if ( '' !== $url ) {
			echo ' <a href="' . esc_url( $url ) . '" rel="noopener noreferrer">' . esc_html( $link ) . '</a>';
		}
		echo '</p>';
	}

	/**
	 * One line describing the order.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	private function describe( $order ) {
		$names = array();
		foreach ( $order->get_items() as $item ) {
			$names[] = $item->get_name();
			if ( count( $names ) >= 3 ) {
				break;
			}
		}
		if ( empty( $names ) ) {
			return sprintf(
				/* translators: %d: order number. */
				__( 'Order #%d', 'cherum-pay-for-woocommerce' ),
				$order->get_id()
			);
		}
		$line = implode( ', ', $names );
		if ( count( $order->get_items() ) > 3 ) {
			$line .= ' …';
		}
		return mb_substr( $line, 0, 500 );
	}

	/**
	 * Refund from the WordPress order screen.
	 *
	 * WHY THIS EXISTS. A refund is the moment a shop owner is most exposed: the
	 * customer is already unhappy, and every extra step — "log into another
	 * dashboard, find the invoice, copy the amount" — is a step where the wrong
	 * number gets typed. Doing it where the order already is removes that.
	 *
	 * WHY IT ASKS FIRST. The refund can be impossible for reasons WooCommerce
	 * cannot know: the balance is short, the network cost exceeds the amount,
	 * the invoice never settled. Asking for a quote first turns a bare "Refund
	 * failed" into a sentence naming the reason.
	 *
	 * WHY THE KEY. WordPress lets an admin click Refund twice. A second refund
	 * is money leaving twice, so the request carries an idempotency key built
	 * from the order, the amount and the reason: the retry returns the SAME
	 * refund instead of creating another.
	 *
	 * AND WHY THE KEY ALSO CARRIES A SEQUENCE (1.3.3). That key was the same
	 * for two DELIBERATE refunds of the same amount with the same reason — two
	 * £10 refunds a week apart, reason left blank — so the service replayed the
	 * first request (its own answer to a repeated key on an open request is a
	 * 200 with the refund it already has), the plugin read "ok" and returned
	 * true, and WooCommerce wrote a SECOND refund line for money that left
	 * once. The books then said more had been refunded than had been. The
	 * sequence moves on only after a refund is accepted, so a retry after a
	 * timeout still replays — which is the property that key was there for —
	 * while a second deliberate refund gets a key of its own. The refund ids
	 * this order has already had are kept as well, and an answer repeating one
	 * of them is refused rather than counted twice.
	 *
	 * A REFUSAL IS WRITTEN DOWN (1.3.3). Every "no" below used to exist only
	 * in the pop-up the shop owner then closed: no order note, no log line, and
	 * the reason gone. Now each one is on the order, where the next person to
	 * look can read it.
	 *
	 * @param int    $order_id Order being refunded.
	 * @param float  $amount   Amount in the shop currency; null means full.
	 * @param string $reason   Reason typed by the shop owner.
	 * @return bool|WP_Error True on success, WP_Error with a readable message.
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return new WP_Error( 'cherum_no_order', __( 'Order not found.', 'cherum-pay-for-woocommerce' ) );
		}
		$invoice_id = $order->get_meta( '_cherum_invoice_id' );
		if ( ! $invoice_id ) {
			return self::refund_refused(
				$order,
				'cherum_no_invoice',
				__( 'This order was not paid through Cherum Pay, so there is nothing to refund here.', 'cherum-pay-for-woocommerce' )
			);
		}
		$key = $this->get_option( 'api_key' );
		if ( ! $key ) {
			return self::refund_refused(
				$order,
				'cherum_no_key',
				__( 'Add your Cherum API key in the payment settings before refunding.', 'cherum-pay-for-woocommerce' )
			);
		}
		/* REHEARSAL STOPS AT REFUNDS, AND IT SAYS SO HERE (1.3.3). A test key
		   is refused by the service on both the quote and the refund itself
		   ("Refunds move real money — call this with a chm_live_ key"), and the
		   shop owner used to learn that only after pressing the button on a
		   real order. Two network calls and a raw service refusal, for
		   something knowable from the key. */
		if ( 0 === strpos( (string) $key, 'chm_test_' ) ) {
			return self::refund_refused(
				$order,
				'cherum_test_key',
				__( 'This store is connected with a rehearsal key (chm_test_), and rehearsal does not cover refunds — they move real money. Connect a live key (chm_live_) to refund through Cherum, or refund this order by another route.', 'cherum-pay-for-woocommerce' )
			);
		}
		$amount = null === $amount ? (float) $order->get_total() : (float) $amount;
		if ( $amount <= 0 ) {
			return self::refund_refused( $order, 'cherum_bad_amount', __( 'Enter an amount above zero.', 'cherum-pay-for-woocommerce' ) );
		}

		/* Refunds are quoted in US dollars. In 1.1.0 the shop-currency amount
		   was sent as dollars unconverted — for a EUR or JPY shop that is the
		   wrong sum on the one action where a wrong sum is money. The exact
		   per-order rate is the invoice's own: USD value over order total, both
		   stored at creation. An old order without the pair converts by
		   nothing — it refuses with the reason instead. */
		$amount_usd = $amount;
		if ( 'USD' !== $order->get_currency() ) {
			$usd   = (float) $order->get_meta( '_cherum_invoice_usd' );
			$total = (float) $order->get_meta( '_cherum_order_total' );
			if ( $usd <= 0 || $total <= 0 ) {
				return self::refund_refused(
					$order,
					'cherum_refund_currency',
					__( 'This order predates exchange-rate tracking, so the refund amount cannot be converted to dollars safely. Refund it from the Cherum dashboard instead.', 'cherum-pay-for-woocommerce' )
				);
			}
			$amount_usd = round( $amount * $usd / $total, 2 );
		}

		$api = new Cherum_Pay_Api( $key );

		/* The quote is advisory: a refusal here is reported with its own words,
		   but a network hiccup must not block a refund the shop owner asked
		   for. Only an explicit refusal stops us.
		   JUDGED BY THE STATUS CODE, NOT BY THE WORDING (1.3.2). Until now this
		   looked for the word "network" in the message, and the two cases came
		   out swapped: a real network failure arrives with status 0 and says
		   "cURL error 28: Operation timed out" — no such word, so the shop owner
		   was refused and shown the raw cURL line — while the service's own
		   refusal reads "network fee or rate unavailable", which contains it, so
		   that one sailed through to POST /refunds. Status 0 is the only case
		   where nobody answered; every HTTP code is an answer. */
		$quote = $api->refund_quote( $invoice_id, $amount_usd );
		if ( ! $quote['ok'] && 0 !== (int) $quote['status'] ) {
			return self::refund_refused(
				$order,
				'cherum_refund_unavailable',
				'' !== $quote['error']
					? $quote['error']
					: __( 'Cherum could not price this refund.', 'cherum-pay-for-woocommerce' ),
				$amount
			);
		}
		if ( ! $quote['ok'] ) {
			self::log( 'refund quote unreachable for order ' . $order_id . ' (' . $quote['error'] . ') — going ahead with the refund' );
		}

		$signature = md5( $invoice_id . '|' . $amount_usd . '|' . $reason );
		$seq       = (int) $order->get_meta( '_cherum_refund_seq_' . $signature );
		$idem      = 'wc-' . $order_id . '-' . $signature . ( $seq > 0 ? '-' . $seq : '' );
		$res       = $api->create_refund( $invoice_id, $amount_usd, (string) $reason, $idem );
		if ( ! $res['ok'] ) {
			return self::refund_refused(
				$order,
				'cherum_refund_failed',
				$res['error'] ? $res['error'] : __( 'Cherum did not accept the refund.', 'cherum-pay-for-woocommerce' ),
				$amount
			);
		}

		$rid  = isset( $res['data']['refund']['id'] ) ? (string) $res['data']['refund']['id'] : '';
		$had  = self::refund_ids( $order );
		if ( '' !== $rid && in_array( $rid, $had, true ) ) {
			/* The service handed back a refund this order has already been
			   credited with. Counting it again would put a second refund line
			   in the books for money that left once. */
			return self::refund_refused(
				$order,
				'cherum_refund_duplicate',
				sprintf(
					/* translators: %s: the refund id already recorded on this order. */
					__( 'Cherum answered with refund %s, which is already recorded on this order — nothing new was refunded. If you meant a second refund, wait until the first one is paid out, or give this one a different reason so the two can be told apart.', 'cherum-pay-for-woocommerce' ),
					$rid
				),
				$amount
			);
		}
		if ( '' !== $rid ) {
			$order->add_meta_data( '_cherum_refund_ids', $rid, false );
		}
		$order->update_meta_data( '_cherum_refund_seq_' . $signature, (string) ( $seq + 1 ) );
		$order->save();

		/* The note says PENDING on purpose. The money leaves after the payer
		   names a wallet on the payment page — telling the shop owner "refunded"
		   before that happens would be a promise we do not control. */
		$order->add_order_note(
			sprintf(
				/* translators: 1: amount, 2: refund id */
				__( 'Cherum Pay: refund of %1$s requested (%2$s). The payer is asked for a wallet on the payment page; the money leaves once they give one.', 'cherum-pay-for-woocommerce' ),
				wc_price( $amount ),
				$rid ? $rid : __( 'no id returned', 'cherum-pay-for-woocommerce' )
			)
		);
		self::log( 'refund requested for order ' . $order_id . ': ' . $amount_usd . ' USD (' . ( '' !== $rid ? $rid : 'no id' ) . ')' );
		return true;
	}

	/**
	 * Every Cherum refund id already recorded on this order.
	 *
	 * @param WC_Order $order Order.
	 * @return string[]
	 */
	private static function refund_ids( $order ) {
		$out = array();
		foreach ( (array) $order->get_meta( '_cherum_refund_ids', false ) as $row ) {
			if ( is_object( $row ) ) {
				$value = isset( $row->value ) ? $row->value : '';
			} elseif ( is_array( $row ) ) {
				$value = isset( $row['value'] ) ? $row['value'] : '';
			} else {
				$value = $row;
			}
			if ( '' !== (string) $value ) {
				$out[] = (string) $value;
			}
		}
		return $out;
	}

	/**
	 * Refuse a refund, and leave the reason where it can be found later.
	 *
	 * The pop-up that carries this message is closed a second after it opens.
	 * The order note is the copy that survives — and it matters more than the
	 * log, which most shops leave switched off.
	 *
	 * @param WC_Order   $order   Order.
	 * @param string     $code    Machine-readable code for WP_Error.
	 * @param string     $message Sentence for the shop owner.
	 * @param float|null $amount  Amount asked for, in shop currency.
	 * @return WP_Error
	 */
	private static function refund_refused( $order, $code, $message, $amount = null ) {
		self::log( 'refund refused for order ' . $order->get_id() . ' [' . $code . ']: ' . $message );
		$order->add_order_note(
			null === $amount
				? sprintf(
					/* translators: %s: reason the refund was refused. */
					__( 'Cherum Pay: the refund was not accepted — %s', 'cherum-pay-for-woocommerce' ),
					$message
				)
				: sprintf(
					/* translators: 1: amount asked for, 2: reason the refund was refused. */
					__( 'Cherum Pay: a refund of %1$s was not accepted — %2$s', 'cherum-pay-for-woocommerce' ),
					wp_strip_all_tags( wc_price( $amount ) ),
					$message
				)
		);
		return new WP_Error( $code, $message );
	}

	/**
	 * Poll the invoice when the buyer lands on "order received" still unpaid.
	 *
	 * @param int $order_id Order id.
	 */
	public function poll_on_thankyou( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( $order && ! $order->is_paid() && $order->has_status( array( 'pending', 'on-hold' ) ) ) {
			self::poll_order( $order );
		}
	}

	/** How long a polled order is left alone before the net looks at it again. */
	const POLL_COOLDOWN = 1800;

	/** Orders asked about in one cron pass, and how many are fetched to find them. */
	const POLL_BATCH = 10;
	const POLL_WINDOW = 25;

	/**
	 * Cron pass: ask Cherum about stale unpaid Cherum orders.
	 *
	 * The webhook is the primary path; this is the net under it. Orders between
	 * ten minutes and two days old are eligible — younger ones the webhook
	 * still owns, older ones have expired long ago.
	 *
	 * OLDEST FIRST, AND ONLY ONCE PER HALF HOUR EACH (1.3.2). It used to take
	 * the ten NEWEST, with no memory of what it had asked about: on a store with
	 * more than ten stale orders the newest ten were re-asked every quarter of
	 * an hour for ever and the older ones — the ones about to fall out of the
	 * two-day window and stay pending for good — were never asked about at all.
	 * Seen on the demo store with thirteen candidates: the one order that had
	 * actually been paid was the one the pass never reached. Oldest first puts
	 * the most urgent first, and the stamp moves the queue along.
	 */
	public static function poll_pending_orders() {
		$orders = wc_get_orders(
			array(
				'limit'        => self::POLL_WINDOW,
				'status'       => array( 'pending', 'on-hold' ),
				'meta_key'     => '_cherum_invoice_id', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_compare' => 'EXISTS',
				'orderby'      => 'date',
				'order'        => 'ASC',
				'date_created' => ( time() - 2 * DAY_IN_SECONDS ) . '...' . ( time() - 10 * MINUTE_IN_SECONDS ),
			)
		);
		/* The "asked recently" filter is done here rather than in the query on
		   purpose: meta_query is not supported by the classic order storage and
		   raises a "doing it wrong" notice there, so a query that reads well
		   would misbehave on every store that has not moved to HPOS. */
		$done = 0;
		foreach ( $orders as $order ) {
			if ( $done >= self::POLL_BATCH ) {
				break;
			}
			if ( ! $order instanceof WC_Order ) {
				continue;
			}
			$last = (int) $order->get_meta( '_cherum_polled_at' );
			if ( $last && ( time() - $last ) < self::POLL_COOLDOWN ) {
				continue;
			}
			// Stamped BEFORE the call: a request that hangs must not put the
			// same order at the head of the queue for the next pass as well.
			$order->update_meta_data( '_cherum_polled_at', (string) time() );
			$order->save();
			self::poll_order( $order );
			++$done;
		}
	}

	/**
	 * One poll: read the invoice, translate its status into the SAME event
	 * path the webhook uses — one place decides what a status means.
	 *
	 * @param WC_Order $order Order.
	 */
	private static function poll_order( $order ) {
		$invoice_id = (string) $order->get_meta( '_cherum_invoice_id' );
		$key        = self::setting( 'api_key' );
		if ( '' === $invoice_id || '' === $key ) {
			return;
		}
		$api = new Cherum_Pay_Api( $key );
		$res = $api->get_invoice( $invoice_id );
		if ( ! $res['ok'] || empty( $res['data']['invoice']['status'] ) ) {
			return;
		}
		$status = (string) $res['data']['invoice']['status'];
		$map    = array(
			'confirmed' => 'invoice.confirmed',
			'settled'   => 'invoice.confirmed',
			'expired'   => 'invoice.expired',
			'canceled'  => 'invoice.canceled',
			'seen'      => 'invoice.seen',
		);
		if ( ! isset( $map[ $status ] ) ) {
			return;
		}
		self::log( 'poll: invoice ' . $invoice_id . ' is ' . $status . ' — applying to order ' . $order->get_id() );
		/* THE WHOLE INVOICE, NOT JUST ITS ID (1.3.2). The answer we already hold
		   carries coin, network, amountCrypto and tokenDecimals under the same
		   names the event handler reads — passing only the id meant an order
		   rescued by this net lost "Paid with 8.83 USDC · base" for good, while
		   the identical order closed by a notification kept it. */
		$invoice       = (array) $res['data']['invoice'];
		$invoice['id'] = $invoice_id;
		Cherum_Pay_Webhook::apply_event( $order, $map[ $status ], $invoice );
	}
}
