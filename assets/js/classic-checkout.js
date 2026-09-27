/**
 * Cherum Pay — classic checkout.
 *
 * Re-total the checkout when the buyer changes the payment method, so that the
 * "Crypto payment discount" line on screen is the one the order will be written
 * with.
 *
 * WooCommerce does not do this by itself. Its own checkout script binds one
 * handler to the payment radios, and that handler slides the payment boxes open
 * and relabels the order button; totals are refreshed for the address, for the
 * shipping choice and for fields carrying .update_totals_on_change, never for
 * the choice of method. Any gateway whose total depends on the method has to
 * ask for the refresh itself.
 *
 * `update_checkout` is WooCommerce's own event: it posts the currently checked
 * method to ?wc-ajax=update_order_review, which stores it in the session, and
 * the totals come back rendered by the same fee callback the order is written
 * from. Nothing here computes money.
 *
 * The listener is delegated from the document body on purpose: WooCommerce
 * replaces the whole payment block on every refresh, so a handler bound to the
 * radios themselves would survive exactly one update.
 *
 * @package Cherum_Pay
 */
( function ( $ ) {
	'use strict';

	if ( ! $ ) {
		return;
	}

	$( function () {
		$( document.body ).on(
			'change',
			'form.checkout input[name="payment_method"]',
			function () {
				$( document.body ).trigger( 'update_checkout' );
			}
		);
	} );
}( window.jQuery ) );
