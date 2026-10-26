/**
 * BMaGlass Pay — Checkout Block registration.
 *
 * Registers the gateway with the WooCommerce Blocks registry so it
 * appears in the block checkout (the default checkout since
 * WooCommerce 10.6). No payment fields are collected here: BMaGlass
 * is a hosted-page flow, so the block renders the title/description
 * and WooCommerce hands the order to the gateway's classic
 * process_payment() on the server, which creates the payment link
 * and redirects the buyer to the hosted BMaGlass page.
 *
 * Written without a build step (plain script using the globals
 * WooCommerce Blocks exposes: wc.wcBlocksRegistry, wc.wcSettings,
 * wp.element) so the plugin stays install-by-copy.
 */
( function () {
	var registry = window.wc && window.wc.wcBlocksRegistry;
	var settingsApi = window.wc && window.wc.wcSettings;
	var element = window.wp && window.wp.element;
	if ( ! registry || ! settingsApi || ! element ) {
		return;
	}
	var settings = settingsApi.getSetting( 'bmaglass_data', {} );
	var createElement = element.createElement;

	var Label = function () {
		return createElement(
			'span',
			{ style: { width: '100%' } },
			settings.title || 'BMaGlass Pay'
		);
	};

	var Content = function () {
		if ( ! settings.description ) {
			return null;
		}
		return createElement( 'p', null, settings.description );
	};

	registry.registerPaymentMethod( {
		name: 'bmaglass',
		label: createElement( Label, null ),
		content: createElement( Content, null ),
		edit: createElement( Content, null ),
		canMakePayment: function () {
			return true; // Availability (enabled + configured key) is decided server-side by the integration class.
		},
		ariaLabel: settings.title || 'BMaGlass Pay',
		supports: {
			features:
				settings.supports && settings.supports.length
					? settings.supports
					: [ 'products' ],
		},
	} );
} )();
