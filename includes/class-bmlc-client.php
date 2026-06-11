<?php
/**
 * Thin BML Connect v2 API client built on the WordPress HTTP API.
 * No external dependencies (no Guzzle/Composer).
 *
 * Auth is a static secret API key sent verbatim in the Authorization header.
 * Amounts are always in laari (integer): MVR 1.00 = 100 laari.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BMLC_Client {

	const SANDBOX_BASE    = 'https://api.uat.merchants.bankofmaldives.com.mv/public';
	const PRODUCTION_BASE = 'https://api.merchants.bankofmaldives.com.mv/public';

	/** @var string */
	private $api_key;
	/** @var string */
	private $app_id;
	/** @var string */
	private $base;
	/** @var bool */
	private $debug;

	public function __construct( $api_key, $app_id, $sandbox = true, $debug = false ) {
		$this->api_key = trim( (string) $api_key );
		$this->app_id  = trim( (string) $app_id );
		$this->base    = $sandbox ? self::SANDBOX_BASE : self::PRODUCTION_BASE;
		$this->debug   = (bool) $debug;
	}

	public function get_api_key() {
		return $this->api_key;
	}

	/**
	 * Create a transaction. $payload amount must already be in laari (int).
	 *
	 * @return object|WP_Error decoded BML response or error.
	 */
	public function create_transaction( array $payload ) {
		return $this->request( 'POST', '/v2/transactions', $payload );
	}

	/**
	 * Fetch a transaction's current state — the source of truth for payment status.
	 *
	 * @return object|WP_Error
	 */
	public function get_transaction( $id ) {
		return $this->request( 'GET', '/v2/transactions/' . rawurlencode( $id ) );
	}

	/**
	 * @return object|WP_Error
	 */
	private function request( $method, $endpoint, $body = null ) {
		$url  = $this->base . $endpoint;
		$args = array(
			'method'  => $method,
			'timeout' => 45,
			'headers' => array(
				'Authorization' => $this->api_key,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$this->log( "→ {$method} {$url} " . ( null !== $body ? wp_json_encode( $body ) : '' ) );

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			$this->log( '✗ transport error: ' . $response->get_error_message(), 'error' );
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw );

		$this->log( "← {$code} {$raw}" );

		if ( $code < 200 || $code >= 300 ) {
			$msg = ( is_object( $data ) && isset( $data->message ) ) ? $data->message : 'HTTP ' . $code;
			return new WP_Error( 'bmlc_api', $msg, array( 'status' => $code, 'body' => $raw ) );
		}

		return $data;
	}

	private function log( $message, $level = 'info' ) {
		if ( ! $this->debug || ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		wc_get_logger()->log( $level, $message, array( 'source' => 'bml-connect' ) );
	}
}
