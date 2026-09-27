=== Cherum Pay for WooCommerce ===
Contributors: cherum
Tags: woocommerce, crypto payments, payment gateway, bitcoin, usdc
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.3.8
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Crypto payments for WooCommerce: the buyer picks the coin and network, you settle in one asset and refund from the order screen. 0.90%.

== Description ==

Cherum Pay adds one payment method to your checkout. The buyer chooses it, lands on a Cherum payment page, picks a coin and a network they already use, and pays. When the payment confirms, a signed notification closes the order in your store. The fee is 0.90% of the payment; if the buyer pays in a coin other than the one the invoice asks for, converting it adds 0.30%.

How it differs from the other crypto gateways in this directory:

* **The buyer chooses the coin and the network.** Bitcoin, Ethereum, USDC, USDT, Solana, TON, TRON, BNB and Polygon, with the stablecoins available on eight networks. Most crypto plugins make the shop pick one coin on one network for everyone; here the person with the money decides.
* **You get one asset.** Whatever the buyer pays with, your balance receives the asset you chose in your Cherum account. You never end up holding a coin you did not want.
* **Refunds from the WordPress order screen.** Type the amount in the refund box WooCommerce already gives you and press Refund. Partial refunds work too, and a refund still waiting for the buyer's wallet can be called off from Order actions. You do not have to open another dashboard.
* **Setup is one field.** Paste an API key, save. The plugin registers your store for notifications on its own; there is no secret to copy between two tabs.
* **0.90% of the payment.** If the buyer pays in a coin other than the one the invoice asks for, converting it adds 0.30%; the payment page shows the figure before they send anything. No monthly fee, no minimum volume, no balance to top up before you can receive money.

A few things you will notice once it runs:

* **The order note shows what actually arrived** on your balance after fees, not what the buyer sent.
* **Underpaid is not "lost".** If the buyer sends less than the invoice, it stays open and they can top it up. If they send more, the payment page offers them the surplus back and asks where to send it; the order note says what happened.
* **A lost notification costs minutes, not a stuck order.** The "order received" page and a check every 15 minutes ask Cherum about unpaid orders and apply the answer through the same code the notification uses.
* **An optional discount for paying in crypto**, shown as a line in the cart totals while this method is selected. Card processing costs you 2 to 3%; crypto costs 0.90%. Some shops hand part of that back.
* **Test mode.** A key that starts with `chm_test_` makes orders and invoices that behave like real ones while no money can move.

= What you need =

* A Cherum account and an API key. Both take a couple of minutes in the merchant dashboard at https://dashboard.cherum.io.
* A store on https. Notifications are only sent to a public https address; a local test site still works through the 15-minute check.
* A store currency Cherum can price: USD, EUR, GBP, CHF, JPY, CAD, AUD, NZD, SEK, NOK, DKK, PLN, CZK, HUF, RON, BGN, TRY, BRL, MXN, INR, ZAR, SGD, HKD, KRW, CNY, IDR, MYR, PHP, THB, ILS, AED, SAR. In any other currency the method stays hidden at checkout and the settings screen tells you why.
* Orders from $2. The floor belongs to the coin and the network, not to the plugin: moving money costs the network the same whether the payment is large or small. Today the cheapest is $2 (USDC on Base, Arbitrum, Optimism, Polygon or Solana), while other assets ask $10 to $40 and Ethereum asks $175, and the payment page names the live figure for each coin before the buyer sends anything. Under $0.50 Cherum will not create an invoice at all: the method takes itself off the checkout and the buyer is told why, and the settings screen names that figure in your own currency. Between $0.50 and the cheapest coin's floor the method is still offered and Cherum refuses the invoice when the order is placed. The buyer stays on the checkout with Cherum's own sentence, and nothing is charged.

== External services ==

