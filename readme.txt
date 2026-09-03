=== Cherum Pay for WooCommerce ===
Contributors: cherum
Tags: woocommerce, cryptocurrency, payment gateway, usdc, stablecoin
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.3.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Crypto payments for WooCommerce: the buyer picks the coin and network, you get one asset and refund from the order screen. 0.90%.

== Description ==

Cherum Pay adds one payment method to your checkout. The buyer chooses it, lands on a Cherum payment page, picks a coin and a network they already use, and pays. When the payment confirms, a signed notification closes the order in your store. The fee is 0.90% of the payment, nothing else.

How it differs from the other crypto gateways in this directory:

* **The buyer chooses the coin and the network.** Bitcoin, Ethereum, USDC, USDT, Solana, TON, TRON, BNB and Polygon, with the stablecoins available on eight networks. Most crypto plugins make the shop pick one coin on one network for everyone; here the person with the money decides.
* **You get one asset.** Whatever the buyer pays with, your balance receives the asset you chose in your Cherum account. You never end up holding a coin you did not want.
* **Refunds from the WordPress order screen.** Type the amount in the refund box WooCommerce already gives you and press Refund. Partial refunds work too. You do not have to open another dashboard.
* **Setup is one field.** Paste an API key, save. The plugin registers your store for notifications on its own; there is no secret to copy between two tabs.
* **0.90%, nothing else.** No monthly fee, no minimum volume, no balance to top up before you can receive money.

A few things you will notice once it runs:

* **The order note shows what actually arrived** on your balance after fees, not what the buyer sent.
* **Underpaid is not "lost".** If the buyer sends less than the invoice, it stays open and they can top it up. If they send more, the extra goes back to them and the order note says so.
* **A lost notification costs minutes, not a stuck order.** The "order received" page and a check every 15 minutes ask Cherum about unpaid orders and apply the answer through the same code the notification uses.
* **An optional discount for paying in crypto**, shown as a line in the cart totals while this method is selected. Card processing costs you 2 to 3%; crypto costs 0.90%. Some shops hand part of that back.
* **Test mode.** A key that starts with `chm_test_` makes orders and invoices that behave like real ones while no money can move.

= What you need =

* A Cherum account and an API key. Both take a couple of minutes at https://app.cherum.io.
* A store on https. Notifications are only sent to a public https address; a local test site still works through the 15-minute check.
* A store currency Cherum can price: USD, EUR, GBP, CHF, JPY, CAD, AUD, NZD, SEK, NOK, DKK, PLN, CZK, HUF, RON, BGN, TRY, BRL, MXN, INR, ZAR, SGD, HKD, KRW, CNY, IDR, MYR, PHP, THB, ILS, AED, SAR. In any other currency the method stays hidden at checkout and the settings screen tells you why.

== External services ==

This plugin talks to Cherum Pay, a payment service run by Cherum (https://cherum.io). It creates payment invoices there and receives notifications about them. Nothing is sent until a customer chooses this payment method at checkout.

Sent when an invoice is created: the order total, the store currency, the order number, a short line naming up to three items, the address to bring the customer back to after paying, the address to bring them back to if they leave without paying, and the store's language. No customer name, e-mail or postal address is sent.

Sent when you save the settings: the public address of this store's notification route, so Cherum knows where to send order updates.

Sent when you refund: the invoice identifier, the amount and the reason you typed.

Received: the invoice identifier, the link to the payment page, and signed notifications about the invoice and refund status.

Terms of service: https://cherum.io/legal/terms
Privacy policy: https://cherum.io/legal/privacy

== Installation ==

1. Install and activate the plugin. WooCommerce 9.0 or newer must be active.
2. Go to WooCommerce → Settings → Payments → Cherum Pay.
3. Paste your API key and save. The plugin connects the store and shows "Connected" with the endpoint number. Start with a `chm_test_` key: everything works and no money moves.
4. Place a test order. The payment page shows a practice address; press the "simulate" control on it and watch the order close in your store.
5. Swap in a live key when you are ready.

== Frequently Asked Questions ==

= Does the plugin hold my money? =

No. Payments settle to the account you set up at Cherum. The plugin only creates invoices, reads their status and asks for refunds.

= How do refunds work? =

Open the order, press Refund, type the amount, confirm. Cherum takes it from your balance and sends it back to the buyer's address. If the balance is short or the network cost is higher than the amount, the refund is refused and the order note says why.

= What if the shopper closes the browser after paying? =

The order still closes. The signed notification decides the order, not the browser.

= Why does the method not appear at checkout? =

Three usual reasons: no API key, a store currency Cherum cannot price, or the method is switched off. The settings screen says which.

= My store has no https yet. Does it work? =

Orders still close, through the check that runs every 15 minutes. Notifications within seconds need a public https address.

= What does test mode do? =

Orders, invoices, notifications and refunds behave like real ones. The payment address is a practice marker that no network accepts, so nothing can be paid by accident.

= Does it work with the block checkout? =

Yes, with the classic checkout and with the Cart and Checkout blocks. Nothing to switch.

== Screenshots ==

1. The method at checkout, with the discount line in the totals.
2. The Cherum payment page: the buyer picks the coin and the network.
3. Paid. The buyer returns to the store by the button.
4. The order in WooCommerce: notes for each step, refund from the same screen.
5. Settings: one key, connected.

== Changelog ==

= 1.3.1 =
* "Back to the shop" on the payment page no longer cancels the order. It used to open WooCommerce's cancel link, so a buyer who just wanted another look at the basket lost the order without being asked. It now goes to the order's own pay page, where they can pay again or pick another method.
* The payment page opens in the store's language. Russian, Spanish, Indonesian, Arabic, Brazilian Portuguese and Chinese shops send their buyers to a translated page instead of an English one.
* The "order received" page says whether the crypto payment is confirmed or still on its way, with a link to the payment page — that page is the receipt and keeps the transaction.

= 1.3.0 =
* One-field setup: paste the API key and save; the plugin registers the store for notifications and keeps the secret. The settings screen shows the connection state and says in plain words when a key is wrong, lacks a permission or the site has no https yet.
* A notice in the settings when a test key is in use.
* The readme and the settings texts rewritten for people.
* Refund notifications now reach the order. They were answered but not applied: the order was looked up by the refund id instead of the invoice id. Found on a live refund rehearsal, fixed before release.

= 1.2.2 =
* The block checkout shows the Cherum mark next to the method name.
* The checkout text names what the buyer can actually pay with.

= 1.2.1 =
* An "underpaid" event that arrives after the payment completed no longer demotes a paid order. The replay lock is taken before the event is applied and released if applying fails.

= 1.2.0 =
* "Order status once paid" and "Write a log" now do what they say.
* Translations load on self-installed copies.
* Refunds from a non-USD store convert at the order's own rate.
* Fallback check for lost notifications: the "order received" page and a 15-minute cron pass.
* Gateway icon on the classic checkout; uninstall removes settings and transients.

= 1.1.0 =
* Every platform event handled. Refunds from the order screen. Optional crypto discount. Choice of order status once paid. Optional log. Russian translation.

= 1.0.0 =
* First release: classic checkout, Cart and Checkout blocks, High-Performance Order Storage.
