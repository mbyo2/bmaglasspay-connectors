/**
 * BMaGlass Pay — Mobile Money Checkout Block registration.
 *
 * Registers the mobile-money gateway with the WooCommerce Blocks
 * registry so it appears in the block checkout (the default checkout
 * since WooCommerce 10.6). The component collects the buyer's mobile
 * money number and network, and on payment setup hands them to the
 * server as payment method data (bmaglass_momo_phone /
 * bmaglass_momo_operator) — WooCommerce then routes the submission
 * to the gateway's classic process_payment(), which starts the
 * collection. The buyer approves the prompt on their phone; the
 * order completes via the settlement webhook / reconciliation.
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
	var settings = settingsApi.getSetting( 'bmaglass_momo_data', {} );
	var createElement = element.createElement;
	var useState = element.useState;
	var useEffect = element.useEffect;

	var Label = function () {
		return createElement(
			'span',
			{ style: { width: '100%' } },
			settings.title || 'Mobile money'
		);
	};

	var Content = function ( props ) {
		var phoneState = useState( '' );
		var phone = phoneState[0];
		var setPhone = phoneState[1];
		var operatorState = useState( '' );
		var operator = operatorState[0];
		var setOperator = operatorState[1];
		var eventRegistration = ( props && props.eventRegistration ) || {};
		var onPaymentSetup = eventRegistration.onPaymentSetup;

		useEffect(
			function () {
				if ( ! onPaymentSetup ) {
					return undefined;
				}
				var unsubscribe = onPaymentSetup( function () {
					return {
						type: 'success',
						meta: {
							paymentMethodData: {
								bmaglass_momo_phone: phone,
								bmaglass_momo_operator: operator,
							},
						},
					};
				} );
				return unsubscribe;
			},
			[ onPaymentSetup, phone, operator ]
		);

		return createElement(
			'div',
			null,
			settings.description
				? createElement( 'p', null, settings.description )
				: null,
			createElement(
				'p',
				{ className: 'form-row form-row-wide' },
				createElement(
					'label',
					{ htmlFor: 'bmaglass-momo-phone' },
					'Mobile money number *'
				),
				createElement( 'input', {
					id: 'bmaglass-momo-phone',
					type: 'tel',
					autoComplete: 'tel',
					placeholder: '0977 123 456',
					value: phone,
					onChange: function ( e ) {
						setPhone( e.target.value );
					},
				} )
			),
			createElement(
				'p',
				{ className: 'form-row form-row-wide' },
				createElement(
					'label',
					{ htmlFor: 'bmaglass-momo-operator' },
					'Network'
				),
				createElement(
					'select',
					{
						id: 'bmaglass-momo-operator',
						value: operator,
						onChange: function ( e ) {
							setOperator( e.target.value );
						},
					},
					createElement( 'option', { value: '' }, 'Detect from my number' ),
					createElement( 'option', { value: 'mtn' }, 'MTN' ),
					createElement( 'option', { value: 'airtel' }, 'Airtel' ),
					createElement( 'option', { value: 'zamtel' }, 'Zamtel' )
				)
			)
		);
	};

	registry.registerPaymentMethod( {
		name: 'bmaglass_momo',
		label: createElement( Label, null ),
		content: createElement( Content, null ),
		edit: createElement( Content, null ),
		canMakePayment: function () {
			return true; // Availability is decided server-side by the integration class.
		},
		ariaLabel: settings.title || 'Mobile money',
		supports: {
			features:
				settings.supports && settings.supports.length
					? settings.supports
					: [ 'products' ],
		},
	} );
} )();
