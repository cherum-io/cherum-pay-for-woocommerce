/**
 * Cherum Pay — payment method for the WooCommerce block checkout.
 *
 * Plain JavaScript on purpose: no bundler, no build step, no minified blob.
 * What ships is what you can read, which is what a plugin reviewer and a
 * curious shop owner both deserve.
 *
 * WooCommerce exposes everything needed on globals, so nothing here is
 * imported and nothing has to be compiled.
 */
(function () {
	'use strict';

	var registry = window.wc && window.wc.wcBlocksRegistry;
	var settingsApi = window.wc && window.wc.wcSettings;
	var element = window.wp && window.wp.element;
	var htmlEntities = window.wp && window.wp.htmlEntities;
	var i18n = window.wp && window.wp.i18n;

	/* Every one of these is provided by WooCommerce Blocks itself. If any is
	   missing we are running somewhere this file was not meant to load, and
	   registering half a payment method there would break the whole checkout
	   for every other gateway too. Leaving quietly is the correct failure. */
	if ( ! registry || ! settingsApi || ! element ) {
		return;
	}

	var createElement = element.createElement;
	var decode = htmlEntities && htmlEntities.decodeEntities
		? htmlEntities.decodeEntities
		: function ( s ) { return s; };
	var __ = i18n && i18n.__ ? i18n.__ : function ( s ) { return s; };

	/* WooCommerce keeps every method's data under one `paymentMethodData` key
	   and reads it with getPaymentMethodData(). The older getSetting('<id>_data')
	   form still works through a compatibility shim, so it stays as the
	   fallback for stores on an older WooCommerce. */
	var data = settingsApi.getPaymentMethodData
		? settingsApi.getPaymentMethodData( 'cherum_pay', {} )
		: settingsApi.getSetting( 'cherum_pay_data', {} );

	var title = decode( data.title || __( 'Pay with crypto', 'cherum-pay-for-woocommerce' ) );
	var description = decode( data.description || '' );

	/**
	 * The label shown in the payment method list.
	 */
	function Label( props ) {
		var PaymentMethodLabel = props.components && props.components.PaymentMethodLabel;
		/* The same mark the shop owner sees on the plugin card, at 22px: in a
		   list of payment methods the icon is a cue, not an advertisement. */
		var coins = Array.isArray( data.coinIcons ) ? data.coinIcons : [];
		var icon;
		if ( coins.length ) {
			/* The row of coin logos: what the buyer can pay with, before any
			   text is read. The shop owner can turn it off in the settings. */
			icon = createElement(
				'span',
				{ className: 'cherum-pay-coins', style: { display: 'inline-flex', gap: '4px', marginLeft: 'auto' } },
				coins.map( function ( c, i ) {
					return createElement( 'img', { key: 'c' + i, src: c.src, alt: c.alt, width: 20, height: 20, style: { width: '20px', height: '20px' } } );
				} )
			);
		} else if ( data.icon ) {
			icon = createElement( 'img', { src: data.icon, alt: '', className: 'cherum-pay-label-icon', style: { height: '22px', width: 'auto', marginLeft: 'auto' } } );
		} else {
			icon = null;
		}
		if ( PaymentMethodLabel ) {
			return createElement( PaymentMethodLabel, { text: title, icon: icon } );
		}
		return createElement( 'span', null, title, icon );
	}

	/**
	 * What the buyer sees once the method is selected.
	 *
	 * Two lines and no form. There is nothing to type here: the coin and the
	 * network are chosen on the payment page, where the live price and the
	 * countdown are. Asking for anything at this step would be asking twice.
	 */
	function Content() {
		var children = [];

		/* Tell the server the buyer chose us — and un-tell it on the way out.
		   The block checkout keeps the chosen method in the browser until the
		   order is placed, so the cart discount for paying in crypto could
		   never show while the choice was being made. This component mounts
		   exactly while the method is selected, which makes mount/unmount the
		   honest signal. extensionCartUpdate re-runs the cart totals on the
		   server, where the fee hook reads the session. */
		var checkout = window.wc && window.wc.blocksCheckout;
		var update = checkout && checkout.extensionCartUpdate;
		if ( element.useEffect && update ) {
			element.useEffect( function () {
				update( { namespace: 'cherum-pay', data: { chosen: true } } ).catch( function () {} );
				return function () {
					update( { namespace: 'cherum-pay', data: { chosen: false } } ).catch( function () {} );
				};
			}, [] );
		}

		if ( description ) {
			children.push( createElement( 'p', { key: 'desc' }, description ) );
		}

		children.push(
			createElement(
				'p',
				{ key: 'redirect' },
				__(
					'After you place the order you will finish the payment on a secure Cherum page and come straight back to the shop.',
					'cherum-pay-for-woocommerce'
				)
			)
		);

		return createElement( 'div', { className: 'cherum-pay-blocks-content' }, children );
	}

	registry.registerPaymentMethod( {
		name: 'cherum_pay',
		label: createElement( Label, null ),
		content: createElement( Content, null ),
		/* The saved-method view is the same text: there is no stored card and
		   nothing that differs between a first payment and a repeat one. */
		edit: createElement( Content, null ),
		/* No stored credentials means nothing can be "used again", so the
		   method never claims it can. */
		canMakePayment: function () {
			return true;
		},
		ariaLabel: title,
		supports: {
			features: Array.isArray( data.supports ) ? data.supports : [ 'products' ],
		},
	} );
})();
