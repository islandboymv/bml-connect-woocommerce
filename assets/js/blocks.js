/**
 * BML Connect — WooCommerce Blocks checkout registration.
 * No build step: uses the global wc/wp objects WooCommerce already enqueues.
 */
( function () {
	var settings = window.wc.wcSettings.getSetting( 'bml_connect_data', {} );
	var label    = window.wp.htmlEntities.decodeEntities( settings.title ) || 'BML Connect';
	var el       = window.wp.element.createElement;

	// Shown in the area revealed when this method is selected: description + supported-merchants image.
	var Content = function () {
		var children = [ window.wp.htmlEntities.decodeEntities( settings.description || '' ) ];
		if ( settings.icon ) {
			children.push( el( 'img', {
				key: 'bmlc-merchants',
				src: settings.icon,
				alt: 'Supported merchants',
				style: { display: 'block', maxWidth: '100%', height: 'auto', marginTop: '8px' },
			} ) );
		}
		return el( 'div', null, children );
	};

	var Label = function () {
		return el( 'span', { style: { width: '100%' } }, label );
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
