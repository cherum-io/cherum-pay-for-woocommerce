=== Cherum Pay for WooCommerce ===
Contributors: cherum
Tags: woocommerce, crypto payments, payment gateway, bitcoin, usdc
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.3.12
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Crypto payments for WooCommerce: the buyer picks the coin and network, you settle in one asset and refund from the order screen. 0.90%.

== Description ==

Cherum Pay adds one payment method to your checkout. The buyer chooses it, lands on a Cherum payment page, picks a coin and a network they already use, and pays. When the payment confirms, a signed notification closes the order in your store. The fee is 0.90% of the payment; if the buyer pays in a coin or on a network other than the asset you get paid in, converting it adds 0.50%. A buyer who pays in the coin and network you get paid in sends exactly the order total, and you receive that coin, up to the order total, minus the 0.90%; any other way, the amount also carries a small rate buffer, and the part of it the rate does not use is kept by Cherum.

How it differs from the other crypto gateways in this directory:

* **The buyer chooses the coin and the network.** Bitcoin, Ethereum, USDC, USDT, Solana, TRON, BNB, Polygon and GRAM on TON, with the stablecoins available on eight networks. Most crypto plugins make the shop pick one coin on one network for everyone; here the person with the money decides.
* **You get one asset.** Whatever the buyer pays with, your balance receives the asset you chose in your Cherum account. You never end up holding a coin you did not want.
* **Refunds from the WordPress order screen.** Type the amount in the refund box WooCommerce already gives you and press Refund. Partial refunds work too, and a refund still waiting for the buyer's wallet can be called off from Order actions. You do not have to open another dashboard.
* **Setup is one field.** Paste an API key, save. The plugin registers your store for notifications on its own; there is no secret to copy between two tabs.
* **0.90% of the payment.** If the buyer pays in a coin or on a network other than the asset you get paid in, converting it adds 0.50%, and a swap of another token in their own wallet costs 0.30%. Unless the buyer pays in the coin and network you get paid in, the amount they are asked for also carries a rate buffer of 0.10–1.25% by coin against the rate moving while the payment travels; the payment page names the conversion fee and the buffer as separate figures before they send anything, and the part of the buffer the rate does not use is kept by Cherum. No monthly fee, no minimum volume, no balance to top up before you can receive money.

A few things you will notice once it runs:

* **The order note shows what actually arrived** on your balance after fees, not what the buyer sent.
* **Underpaid is not "lost".** If the buyer sends less than the invoice, it stays open and they can top it up. Only a shortfall within a small tolerance, meant for an exchange's withdrawal fee, closes the invoice as paid; the order note then shows what actually reached your balance. If they send more, the payment page offers them the surplus back and asks where to send it; the order note says what happened.
* **A lost notification costs minutes, not a stuck order.** The "order received" page and a check every 15 minutes ask Cherum about unpaid orders and apply the answer through the same code the notification uses.
* **An optional discount for paying in crypto**, shown as a line in the cart totals while this method is selected. Card processing costs you 2 to 3%; crypto costs 0.90%. Some shops hand part of that back.
* **Test mode.** A key that starts with `chm_test_` makes orders and invoices that behave like real ones while no money can move.

= What you need =

* A Cherum account and an API key. Both take a couple of minutes in the merchant dashboard at https://dashboard.cherum.io.
* A store on https. Notifications are only sent to a public https address; a local test site still works through the 15-minute check.
* A store currency Cherum can price: USD, EUR, GBP, CHF, JPY, CAD, AUD, NZD, SEK, NOK, DKK, PLN, CZK, HUF, RON, TRY, BRL, MXN, INR, ZAR, SGD, HKD, KRW, CNY, IDR, MYR, PHP, THB, ILS, AED, SAR. In any other currency the method stays hidden at checkout and the settings screen tells you why.
* Orders from $2. The floor belongs to the coin and the network, not to the plugin: moving money costs the network the same whether the payment is large or small. Today the cheapest is $2 — USDC on Base, Arbitrum, Optimism, Polygon or Solana — while other assets ask $10 to $40 and Ethereum asks $175, and the payment page names the live figure for each coin before the buyer sends anything. That figure moves with the cost of moving money, so from 1.3.9 the plugin asks Cherum for today's one instead of carrying a number of its own: an order under it does not show this payment method at checkout, the buyer is told why, and the settings screen names it in your own currency. There is a second and lower floor as well — under $0.50 Cherum will not create an invoice at all — and a store that has not been able to ask yet falls back to that one.

== External services ==

