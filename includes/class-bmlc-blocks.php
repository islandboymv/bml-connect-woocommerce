<?php
/**
 * WooCommerce Blocks (block-based checkout) integration for BML Connect.
 * Renders the gateway as a payment option in the Checkout block.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class BMLC_Blocks_Support extends AbstractPaymentMethodType {

	protected $name = 'bml_connect';

	/** @var WC_Payment_Gateway|null */
	private $gateway;

	public function initialize() {
		$this->settings = get_option( 'woocommerce_bml_connect_settings', array() );
		$gateways       = WC()->payment_gateways ? WC()->payment_gateways->payment_gateways() : array();
		$this->gateway  = isset( $gateways['bml_connect'] ) ? $gateways['bml_connect'] : null;
	}

	public function is_active() {
		return ! empty( $this->settings['enabled'] ) && 'yes' === $this->settings['enabled'];
	}

	public function get_payment_method_script_handles() {
		wp_register_script(
			'bmlc-blocks',
			BMLC_URL . 'assets/js/blocks.js',
			array( 'wc-blocks-registry', 'wp-element', 'wp-html-entities', 'wc-settings' ),
			BMLC_VERSION,
			true
		);
		return array( 'bmlc-blocks' );
	}

	public function get_payment_method_data() {
		return array(
			'title'       => $this->gateway ? $this->gateway->title : ( $this->settings['title'] ?? 'BML Connect' ),
			'description' => $this->gateway ? $this->gateway->description : ( $this->settings['description'] ?? '' ),
			'icon'        => ( $this->gateway && $this->gateway->icon ) ? $this->gateway->icon : '',
			'supports'    => $this->gateway
				? array_filter( $this->gateway->supports, array( $this->gateway, 'supports' ) )
				: array( 'products' ),
		);
	}
}
