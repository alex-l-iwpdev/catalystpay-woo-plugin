<?php
/**
 * FixCheckout class file.
 *
 * @package wc-catalystpay
 */

namespace WcCatalystpayGetway\Plugin;

use WC_Gateway_Catalystpay;

/**
 * FixCheckout class.
 */
class FixCheckout {
	const FIX_WC_CATALYSTPAY_ACTION_NAME = 'wc_catalystpay_get_settings_by_order_id';

	private $settings;

	/**
	 * FixCheckout construct.
	 */
	public function __construct() {

		$this->init();
	}

	/**
	 * Init actions and filter.
	 *
	 * @return void
	 */
	private function init(): void {
		add_action( 'wp_enqueue_scripts', [ $this, 'add_scripts_amd_style' ] );

		add_action( 'wp_ajax_' . self::FIX_WC_CATALYSTPAY_ACTION_NAME, [ $this, 'get_payment_setting_by_order_id' ] );
		add_action(
			'wp_ajax_nopriv_' . self::FIX_WC_CATALYSTPAY_ACTION_NAME,
			[
				$this,
				'get_payment_setting_by_order_id',
			]
		);
	}


	public function get_payment_setting_by_order_id(): void {

		$nonce = ! empty( $_POST['nonce'] ) ? filter_var( wp_unslash( $_POST['nonce'] ), FILTER_SANITIZE_FULL_SPECIAL_CHARS ) : null;

		if ( ! wp_verify_nonce( $nonce, self::FIX_WC_CATALYSTPAY_ACTION_NAME ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid nonce.', 'wc-catalystpay' ) ] );
		}

		$order_id = ! empty( $_POST['orderID'] ) ? filter_var( wp_unslash( $_POST['orderID'] ), FILTER_SANITIZE_NUMBER_INT ) : null;
		if ( empty( $order_id ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid order ID.', 'wc-catalystpay' ) ] );
		}

//		$this->settings = get_option( 'woocommerce_catalystpay_gateway_settings', true );
//
//		$catalystpay = new Catalystpay(
//			$this->settings['api_channel'],
//			$this->settings['api_token'],
//			$this->settings['test_mode'] === 'yes'
//		);
//
//		$token_id  = DBHelpers::get_transfer_token_by_order_id( $order_id );
//		$order_obj = wc_get_order( $order_id );
//		$locale    = mb_substr( get_locale(), 0, 2 );
//
//		$data_settings = [
//			'settings' =>
//				[
//					'script'       => $catalystpay->getServerUrl() . '/paymentWidgets.js?checkoutId=' . $token_id,
//					'api_merchant' => $this->settings['api_merchant'],
//					'locale'       => $locale,
//					'amount'       => $order_obj->get_total(),
//					'currency'     => $order_obj->get_currency(),
//					'gpay'         => 'yes' === $this->settings['gpay'],
//					'apple_pay'    => 'yes' === $this->settings['apple_pay'],
//					'rocket_fuel'  => 'yes' === $this->settings['rocket_fuel'],
//				],
//			'result'   => 'success',
//			'redirect' => '',
//		];

		$data_settings = WC_Gateway_Catalystpay::get_payment_object( $order_id );

		wp_send_json_success( [ 'object' => $data_settings ] );
	}

	/**
	 * Add script and style.
	 *
	 * @return void
	 */
	public function add_scripts_amd_style(): void {

		global $wp;

		if ( is_checkout() && empty( $wp->query_vars['order-pay'] ) && ! isset( $wp->query_vars['order-received'] ) || 'checkout' === $wp->query_vars['pagename'] ) {
			wp_enqueue_style( 'catalystpay_style_modal', '//cdnjs.cloudflare.com/ajax/libs/jquery-modal/0.9.2/jquery.modal.min.css', '', '0.9.2' );
			wp_enqueue_style( 'catalystpay_style', CATALYSTPAY_PLUGIN_DIR . '/assets/css/style.css', '', '1.1.0' );
			if ( ! empty( $brands ) ) {
				wp_enqueue_style( 'catalystpay_style_apm', CATALYSTPAY_PLUGIN_DIR . '/assets/css/apm.css', '', '1.1.0' );
			}
			wp_enqueue_script( 'wpwl_catalystpay_modal_script', 'https://cdnjs.cloudflare.com/ajax/libs/jquery-modal/0.9.2/jquery.modal.min.js', [ 'jquery' ], false, true );
			wp_enqueue_script( 'wpwl_catalystpay_script', CATALYSTPAY_PLUGIN_DIR . '/assets/js/script.js', [ 'jquery' ], '1.1.0', true );
		}

		wp_localize_script(
			'wpwl_catalystpay_script',
			'wcCatalystpayFix',
			[
				'ajaxurl'         => admin_url( 'admin-ajax.php' ),
				'actionGetOderId' => self::FIX_WC_CATALYSTPAY_ACTION_NAME,
				'nonceGetOderId'  => wp_create_nonce( self::FIX_WC_CATALYSTPAY_ACTION_NAME ),
			]
		);
	}
}