This plugin talks to Cherum Pay, a payment service run by Cherum (https://cherum.io). It creates payment invoices there and receives notifications about them. Nothing is sent until a customer chooses this payment method at checkout.

Sent when an invoice is created: the order total, the store currency, the order number, a short line naming up to three items, the address to bring the customer back to after paying, the address to bring them back to if they leave without paying, and the store's language. No customer name or postal address is sent. The customer's e-mail address is sent only if you switch on "Receipt from Cherum", and then only so Cherum can e-mail them a receipt for the payment.

Sent when you save the settings: the public address of this store's notification route, so Cherum knows where to send order updates.

Sent when you refund: the invoice identifier, the amount and the reason you typed. When you cancel a refund, or when the plugin asks which refund is open on an order, only the refund or invoice identifier is sent.

Received: the invoice identifier, the link to the payment page, and signed notifications about the invoice and refund status.

Terms of service: https://cherum.io/legal/terms
Privacy policy: https://cherum.io/legal/privacy

== Installation ==

1. Install and activate the plugin. WooCommerce 9.0 or newer must be active.
2. Go to WooCommerce → Settings → Payments → Cherum Pay.
3. Paste your API key and save. The plugin connects the store and shows "Connected" with the endpoint number. Start with a `chm_test_` key: everything works and no money moves.
4. Place a test order and, on the payment page, pick a coin and network as a buyer would. The page shows a practice address that no network accepts. Then open that invoice in your Cherum dashboard and press "confirmed" under Rehearse: the payment page and the order in your store react as they would to a real payment.
5. Swap in a live key when you are ready.

== Frequently Asked Questions ==

= Does the plugin hold my money? =

The plugin holds nothing. Until settlement the deposit address belongs to Cherum, so the money is with Cherum, not with you: terms §2.1. The plugin only creates invoices, reads their status and asks for refunds; what Cherum settles goes to the account you set up there.

= How do refunds work? =

Open the order, press Refund, type the amount, confirm. Cherum reserves the amount on your balance and asks the buyer, on the payment page, for the wallet to send it to; the money leaves once they answer. If the balance is short or the network cost is higher than the amount, the refund is refused and the order note says why.

One refund at a time per payment. A refund waiting for a wallet can be called off with Order actions → "Cancel Cherum refund" → Update, which puts the reserve back on your balance and lets you start another one. Deleting the refund line WooCommerce recorded does the same.

= What if the shopper closes the browser after paying? =

The order still closes. The signed notification decides the order, not the browser.

= Why does the method not appear at checkout? =

Four usual reasons: no API key, a store currency Cherum cannot price, the method is switched off, or the order is smaller than the $0.50 Cherum can invoice at all. The settings screen says which, and a buyer looking at an order too small to pay in crypto is told so at the checkout. An order above $0.50 but below the cheapest coin's floor, $2 today, still shows the method: Cherum refuses that invoice when the order is placed and names the figure.

= My store has no https yet. Does it work? =

Orders still close, through the check that runs every 15 minutes. Notifications within seconds need a public https address.

= What does test mode do? =

Orders, invoices and notifications behave like real ones. The payment address is a practice marker that no network accepts, so nothing can be paid by accident. To play what the buyer would do, pick a coin on the payment page, then open the invoice in your Cherum dashboard and use the Rehearse buttons: seen, confirmed, underpaid, overpaid, expired.

Refunds are not part of test mode, because they move real money. On a store with a test key the refund box says so; connect a live key to refund through Cherum.

= Does it work with the block checkout? =

Yes, with the classic checkout and with the Cart and Checkout blocks. Nothing to switch. Up to 1.3.2 the crypto discount was the exception: it appeared in the block checkout and never in the classic one. It works in both from 1.3.3.

== Screenshots ==

1. The method at checkout, with the crypto discount line in the totals.
2. The Cherum payment page: the buyer picks the coin and the network. Taken with a rehearsal key, so the page is marked TEST and the address it hands out is a practice one.
3. Paid. One button takes the buyer back to the shop. Still the rehearsal key, hence the TEST mark.
4. The order in WooCommerce: notes for each step, refund from the same screen.
5. Settings: one key, connected.

== Changelog ==

The three most recent releases are here; everything older is in changelog.txt, in the plugin folder.

= 1.3.8 =
* The settings screen no longer says "Connected" over a connection Cherum has switched off. Cherum switches a notification endpoint off by itself: when the store's address answers "no such route" long enough (a plugin update, maintenance mode), when every delivery fails for days, or when another endpoint takes over the same address. The store now reads that state, switches notifications back on and prints the reason it was off; it also asks Cherum four times a day instead of only when somebody presses Save.
* Changing the API key no longer leaves a dead endpoint behind. This store keeps one notification secret, and Cherum keeps an endpoint per key: swapping the key, test to live or a re-issued one, used to register a new endpoint, overwrite the only secret, and leave the previous endpoint switched on and rejecting every delivery for a day and a half. The old endpoint is now switched off at the moment of the change, while the store still holds the key that is allowed to do it.
* Events that happen while notifications are off are no longer lost: Cherum holds them and sends them when the endpoint comes back on, and the store says how many are on their way.

= 1.3.7 =
* A discount can no longer make an order too small to pay for. Cherum will not create an invoice under $0.50 at all, and with a large discount next to a coupon the cart could land under that: the buyer filled in the checkout, pressed "Place order" and was told "Amount is below the minimum of $0.5" with nothing they could do about it. The discount now stops at the smallest payable amount, whatever the rate and the currency.
* An order that is too small on its own does not offer this payment method at all, and when it is the only method the buyer is told why and what to do, instead of "there are no payment methods for your location", which was neither true nor useful. The settings screen names the figure. In a shop whose currency is not the US dollar the exact figure is the one Cherum priced your last order at, so it is known from your first Cherum order onwards.
* An invoice that expires on an order that has already been paid no longer writes to the buyer. An order can be paid on an invoice that was replaced since (a buyer coming back to a pending order and paying the first one), and when the replacement expired, "Leave it pending" e-mailed the person who had already paid the details of their order with a link to pay it. The expiry is recorded on the order and nothing else happens; the safety-net check no longer asks about paid orders either.

= 1.3.6 =
* A refund that has not been paid out yet can be called off from the order screen: Order actions → "Cancel Cherum refund" → Update. Cherum allows one open refund per payment, and an open one can wait days for the buyer to give a wallet. Until now that left the shop unable to finish it, replace it or end it without opening another dashboard.
* Deleting the refund line WooCommerce recorded (Order → Refunds → ×) cancels the Cherum refund too, so a payout can no longer reach the buyer with no record of a refund left in the store.
* An order put on hold because a refund failed is put back where it was when you delete that refund line. It used to stay "On hold" for ever, including when you did exactly what the note told you to do.
* "A refund is already open on this payment" now names the refund and says how to end it, instead of stating a rule you could not act on.
* The Cherum Pay box on the order screen names an open refund while there is one.
