# Cherum Pay for WooCommerce

Crypto payments for WooCommerce: the buyer picks the coin and network, you settle in one asset and refund from the order screen. 0.90%.

Version 1.3.12. Requires WordPress 6.5+, WooCommerce 9.0+, PHP 7.4+. Block and classic checkout. GPLv2 or later.

Install it from the WordPress.org plugin directory: https://wordpress.org/plugins/cherum-pay-for-woocommerce/

## What it does

Cherum Pay adds one payment method to your checkout. The buyer chooses it, lands on a Cherum payment page, picks a coin and a network they already use, and pays. When the payment confirms, a signed notification closes the order in your store. The fee is 0.90% of the payment; if the buyer pays in a coin or on a network other than the asset you get paid in, converting it adds 0.50%. A buyer who pays in the coin and network you get paid in sends exactly the order total, and you receive that coin, up to the order total, minus the 0.90%; any other way, the amount also carries a small rate buffer, and the part of it the rate does not use is kept by Cherum.

* **The buyer chooses the coin and the network.** Bitcoin, Ethereum, USDC, USDT, Solana, TRON, BNB, Polygon and GRAM on TON, with the stablecoins available on eight networks.
* **You get one asset.** Whatever the buyer pays with, your balance receives the asset you chose in your Cherum account.
* **Refunds from the WordPress order screen.** Type the amount in the refund box WooCommerce already gives you and press Refund. Partial refunds work too, and a refund still waiting for the buyer's wallet can be called off from Order actions.
* **Setup is one field.** Paste an API key, save. The plugin registers your store for notifications on its own.
* **0.90% of the payment.** Converting another coin or network adds 0.50%, and a swap of another token in the buyer's own wallet costs 0.30%. Unless the buyer pays in the coin and network you get paid in, the amount they are asked for also carries a rate buffer of 0.10–1.25% by coin; the payment page names the conversion fee and the buffer as separate figures before they send anything. No monthly fee, no minimum volume, no balance to top up before you can receive money.

Once it runs:

* **The order note shows what actually arrived** on your balance after fees, not what the buyer sent.
* **Underpaid is not "lost".** If the buyer sends less than the invoice, it stays open and they can top it up. Only a shortfall within a small tolerance, meant for an exchange's withdrawal fee, closes the invoice as paid. If they send more, the payment page offers them the surplus back and asks where to send it; the order note says what happened.
* **A lost notification costs minutes, not a stuck order.** The "order received" page and a check every 15 minutes ask Cherum about unpaid orders and apply the answer through the same code the notification uses.
* **An optional discount for paying in crypto**, shown as a line in the cart totals while this method is selected.

## What you need

* A Cherum account and an API key, from the merchant dashboard at https://dashboard.cherum.io.
* A store on https. Notifications are only sent to a public https address; a local test site still works through the 15-minute check.
* A store currency Cherum can price. The list is in [readme.txt](readme.txt); in any other currency the method stays hidden at checkout and the settings screen tells you why.
* Orders from $2. The floor belongs to the coin and the network, not to the plugin, and the payment page names the live figure for each coin before the buyer sends anything. Under $0.50 Cherum will not create an invoice at all. Details in [readme.txt](readme.txt).

## Installation

1. Install and activate the plugin. WooCommerce 9.0 or newer must be active.
2. Go to WooCommerce → Settings → Payments → Cherum Pay.
3. Paste your API key and save. The plugin connects the store and shows "Connected" with the endpoint number. Start with a `chm_test_` key: no money moves.
4. Place a test order and, on the payment page, pick a coin and network as a buyer would. The page shows a practice address that no network accepts. Then open that invoice in your Cherum dashboard and press "confirmed" under Rehearse: the payment page and the order in your store react as they would to a real payment.
5. Swap in a live key when you are ready.

## Test mode

A key that starts with `chm_test_` makes orders, invoices and notifications that behave like real ones, while the payment address is a practice marker that no network accepts. The Rehearse buttons on the invoice in your Cherum dashboard play what the buyer would do: seen, confirmed, underpaid, overpaid, expired.

Refunds are not part of test mode, because they move real money. On a store with a test key the refund box says so; connect a live key to refund through Cherum.

## Who holds the money

The plugin holds nothing. Until settlement the deposit address belongs to Cherum, so the money is with Cherum, not with you (terms of service, §2.1). The plugin only creates invoices, reads their status and asks for refunds; what Cherum settles goes to the account you set up there.

## External services

This plugin talks to Cherum Pay, a payment service run by Cherum (https://cherum.io). Nothing is sent until a customer chooses this payment method at checkout.

* **When an invoice is created:** the order total, the store currency, the order number, a short plain-text line naming up to three items, the address to bring the customer back to after paying, the address to bring them back to if they leave without paying, and the store's language. No customer name or postal address is sent. The customer's e-mail address is sent only if you switch on "Receipt from Cherum", and then only so Cherum can e-mail them a receipt for the payment.
* **When you save the settings:** the public address of this store's notification route, so Cherum knows where to send order updates.
* **When you refund:** the invoice identifier, the amount and the reason you typed. When you cancel a refund, or when the plugin asks which refund is open on an order, only the refund or invoice identifier is sent.
* **Received:** the invoice identifier, the link to the payment page, and signed notifications about the invoice and refund status.

Terms of service: https://cherum.io/legal/terms
Privacy policy: https://cherum.io/legal/privacy

## This repository

This repository mirrors version 1.3.12 of the plugin as published in the WordPress.org directory. The plugin files are the same bytes as the directory's `tags/1.3.12`, and the 1.3.12 release here carries the directory's own zip.

* `readme.txt` is the directory page. It has the three most recent releases; `changelog.txt` has everything older.
* `.wordpress-org/` holds the directory listing images: banners, icons and screenshots. They are not part of the plugin archive.
* `README.md` and `LICENSE` belong to the repository and are not part of the plugin archive either.

Bug reports go to the issue tracker here. Questions about your account or a payment go through the dashboard, https://dashboard.cherum.io. Security reports: support@cherum.io, see https://cherum.io/.well-known/security.txt.

Website: https://cherum.io/woocommerce · Pay API: https://docs.cherum.io/pay-api.html
