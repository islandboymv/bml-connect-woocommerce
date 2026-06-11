<?php
/**
 * BML Connect WooCommerce payment gateway.
 *
 * Flow:
 *   1. process_payment() creates a BML transaction and redirects the buyer to BML.
 *   2. handle_return() runs when the buyer is redirected back — it RE-QUERIES BML
 *      (never trusts the redirect) before marking the order paid.
 *   3. handle_webhook() is the reliable confirmation path — signature-verified,
 *      idempotent, fires even if the buyer never returns to the browser.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BMLC_Gateway extends WC_Payment_Gateway {

	const TXN_META     = '_bmlc_transaction_id';
	const HISTORY_META = '_bmlc_all_transaction_ids';

	/** @var bool */
	public $testmode;
	/** @var string */
	public $api_key;
	/** @var string */
	public $app_id;
	/** @var bool */
	public $debug;

	public function __construct() {
		$this->id                 = 'bml_connect';
		$this->method_title       = __( 'BML Connect', 'bml-connect' );
		$this->method_description = __( 'Accept card & BML MobilePay payments via Bank of Maldives Connect. Buyers are redirected to BML; confirmation arrives by webhook.', 'bml-connect' );
		$this->has_fields         = false;
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->enabled     = $this->get_option( 'enabled' );
		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->testmode    = 'yes' === $this->get_option( 'testmode' );
		$this->api_key     = $this->get_option( 'api_key' );
		$this->app_id      = $this->get_option( 'app_id' );
		$this->debug       = 'yes' === $this->get_option( 'debug' );

		if ( 'yes' === $this->get_option( 'show_icon' ) && file_exists( BMLC_PATH . 'assets/img/bml.png' ) ) {
			$this->icon = apply_filters( 'bmlc_icon', BMLC_URL . 'assets/img/bml.png' );
		}

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

		// WC API endpoints — front-end, no nonce/CSRF, reachable by BML.
		add_action( 'woocommerce_api_bmlc_return', array( $this, 'handle_return' ) );
		add_action( 'woocommerce_api_bmlc_webhook', array( $this, 'handle_webhook' ) );
	}

	private function client() {
		return new BMLC_Client( $this->api_key, $this->app_id, $this->testmode, $this->debug );
	}

	public function init_form_fields() {
		$webhook_url = add_query_arg( 'wc-api', 'bmlc_webhook', home_url( '/' ) );

		$this->form_fields = array(
			'enabled'      => array(
				'title'   => __( 'Enable/Disable', 'bml-connect' ),
				'label'   => __( 'Enable BML Connect', 'bml-connect' ),
				'type'    => 'checkbox',
				'default' => 'no',
			),
			'title'        => array(
				'title'       => __( 'Title', 'bml-connect' ),
				'type'        => 'text',
				'description' => __( 'What buyers see at checkout.', 'bml-connect' ),
				'default'     => __( 'Card / BML MobilePay', 'bml-connect' ),
				'desc_tip'    => true,
			),
			'description'  => array(
				'title'   => __( 'Description', 'bml-connect' ),
				'type'    => 'textarea',
				'default' => __( 'Pay securely via Bank of Maldives. You will be redirected to BML to complete your payment.', 'bml-connect' ),
				'css'     => 'max-width:450px;',
			),
			'testmode'     => array(
				'title'       => __( 'Sandbox (test) mode', 'bml-connect' ),
				'label'       => __( 'Use the BML UAT/sandbox environment', 'bml-connect' ),
				'type'        => 'checkbox',
				'description' => __( 'Routes payments to the BML sandbox. Turn OFF for live payments (and use your production API key).', 'bml-connect' ),
				'default'     => 'yes',
			),
			'app_id'       => array(
				'title'       => __( 'Application ID', 'bml-connect' ),
				'type'        => 'text',
				'description' => __( 'Your BML Connect Application ID (UUID).', 'bml-connect' ),
				'desc_tip'    => true,
			),
			'api_key'      => array(
				'title'       => __( 'API Key (secret)', 'bml-connect' ),
				'type'        => 'password',
				'description' => __( 'Your BML Connect secret API key. Used for API calls and webhook signature verification. Never share it.', 'bml-connect' ),
				'desc_tip'    => true,
			),
			'show_icon'    => array(
				'title'   => __( 'Show BML icon', 'bml-connect' ),
				'label'   => __( 'Display the BML logo at checkout', 'bml-connect' ),
				'type'    => 'checkbox',
				'default' => 'yes',
			),
			'debug'        => array(
				'title'       => __( 'Debug logging', 'bml-connect' ),
				'label'       => __( 'Log API calls & webhooks', 'bml-connect' ),
				'type'        => 'checkbox',
				'default'     => 'no',
				'description' => __( 'View under WooCommerce → Status → Logs (source: bml-connect). Never logs your raw API key.', 'bml-connect' ),
			),
			'webhook_info' => array(
				'title'       => __( 'Webhook URL', 'bml-connect' ),
				'type'        => 'title',
				/* translators: %s: webhook URL */
				'description' => sprintf(
					__( 'Set this as the webhook URL for your app in the BML merchant dashboard:<br><code>%s</code>', 'bml-connect' ),
					esc_url( $webhook_url )
				),
			),
			'test_connection' => array(
				'title' => __( 'Test connection', 'bml-connect' ),
				'type'  => 'bmlc_test',
			),
		);
	}

	/**
	 * Renders the "Test BML connection" button. Only visible while Sandbox mode
	 * is ticked (toggled live via JS). Tests the credentials currently in the
	 * form — saving first is not required.
	 *
	 * @param string $key
	 * @param array  $data
	 * @return string
	 */
	public function generate_bmlc_test_html( $key, $data ) {
		$nonce = wp_create_nonce( 'bmlc_test_connection' );
		ob_start();
		?>
		<tr valign="top" class="bmlc-test-row">
			<th scope="row" class="titledesc"><?php esc_html_e( 'Test connection', 'bml-connect' ); ?></th>
			<td class="forminp">
				<button type="button" class="button" id="bmlc-test-btn"><?php esc_html_e( 'Test BML connection', 'bml-connect' ); ?></button>
				<span id="bmlc-test-result" style="margin-left:10px;font-weight:600;"></span>
				<p class="description"><?php esc_html_e( 'Sends a test request to BML using the credentials entered above. No payment is taken; the test transaction simply expires.', 'bml-connect' ); ?></p>
				<script>
				( function ( $ ) {
					function toggleRow() {
						$( '.bmlc-test-row' ).toggle( $( '#woocommerce_bml_connect_testmode' ).is( ':checked' ) );
					}
					$( document ).on( 'change', '#woocommerce_bml_connect_testmode', toggleRow );
					$( toggleRow );
					$( document ).on( 'click', '#bmlc-test-btn', function ( e ) {
						e.preventDefault();
						var $r = $( '#bmlc-test-result' ).css( 'color', '#646970' ).text( '<?php echo esc_js( __( 'Testing…', 'bml-connect' ) ); ?>' );
						$.post( ajaxurl, {
							action: 'bmlc_test_connection',
							nonce: '<?php echo esc_js( $nonce ); ?>',
							api_key: $( '#woocommerce_bml_connect_api_key' ).val(),
							app_id: $( '#woocommerce_bml_connect_app_id' ).val(),
							testmode: $( '#woocommerce_bml_connect_testmode' ).is( ':checked' ) ? 'yes' : 'no'
						} ).done( function ( res ) {
							if ( res && res.success ) {
								$r.css( 'color', '#46b450' ).text( '✓ ' + res.data.message );
							} else {
								$r.css( 'color', '#dc3232' ).text( '✗ ' + ( res && res.data ? res.data.message : 'Unknown error' ) );
							}
						} ).fail( function ( xhr ) {
							$r.css( 'color', '#dc3232' ).text( '✗ Request failed (HTTP ' + xhr.status + ')' );
						} );
					} );
				} )( jQuery );
				</script>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	/**
	 * AJAX: validate connectivity + credentials by creating a throwaway BML
	 * transaction (MVR 1.00, never paid). Returns a clear success or error.
	 */
	public static function ajax_test_connection() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'bml-connect' ) ), 403 );
		}
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'bmlc_test_connection' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed. Reload the page and try again.', 'bml-connect' ) ), 400 );
		}

		$api_key = isset( $_POST['api_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) ) : '';
		$app_id  = isset( $_POST['app_id'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['app_id'] ) ) ) : '';
		$sandbox = isset( $_POST['testmode'] ) && 'yes' === $_POST['testmode'];
		$env     = $sandbox ? __( 'Sandbox', 'bml-connect' ) : __( 'Production', 'bml-connect' );

		if ( empty( $api_key ) ) {
			wp_send_json_error( array( 'message' => __( 'Enter your API key first.', 'bml-connect' ) ) );
		}

		$client = new BMLC_Client( $api_key, $app_id, $sandbox, false );
		$res    = $client->create_transaction( array(
			'amount'            => 100,
			'currency'          => 'MVR',
			'localId'           => 'bmlc-connection-test-' . time(),
			'customerReference' => 'BML Connect connection test',
		) );

		if ( is_wp_error( $res ) ) {
			$data   = $res->get_error_data();
			$status = ( is_array( $data ) && isset( $data['status'] ) ) ? (int) $data['status'] : 0;
			$hint   = in_array( $status, array( 401, 403 ), true )
				? ' ' . __( 'Check your API key matches the selected environment.', 'bml-connect' )
				: '';
			wp_send_json_error( array(
				/* translators: 1: environment, 2: error message */
				'message' => sprintf( __( '%1$s connection failed: %2$s', 'bml-connect' ), $env, $res->get_error_message() ) . $hint,
			) );
		}

		if ( empty( $res->id ) ) {
			wp_send_json_error( array( 'message' => __( 'Unexpected response from BML (no transaction id returned).', 'bml-connect' ) ) );
		}

		wp_send_json_success( array(
			/* translators: 1: environment, 2: transaction id */
			'message' => sprintf( __( '%1$s connection OK — BML accepted a test transaction (%2$s).', 'bml-connect' ), $env, $res->id ),
		) );
	}

	/**
	 * Create (or reuse) a BML transaction and hand the buyer off to BML.
	 *
	 * @param int $order_id
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			wc_add_notice( __( 'Order not found.', 'bml-connect' ), 'error' );
			return array( 'result' => 'failure' );
		}

		if ( empty( $this->api_key ) ) {
			$this->log( 'process_payment aborted: API key not configured', 'error' );
			wc_add_notice( __( 'Payment is temporarily unavailable. Please contact us.', 'bml-connect' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$amount = $this->to_laari( $order->get_total() );

		// Reuse an existing, still-payable transaction instead of creating a duplicate.
		$existing = $order->get_meta( self::TXN_META );
		if ( ! empty( $existing ) ) {
			$txn = $this->client()->get_transaction( $existing );
			if ( ! is_wp_error( $txn )
				&& isset( $txn->amount, $txn->state, $txn->url )
				&& (int) $txn->amount === $amount
				&& in_array( $txn->state, array( 'INITIATED', 'QR_CODE_GENERATED' ), true ) ) {
				return array( 'result' => 'success', 'redirect' => $txn->url );
			}
		}

		$payload = array(
			'amount'            => $amount,
			'currency'          => $order->get_currency(),
			'localId'           => (string) $order->get_id(),
			'customerReference' => sprintf( /* translators: %s: order number */ __( 'Order #%s', 'bml-connect' ), $order->get_order_number() ),
			'redirectUrl'       => $this->return_url( $order ),
			'webhook'           => add_query_arg( 'wc-api', 'bmlc_webhook', home_url( '/' ) ),
		);

		$txn = $this->client()->create_transaction( $payload );

		if ( is_wp_error( $txn ) || empty( $txn->id ) || empty( $txn->url ) ) {
			$msg = is_wp_error( $txn ) ? $txn->get_error_message() : 'malformed response';
			$this->log( 'create_transaction failed: ' . $msg, 'error' );
			wc_add_notice( __( 'Unable to start the BML payment. Please try again.', 'bml-connect' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$order->update_meta_data( self::TXN_META, $txn->id );
		$order->set_transaction_id( $txn->id );
		$history = $order->get_meta( self::HISTORY_META );
		$order->update_meta_data( self::HISTORY_META, $history ? $history . ', ' . $txn->id : $txn->id );
		$order->update_status( 'pending', __( 'Awaiting BML payment.', 'bml-connect' ) );
		$order->save();

		$this->log( 'order ' . $order->get_id() . ' → BML txn ' . $txn->id . ' (' . $amount . ' laari)' );

		return array( 'result' => 'success', 'redirect' => $txn->url );
	}

	/**
	 * Buyer returns from BML. Verify our tamper-proof signature, then re-query
	 * BML for the authoritative state before completing.
	 */
	public function handle_return() {
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : false;

		if ( ! $order ) {
			wp_safe_redirect( wc_get_page_permalink( 'shop' ) );
			exit;
		}

		$sig = isset( $_GET['sig'] ) ? sanitize_text_field( wp_unslash( $_GET['sig'] ) ) : '';
		if ( ! hash_equals( $this->return_signature( $order ), $sig ) ) {
			$this->log( 'return: signature mismatch for order ' . $order_id, 'error' );
			wp_safe_redirect( wc_get_page_permalink( 'shop' ) );
			exit;
		}

		$txn_id = $order->get_meta( self::TXN_META );
		$txn    = $txn_id ? $this->client()->get_transaction( $txn_id ) : null;

		if ( ! is_wp_error( $txn ) && isset( $txn->state ) && 'CONFIRMED' === $txn->state ) {
			$this->complete_order( $order, $txn_id );
			if ( WC()->cart ) {
				WC()->cart->empty_cart();
			}
			wp_safe_redirect( $this->get_return_url( $order ) );
			exit;
		}

		wc_add_notice( __( 'Your BML payment was not completed. You can try again below.', 'bml-connect' ), 'error' );
		wp_safe_redirect( $order->get_checkout_payment_url() );
		exit;
	}

	/**
	 * Server-to-server confirmation from BML. The reliable path.
	 */
	public function handle_webhook() {
		$raw       = file_get_contents( 'php://input' );
		$nonce     = isset( $_SERVER['HTTP_X_SIGNATURE_NONCE'] ) ? $_SERVER['HTTP_X_SIGNATURE_NONCE'] : '';
		$timestamp = isset( $_SERVER['HTTP_X_SIGNATURE_TIMESTAMP'] ) ? $_SERVER['HTTP_X_SIGNATURE_TIMESTAMP'] : '';
		$signature = isset( $_SERVER['HTTP_X_SIGNATURE'] ) ? $_SERVER['HTTP_X_SIGNATURE'] : '';

		$expected = hash( 'sha256', $nonce . $timestamp . $this->api_key );

		if ( empty( $this->api_key ) || empty( $signature ) || ! hash_equals( $expected, $signature ) ) {
			$this->log( 'webhook: signature verification failed', 'error' );
			status_header( 401 );
			echo 'invalid signature';
			exit;
		}

		$data   = json_decode( $raw );
		$state  = isset( $data->state ) ? $data->state : '';
		$txn_id = isset( $data->transactionId ) ? $data->transactionId : ( isset( $data->id ) ? $data->id : '' );

		$this->log( 'webhook received: state=' . $state . ' txn=' . $txn_id );

		if ( 'CONFIRMED' === $state && $txn_id ) {
			$order = $this->find_order_by_transaction( $txn_id );
			if ( $order ) {
				$this->complete_order( $order, $txn_id );
			} else {
				$this->log( 'webhook: no order matched txn ' . $txn_id, 'warning' );
			}
		}

		// Always 200 so BML stops retrying a handled event.
		status_header( 200 );
		echo 'ok';
		exit;
	}

	/**
	 * Mark an order paid. Idempotent — safe to call from both webhook and return.
	 */
	private function complete_order( WC_Order $order, $transaction_id ) {
		if ( $order->is_paid() || in_array( $order->get_status(), array( 'processing', 'completed' ), true ) ) {
			$this->log( 'order ' . $order->get_id() . ' already paid; skipping' );
			return;
		}
		$order->payment_complete( $transaction_id );
		$order->add_order_note( sprintf( /* translators: %s: BML transaction id */ __( 'BML payment confirmed (transaction %s).', 'bml-connect' ), $transaction_id ) );
		$this->log( 'order ' . $order->get_id() . ' marked paid via txn ' . $transaction_id );
	}

	/**
	 * HPOS-safe lookup of an order by its stored BML transaction id.
	 *
	 * @return WC_Order|false
	 */
	private function find_order_by_transaction( $txn_id ) {
		$orders = wc_get_orders( array(
			'limit'      => 1,
			'meta_key'   => self::TXN_META,
			'meta_value' => $txn_id,
		) );
		return ! empty( $orders ) ? $orders[0] : false;
	}

	private function return_url( WC_Order $order ) {
		return add_query_arg( array(
			'wc-api'   => 'bmlc_return',
			'order_id' => $order->get_id(),
			'sig'      => $this->return_signature( $order ),
		), home_url( '/' ) );
	}

	/**
	 * Tamper-proof signature tying the return URL to this order + our secret key.
	 */
	private function return_signature( WC_Order $order ) {
		return hash_hmac( 'sha256', $order->get_id() . '|' . $order->get_order_key(), (string) $this->api_key );
	}

	private function to_laari( $mvr ) {
		return (int) round( (float) $mvr * 100 );
	}

	private function log( $message, $level = 'info' ) {
		if ( ! $this->debug || ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		wc_get_logger()->log( $level, $message, array( 'source' => 'bml-connect' ) );
	}
}
