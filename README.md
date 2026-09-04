# Cherum Pay for WooCommerce

Accept crypto payments in your WooCommerce store — settled to your Cherum balance, refunds included.

Version 1.3.3. Requires WooCommerce 8.0+, PHP 7.4+. GPLv2 or later.

## What it does

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

## Installation

1. Install and activate the plugin. WooCommerce 9.0 or newer must be active.
2. Go to WooCommerce → Settings → Payments → Cherum Pay.
3. Paste your API key and save. The plugin connects the store and shows "Connected" with the endpoint number. Start with a `chm_test_` key: everything works and no money moves.
4. Place a test order. The payment page shows a practice address; press the "simulate" control on it and watch the order close in your store.
5. Swap in a live key when you are ready.

## Development

This repository mirrors the plugin published on WordPress.org. The zip attached to each
release is what the catalog receives. Issues and pull requests are welcome here.

Website: https://cherum.io · Docs: https://cherum.io/docs