This plugin talks to Cherum Pay, a payment service run by Cherum (https://cherum.io). It creates payment invoices there and receives notifications about them. Nothing is sent until a customer chooses this payment method at checkout.

Sent when an invoice is created: the order total, the store currency, the order number, a short plain-text line naming up to three items, the address to bring the customer back to after paying, the address to bring them back to if they leave without paying, and the store's language. No customer name or postal address is sent. The customer's e-mail address is sent only if you switch on "Receipt from Cherum", and then only so Cherum can e-mail them a receipt for the payment.

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

Four usual reasons: no API key, a store currency Cherum cannot price, the method is switched off, or the order is too small for the cheapest coin to take — $2 today, with $0.50 the point under which Cherum will not create an invoice at all. The settings screen says which, and a buyer looking at an order too small to pay in crypto is told so at the checkout, with the figure in your own currency. Up to 1.3.8 an order between the two floors was still offered the method and refused on "Place order"; from 1.3.9 it is not offered.

= Why does the payment page write a product name differently? =

Cherum prints the line naming the items on its own payment page and takes it as plain text only, so the plugin leaves out of it what Cherum would refuse: a website address loses its dot (`Amazon.com Gift Card` reads `Amazon com Gift Card`), a link is dropped, `<` and `>` become `‹` and `›`, and invisible characters go. Your products keep their names in the store. An order whose names leave nothing usable is described as "Order #" and its number.

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

= 1.3.12 =
* More ways of spelling out a dot are cleaned from the line that names the items. Cherum now reads `cherum ( dot ) com` with spaces inside the brackets, a "dot" written with a look-alike character (a Cyrillic or Greek o, a zero) and `cherum-dot-com` between two hyphens as a website address, and refuses them in an invoice description as it refuses `cherum.com`. With 1.3.11, a product name spelt one of these ways makes Cherum refuse the line, and the order is described as "Order #" and its number. The plugin now cleans them by the same rule: `cherum ( dot ) com` is sent as `cherum com` and `cherum-dot-com` as `cherum-dot com`. A name like `Polka-dot-pattern dress`, where no real domain ending follows, is sent as it is.
* The logo of the payment method is the new Cherum mark. The checkout shows it only when the row of coin logos is switched off in the settings.
* On the block checkout the row of coin logos now sits at the right edge of the method, after "Pay with crypto", as card brands do; before, it came between the radio button and the name. On a narrow screen it moves under the name.
* The plugin description now says what the buyer is asked for: paying in the coin and on the network you get paid in, exactly the order total; any other way, the amount also carries a rate buffer of 0.10–1.25% by coin, named on the payment page apart from the conversion fee. It also says that only a shortfall within a small tolerance closes an underpaid invoice. Nothing in the plugin's code changed for this.

= 1.3.11 =
* The line that tells the buyer what they are paying for is now always plain text. Cherum prints it on its payment page and is about to refuse an invoice whose description carries a website address, a link, markup or invisible characters — and the plugin builds that line from your product names, so a product called `Amazon.com Gift Card` or `Cable <10 cm` would have made "Place order" fail on the day the rule takes effect. The plugin now cleans the line by Cherum's own rule: `Amazon.com` is sent as `Amazon com`, a link is left out, `<` and `>` become `‹` and `›`, invisible characters go. A name with nothing in it to refuse is sent exactly as before, and your products keep their names in the store.
* If Cherum refuses the line all the same, the plugin asks once more with "Order #" and the order number, and the buyer goes on to pay. Nothing is retried a second time.
* Product names arrive on the payment page without HTML: `Salt &amp; Pepper` reads `Salt & Pepper`, and tags left by a page builder no longer show.

= 1.3.10 =
* The coin of the TON network is shown as GRAM, as on the Cherum payment page. The order screen reads "Paid with 1.5 GRAM · TON", the late-payment note says GRAM, and so do the coin logo at checkout and the default checkout description. The network is still called TON.
* Nothing changes in your data or your code: Cherum still sends this coin as `TON`, and the order keeps it as `TON` in `_cherum_paid_coin`, so reports, exports and anything that reads that field work as before.
* Networks are named on the order screen and in the late-payment note — "8.83 USDC · Base", "USDT · BNB Chain" — instead of the service's keys "base" and "bsc".
* If you never edited "Description at checkout", it now says GRAM as well. Text you wrote yourself is left as it is.

== Upgrade Notice ==

= 1.3.12 =
Cleans more spelt-out dots from the item line, which Cherum now refuses (with 1.3.11 such an order is described as Order #N). New Cherum mark on the method logo; the coin logos sit at the right edge of the method on the block checkout.
