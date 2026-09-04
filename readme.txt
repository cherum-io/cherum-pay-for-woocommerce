=== Cherum Pay for WooCommerce ===
Contributors: cherum
Tags: woocommerce, cryptocurrency, payment gateway, usdc, stablecoin
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.3.3
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
* **Underpaid is not "lost".** If the buyer sends less than the invoice, it stays open and they can top it up. If they send more, the payment page offers them the surplus back and asks where to send it; the order note says what happened.
* **A lost notification costs minutes, not a stuck order.** The "order received" page and a check every 15 minutes ask Cherum about unpaid orders and apply the answer through the same code the notification uses.
* **An optional discount for paying in crypto**, shown as a line in the cart totals while this method is selected. Card processing costs you 2 to 3%; crypto costs 0.90%. Some shops hand part of that back.
* **Test mode.** A key that starts with `chm_test_` makes orders and invoices that behave like real ones while no money can move.

= What you need =

* A Cherum account and an API key. Both take a couple of minutes at https://app.cherum.io.
* A store on https. Notifications are only sent to a public https address; a local test site still works through the 15-minute check.
* A store currency Cherum can price: USD, EUR, GBP, CHF, JPY, CAD, AUD, NZD, SEK, NOK, DKK, PLN, CZK, HUF, RON, BGN, TRY, BRL, MXN, INR, ZAR, SGD, HKD, KRW, CNY, IDR, MYR, PHP, THB, ILS, AED, SAR. In any other currency the method stays hidden at checkout and the settings screen tells you why.

== External services ==

This plugin talks to Cherum Pay, a payment service run by Cherum (https://cherum.io). It creates payment invoices there and receives notifications about them. Nothing is sent until a customer chooses this payment method at checkout.

Sent when an invoice is created: the order total, the store currency, the order number, a short line naming up to three items, the address to bring the customer back to after paying, the address to bring them back to if they leave without paying, and the store's language. No customer name or postal address is sent. The customer's e-mail address is sent only if you switch on "Receipt from Cherum", and then only so Cherum can e-mail them a receipt for the payment.

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

Yes, with the classic checkout and with the Cart and Checkout blocks. Nothing to switch. Up to 1.3.2 the crypto discount was the exception: it appeared in the block checkout and never in the classic one. It works in both from 1.3.3.

== Screenshots ==

1. The method at checkout, with the discount line in the totals.
2. The Cherum payment page: the buyer picks the coin and the network.
3. Paid. The buyer returns to the store by the button.
4. The order in WooCommerce: notes for each step, refund from the same screen.
5. Settings: one key, connected.

== Changelog ==

= 1.3.3 =
* The crypto discount now works on the classic checkout. It never had: the discount was hooked from the payment method's own object, and on a classic checkout that object does not exist yet when the cart is totalled — so the order was written at the full price, with no sign anywhere that a discount had been set.
* The discount is taken off the price after coupons, not before. With a 50% coupon and a 2% crypto discount the shop was handing back 2% of the full price, twice what it meant; a large discount next to a large coupon could bring the cart to 0.00, which WooCommerce completes without calling any payment method at all.
* An order remembers every invoice it has ever had. When a buyer paid a second time, the store kept only the newest invoice id and answered "unknown order" to everything about the older one — including money arriving late on its address, and a payment confirmed on it inside the 24-hour window that follows an expiry. That left a paid buyer looking at an unpaid order, with nothing in the order history to explain it.
* The late-payment note carries the figures again. It read the two fields the service does not send, so every such note in every shop said "(amount in dashboard, tx —)". It now names the amount, the coin, the network and the date you have to decide by.
* "Leave it pending" really does e-mail the buyer a way back. Both the setting and the order note promised a link in the buyer's e-mail, and WooCommerce sends a customer nothing at all for an order that stays pending. The plugin now sends WooCommerce's own order-details e-mail, once, and says plainly when it could not.
* The overpayment note no longer promises an automatic refund. A surplus goes back only after the buyer names a wallet on the payment page, and a surplus below Cherum's minimum refund amount does not go back at all.
* The credited amount is written as money: "$60.83 credited... the exact amount is 60.82758 USD" instead of a bare "60.82758", which a shop in another currency read as its own.
* A refused refund is written on the order. Every refusal used to live only in the pop-up you then closed: no note, no log line, no reason.
* Two deliberate refunds of the same amount, with the same reason, are two refunds. They shared an idempotency key, so the second was answered with the first and WooCommerce recorded a refund line for money that had left once. A retry after a timeout still replays the same request, which is what that key is for.
* Refunds say up front that a rehearsal key cannot make them. The rehearsal notice says so too.
* "Receipt from Cherum" checks, when you save, whether Cherum is sending buyer receipts at all, and tells you if it is not — rather than sending the buyer's e-mail address out of the store for a letter that cannot be sent.
* The suggested privacy-policy text follows the settings instead of stating, always, that no e-mail address is sent.

= 1.3.2 =
* Paying a second time works. With "Leave it pending" set, an order whose invoice had expired sent the buyer straight back to the expired invoice — the only page they could reach was the one telling them it was too late. The plugin now checks the invoice before reusing it and asks for a fresh one when the old one is dead.
* Refunds are no longer refused when the service is reachable and accepted when it is not. The two cases were the wrong way round: a timeout blocked the refund and showed the raw cURL line, while the service's own "cannot price a refund right now" was mistaken for a hiccup and the refund was sent anyway.
* A refusal without a sentence now names its reason and its numbers instead of "Cherum Pay returned status 409".
* One e-mail per payment. With "Order status once paid = Completed" the buyer received both "Processing order" and "Completed order" for the same payment, and the order history showed a transition that never needed to happen.
* An order rescued by the fallback check keeps "Paid with 8.83 USDC · base", like an order closed by a notification.
* The fallback check goes oldest first and remembers what it has asked about. On a store with more than ten stale orders the oldest — the ones about to fall out of the window for good — were never asked about at all.
* The discount and the invoice lifetime are checked when they are saved. "95%" saved happily and no discount ever appeared; "2 minutes" was quietly turned into 5. Both now say what was actually stored.
* A failed connection attempt no longer forgets the endpoint number, which made the next successful save roll the notification secret for nothing.
* A network failure at checkout tells the buyer nothing was charged, instead of showing them "cURL error 28: Operation timed out".
* "Invoice created" is written to the order history only when the invoice really is a new one.

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
