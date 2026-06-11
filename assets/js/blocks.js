/**
 * BML Connect — WooCommerce Blocks checkout registration.
 * No build step: uses the global wc/wp objects WooCommerce already enqueues.
 */
( function () {
	var settings = window.wc.wcSettings.getSetting( 'bml_connect_data', {} );
	var label    = window.wp.htmlEntities.decodeEntities( settings.title ) || 'BML Connect';
	var el       = window.wp.element.createElement;

	var Content = function () {
		return window.wp.htmlEntities.decodeEntities( settings.description || '' );
	};

	var Label = function () {
		var icon = settings.icon
			? el( 'img', {
				src: settings.icon,
				alt: label,
				style: { marginLeft: '8px', maxHeight: '24px' },
			} )
			: null;
		return el(
			'span',
			{ style: { display: 'flex', alignItems: 'center', justifyContent: 'space-between', width: '100%' } },
			label,
			icon
		);
	};

	window.wc.wcBlocksRegistry.registerPaymentMethod( {
		name: 'bml_connect',
		label: el( Label, null ),
		content: el( Content, null ),
		edit: el( Content, null ),
		canMakePayment: function () {
			return true;
		},
		ariaLabel: label,
		supports: {
			features: settings.supports || [ 'products' ],
		},
	} );
} )();
