<?php
/**
 * Plugin Name: Catalystpay Payment Gateway
 * Plugin URI: https://catalystpay.com/
 * Description: Payment Gateway for Catalystpay on WooCommerce
 * Author: Catalystpay Team
 * Author URI: https://catalystpay.com/
 * Version: 1.1.0
 * Text Domain: wc-catalystpay
 * WC requires at least: 3.0
 */

use Automattic\Jetpack\Constants;
use CatalystPay\CatalystPaySDK;


require_once __DIR__ . '/class.catalystpay.php';
include_once __DIR__ . '/class.catalystpay-webhook.php';

defined( 'ABSPATH' ) or exit;

define( 'CATALYSTPAY_SUPPORT_PHP', '7.3' );
define( 'CATALYSTPAY_SUPPORT_WP', '5.0' );
define( 'CATALYSTPAY_SUPPORT_WC', '3.0' );
define( 'CATALYSTPAY_DB_VERSION', '1.0' );

if ( ! defined( 'CATALYSTPAY_PLUGIN_DIR' ) ) {
	define( 'CATALYSTPAY_PLUGIN_DIR', untrailingslashit( plugins_url( '/', __FILE__ ) ) );
}

/**
 * Add the gateway to WC Available Gateways
 *
 * @param array $gateways all available WC gateways
 *
 * @return array $gateways all WC gateways + offline gateway
 * @since 1.0.0
 *
 */
function wc_catalystpay_add_to_gateways( $gateways ) {
	$gateways[] = 'WC_Gateway_Catalystpay';

	return $gateways;
}

add_filter( 'woocommerce_payment_gateways', 'wc_catalystpay_add_to_gateways' );


/**
 * Adds plugin page links
 *
 * @param array $links all plugin links
 *
 * @return array $links all plugin links + our custom links (i.e., "Settings")
 * @since 1.0.0
 *
 */
function wc_catalystpay_gateway_plugin_links( $links ) {

	$plugin_links = [
		'<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=catalystpay_gateway' ) . '">' . esc_html__( 'Settings',
			'wc-catalystpay' ) . '</a>',
	];

	return array_merge( $plugin_links, $links );
}

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'wc_catalystpay_gateway_plugin_links' );

/**
 * Catalystpay Payment Gateway
 *
 * @class        WC_Gateway_Catalystpay
 * @extends      WC_Payment_Gateway
 * @package      WooCommerce/Classes/Payment
 * @author       Vladimir Zabara
 */
add_action( 'plugins_loaded', 'wc_catalystpay_gateway_init', 11 );

if ( ! function_exists( 'is_checkout' ) ) {

	function is_checkout() {
		$page_id = wc_get_page_id( 'checkout' );

		return ( $page_id && is_page( $page_id ) ) || wc_post_content_has_shortcode( 'woocommerce_checkout' ) || apply_filters( 'woocommerce_is_checkout', false ) || Constants::is_defined( 'WOOCOMMERCE_CHECKOUT' );
	}
}

function wc_catalystpay_gateway_init() {

	if ( class_exists( "WC_Payment_Gateway_CC", false ) ) {

		new \WcCatalystpayGetway\Plugin\FixCheckout();

		class WC_Gateway_Catalystpay extends WC_Payment_Gateway_CC {

			public $supports = [
				'products',
			];

			public function __construct() {

				$this->id                 = 'catalystpay_gateway';
				$this->has_fields         = true;
				$this->method_title       = __( 'Catalystpay', 'wc-catalystpay' );
				$this->method_description = __( 'Take payments in person via Catalystpay', 'wc-catalystpay' );

				$this->init_form_fields();
				$this->init_settings();

				$this->title        = $this->settings['title'];
				$this->description  = $this->settings['description'];
				$this->instructions = $this->get_option( 'instructions', $this->description );

				$this->config = include __DIR__ . '/config.php';

				add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [
					$this,
					'process_admin_options',
				] );
				add_action( 'woocommerce_thankyou_' . $this->id, [ $this, 'thankyou_page' ] );
				add_action( "woocommerce_receipt_{$this->id}", [ $this, 'receipt_page' ] );

				add_action( 'wp_enqueue_scripts', [ $this, 'form' ] );
				add_action( 'woocommerce_email_before_order_table', [ $this, 'email_instructions' ], 10, 3 );

				$this->init();
			}

			protected function init() {
				if ( ! $this->check_environment() ) {
					return;
				}

				if ( get_site_option( 'CATALYSTPAY_DB_VERSION' ) != CATALYSTPAY_DB_VERSION ) {
					$this->install_db();
				}
			}

			public function install_db() {
				global $wpdb;

				require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

				$installed_ver = get_option( "CATALYSTPAY_DB_VERSION" );
				if ( $installed_ver != CATALYSTPAY_DB_VERSION ) {
					$sql = "CREATE TABLE IF NOT EXISTS `" . $wpdb->prefix . "catalystpay_order` (`order_id` INT(11) NOT NULL, `transaction_token` VARCHAR(255) NOT NULL, `transaction_id` VARCHAR(64) NULL, PRIMARY KEY (`order_id`), UNIQUE INDEX uidx_trx_token (`transaction_token`), INDEX (`transaction_id`)) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci";
					dbDelta( $sql );
					update_option( 'CATALYSTPAY_DB_VERSION', CATALYSTPAY_DB_VERSION );
				}
			}

			public function init_form_fields() {
				$this->form_fields = apply_filters( 'wc_catalystpay_form_fields', [
					'enabled' => [
						'title'   => __( 'Enable/Disable', 'wc-catalystpay' ),
						'type'    => 'checkbox',
						'label'   => __( 'Enable Catalystpay', 'wc-catalystpay' ),
						'default' => 'yes',
					],

					'title' => [
						'title'       => __( 'Title', 'wc-catalystpay' ),
						'type'        => 'text',
						'description' => __( 'This controls the title for the payment method the customer sees during checkout.',
							'wc-catalystpay' ),
						'default'     => __( 'Catalystpay', 'wc-catalystpay' ),
						'desc_tip'    => true,
					],

					'description' => [
						'title'       => __( 'Description', 'wc-catalystpay' ),
						'type'        => 'textarea',
						'description' => __( 'Payment method description that the customer will see on your checkout.',
							'wc-catalystpay' ),
						'default'     => '',
						'desc_tip'    => true,
					],

					'instructions' => [
						'title'       => __( 'Instructions', 'wc-catalystpay' ),
						'type'        => 'textarea',
						'description' => __( 'Instructions that will be added to the thank you page and emails.',
							'wc-catalystpay' ),
						'default'     => '',
						'desc_tip'    => true,
					],

					'account_settings' => [
						'title'       => __( 'Account Settings', 'wc-catalystpay' ),
						'type'        => 'title',
						'description' => '',
					],

					'api_merchant' => [
						'title'       => __( 'API Merchant', 'wc-catalystpay' ),
						'type'        => 'text',
						'description' => __( 'Retrieve the "API Merchant" from your Catalystpay merchant account.',
							'wc-catalystpay' ),
						'default'     => '',
						'desc_tip'    => true,
					],

					'api_channel' => [
						'title'       => __( 'API Channel', 'wc-catalystpay' ),
						'type'        => 'text',
						'description' => __( 'Retrieve the "API Channel" from your Catalystpay merchant account.',
							'wc-catalystpay' ),
						'default'     => '',
						'desc_tip'    => true,
					],

					'api_token' => [
						'title'       => __( 'API Token', 'wc-catalystpay' ),
						'type'        => 'text',
						'description' => __( 'Retrieve the "API Token" from your Catalystpay merchant account.',
							'wc-catalystpay' ),
						'default'     => '',
						'desc_tip'    => true,
					],

					'api_secret' => [
						'title'       => __( 'API Webhook Secret', 'wc-catalystpay' ),
						'type'        => 'text',
						'description' => __( 'Retrieve the "APIWebhook Secret" from your Catalystpay merchant account.',
							'wc-catalystpay' ),
						'default'     => '',
						'desc_tip'    => true,
					],

					'preauthorize' => [
						'title'   => __( 'Preauthorize', 'wc-catalystpay' ),
						'type'    => 'checkbox',
						'label'   => __( 'Enable preauthorize transactions', 'wc-catalystpay' ),
						'default' => 'no',
					],

					'gpay'             => [
						'title'   => __( 'Google Pay', 'wc-catalystpay' ),
						'type'    => 'checkbox',
						'label'   => __( 'Enable Google Pay', 'wc-catalystpay' ),
						'default' => 'no',
					],
					'gpay_merchant_id' => [
						'title'             => __( 'Gpay Merchant ID', 'wc-catalystpay' ),
						'type'              => 'text',
						'label'             => __( 'Gpay Merchant ID', 'wc-catalystpay' ),
						'default'           => '',
						'class'             => 'gpay-merchant-id-field',
						'css'               => 'display: none;',
						'custom_attributes' => [
							'disabled' => 'disabled',
						],
						'hide_field'        => true,
					],
					'apple_pay'        => [
						'title'   => __( 'Apple Pay', 'wc-catalystpay' ),
						'type'    => 'checkbox',
						'label'   => __( 'Enable Apple Pay', 'wc-catalystpay' ),
						'default' => 'no',
					],
					'rocket_fuel'      => [
						'title'   => __( 'RocketFuel', 'wc-catalystpay' ),
						'type'    => 'checkbox',
						'label'   => __( 'Enable RocketFuel', 'wc-catalystpay' ),
						'default' => 'no',
					],

					'subscription' => [
						'title'   => __( 'Subscription', 'wc-catalystpay' ),
						'type'    => 'checkbox',
						'label'   => __( 'Enable Subscription', 'wc-catalystpay' ),
						'default' => 'no',
					],

					'checkout' => [
						'title'   => __( 'checkout', 'wc-catalystpay' ),
						'type'    => 'checkbox',
						'label'   => __( 'One Click Checkout', 'wc-catalystpay' ),
						'default' => 'no',
					],

					'test_mode' => [
						'title'   => __( 'Test Mode', 'wc-catalystpay' ),
						'type'    => 'checkbox',
						'label'   => __( 'Enable test mode', 'wc-catalystpay' ),
						'default' => 'yes',
					],
				] );
			}

			public function payment_fields() {
				global $wp;

				if ( false === empty( $this->description ) ) {
					_e( wpautop( wptexturize( $this->description . PHP_EOL ) ) );
				}
			}

			public function form() {
				global $wp;

				if ( is_checkout() && empty( $wp->query_vars['order-pay'] ) && ! isset( $wp->query_vars['order-received'] ) || 'checkout' === $wp->query_vars['pagename'] ) {
					$brands = 'yes' === $this->settings['gpay'] ? ' GOOGLEPAY' : '';
					$brands .= 'yes' === $this->settings['apple_pay'] ? ' APPLEPAY' : '';
					$brands .= 'yes' === $this->settings['rocket_fuel'] ? ' ROCKETFUEL' : ''; ?>
					<div class="modal catalystpay-wrapper" tabindex="-1" role="dialog" id="ccModal">

						<form action="#" class="paymentWidgets" data-brands="VISA MASTER AMEX<?php echo $brands; ?>"
							  id="cc-form"></form>
						<?php $plugin_dir_url = plugin_dir_url( __FILE__ ); ?>
						<div class="form-footer">
							<span class="footer-text">PCI DSS Compliant | 128-Bit Encryption | Verified by VISA | MasterCard SecureCode</span><br>
							<div class="footer-images">
								<img src="<?php echo esc_url( $plugin_dir_url . 'assets/pci-dss.png' ); ?>"
									 class="footer-img">
								<img src="<?php echo esc_url( $plugin_dir_url . 'assets/secure-ssl.jpg' ); ?>"
									 class="footer-img">
								<img src="<?php echo esc_url( $plugin_dir_url . 'assets/verified-by-visa.jpg' ); ?>"
									 class="footer-img">
								<img src="<?php echo esc_url( $plugin_dir_url . 'assets/mastercard.jpg' ); ?>"
									 class="footer-img">
							</div>
						</div>
					</div>
					<?php
				}
			}

			public function thankyou_page( $order ) {
				$order_info = wc_get_order( $order );
				$id         = sanitize_text_field( $_GET['id'] );
				if ( $order_info->has_status( [ 'processing', 'shipped', 'completed' ] ) ) {
					return;
				}

				global $wpdb;
				$catalystpay = new Catalystpay(
					$this->settings['api_channel'],
					$this->settings['api_token'],
					$this->settings['test_mode'] === 'yes'
				);


				$result = $catalystpay->getTransactionInfo( $id );

				if ( ! $result || ! isset( $result['result']['code'] ) || ! preg_match( '/^(000.000.|000.100.1|000.[36]|000.400.[1][12]0)/', $result['result']['code'] ) ) {
					$order_info->update_status( 'failed' );
					$url = $_SERVER['REQUEST_URI'];
					if ( strpos( $url, 'refresh=1' ) === false ) {
						$url .= ( strpos( $url, '?' ) === false ? '?' : '&' ) . 'refresh=1';
						echo '<script type="text/javascript">
                            window.location.href = "' . $url . '";
                        </script>';
						exit;
					}
				} else {
					if ( $this->settings['preauthorize'] === 'yes' ) {
						$order_info->update_status( 'pending' );
					} else {
						$transaction_id = $result['id'];
						$update         = "UPDATE `{$wpdb->prefix}wc_orders` SET `transaction_id` =  '$transaction_id' WHERE `id` = $order";
						$wpdb->query( $update );
						$update = "UPDATE `{$wpdb->prefix}catalystpay_order` SET `transaction_id` =  '$transaction_id' WHERE `order_id` = $order";
						$wpdb->query( $update );

						$sel        = "SELECT * FROM `{$wpdb->prefix}wc_orders` WHERE `id` = $order";
						$order_data = $wpdb->get_row( $sel, ARRAY_A );

						if ( $order_data && isset( $order_data['transaction_id'] ) && $order_data['transaction_id'] != '' ) {
							$order_info->update_status( 'completed' );
						}

						if ( ! empty( $result['registrationId'] ) ) {
							$current_user_id = get_current_user_id();
							$registrationid  = $result['registrationId'];
							$existing_tokens = $wpdb->get_col(
								$wpdb->prepare(
									"SELECT registration_token FROM {$wpdb->prefix}catalystpay_registration WHERE user_id = %d",
									$current_user_id
								)
							);

							if ( in_array( $registrationid, $existing_tokens ) ) {
								echo 'Registration token already exists for this user.';
							} else {
								$insert_result = $wpdb->replace(
									"{$wpdb->prefix}catalystpay_registration",
									[
										'user_id'            => $current_user_id,
										'registration_token' => $registrationid,
									],
									[
										'%d',
										'%s',
									]
								);
							}
						}

						if ( $order_info->has_status( [ 'completed' ] ) ) {
							$url  = $_SERVER['REQUEST_URI'];
							$url  .= ( strpos( $url, '?' ) === false ? '?' : '&' ) . 'refresh=1';
							$note = __( 'Transfer id: ', 'wc-catalystpay' ) . $result['id'];
							$order_info->add_order_note( $note );
							wp_safe_redirect( $url, 301 );
							die();
						}
					}
				}


				if ( $this->instructions ) {
					_e( wpautop( wptexturize( $this->instructions ) ) );
				}
			}

			public function email_instructions( $order, $sent_to_admin, $plain_text = false ) {
				if ( $this->instructions && ! $sent_to_admin && $this->id === $order->payment_method && $order->has_status( 'on-hold' ) ) {
					echo esc_html( $this->instructions . PHP_EOL );
				}
			}

			function is_product_in_subscription_table( $product_id ) {
				global $wpdb;

				$table_name = $wpdb->prefix . 'subscriptions';
				$result     = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_name WHERE product_id = %d", $product_id ) );

				return $result ? true : false;
			}

			public static function get_payment_object( $order_id ) {
				$instance = new self();

				// Вызываем публичный метод на экземпляре класса
				return $instance->process_payment( $order_id );
			}

			public function process_payment( $order_id ) {

				global $wpdb;

				$order = wc_get_order( $order_id );

				global $product;


				$is_subscription_product = false;
				foreach ( $order->get_items() as $item_id => $item ) {
					$product_id = $item->get_product_id();
					if ( is_product_in_subscription_table( $product_id ) ) {
						$is_subscription_product = true;
						break;
					}
				}

				if ( ! $is_subscription_product ) {
					$locale = mb_substr( get_locale(), 0, 2 );
					false === in_array( $locale, $this->config['supported_locales'], true ) && $locale = 'en';
					$user_id = get_current_user_id();

					if ( $this->settings['checkout'] == "yes" && $user_id > 0 ) {

						$table_name = $wpdb->prefix . 'catalystpay_registration';

						// Run the query to get data from the table
						$query   = $wpdb->prepare( "SELECT * FROM $table_name WHERE user_id = %d", $user_id );
						$results = $wpdb->get_results( $query, OBJECT );

						$catalystpay_data = [
							'amount'                      => $order->get_total(),
							'currency'                    => $order->get_currency(),
							'paymentType'                 => $this->settings['preauthorize'] === 'yes' ? 'PA' : 'DB',
							'locale'                      => $locale,
							'customer.merchantCustomerId' => $order->get_customer_id(),
							'customer.givenName'          => $order->get_billing_first_name(),
							'customer.surname'            => $order->get_billing_last_name(),
							'customer.email'              => $order->get_billing_email(),
							'customer.ip'                 => $order->get_customer_ip_address(),
							'customer.phone'              => $order->get_billing_phone(),
							'customer.language'           => strtoupper( $locale ),
							'customer.status'             => $order->get_customer_id() > 0 ? 'EXISTING' : 'NEW',
							'shipping.street1'            => $order->get_shipping_address_1(),
							'shipping.street2'            => $order->get_shipping_address_2(),
							'shipping.city'               => $order->get_shipping_city(),
							'shipping.state'              => str_replace( '-', '', $order->get_shipping_state() ),
							'shipping.postcode'           => $order->get_shipping_postcode(),
							'shipping.country'            => $order->get_shipping_country(),
							'shipping.comment'            => $order->get_customer_note(),
							'shipping.type'               => 'SHIPMENT',
							'billing.street1'             => $order->get_billing_address_1(),
							'billing.street2'             => $order->get_billing_address_2(),
							'billing.city'                => $order->get_billing_city(),
							'billing.state'               => str_replace( '-', '', $order->get_billing_state() ),
							'billing.postcode'            => $order->get_billing_postcode(),
							'billing.country'             => $order->get_billing_country(),
						];

						$registration_token = [];


						foreach ( $results as $index => $registration_id ) {
							$registration_token[ "registrations[" . $index . "].id" ] = $registration_id->registration_token;
						}
						$catalystpay_data        = array_merge( $catalystpay_data, $registration_token );
						$data['checkout_button'] = true;

						$catalystpay = new Catalystpay(
							$this->settings['api_channel'],
							$this->settings['api_token'],
							$this->settings['test_mode'] === 'yes'
						);

						$result = $catalystpay->init( $catalystpay_data );

						if ( $catalystpay->hasErrors() ) {
							wc_get_logger()->critical(
								sprintf( 'CatalystPay debug ( Init payment ): %s', json_encode( (array) $result ) ),
								[
									'source' => 'catalystpay-errors',
								]
							);

							wc_add_notice( __( 'Your transaction is not completed .', 'wc-catalystpay' ), 'error' );

							return false;
						} else {

							$wpdb->_insert_replace_helper(
								$wpdb->prefix . 'catalystpay_order',
								[
									'order_id'          => $order->get_id(),
									'transaction_token' => $result['ndc'],
								],
								null,
								'replace'
							);

							return [
								'settings' => json_encode( [
									'script'       => $catalystpay->getServerUrl() . '/paymentWidgets.js?checkoutId=' . $result['id'],
									'api_merchant' => $this->settings['api_merchant'],
									'locale'       => $locale,
									'amount'       => $order->get_total(),
									'currency'     => $order->get_currency(),
									'gpay'         => 'yes' === $this->settings['gpay'],
									'apple_pay'    => 'yes' === $this->settings['apple_pay'],
									'rocket_fuel'  => 'yes' === $this->settings['rocket_fuel'],
								] ),
								'result'   => 'success',
								//'redirect' => $this->payment_success($order , $result),
								'redirect' => $this->get_return_url( $order ),
							];
						}

					} else {
						$data = [
							'amount'                      => $order->get_total(),
							'currency'                    => $order->get_currency(),
							'paymentType'                 => $this->settings['preauthorize'] === 'yes' ? 'DB' : 'DB',
							'locale'                      => $locale,
							'customer.merchantCustomerId' => $order->get_customer_id(),
							'customer.givenName'          => $order->get_billing_first_name(),
							'customer.surname'            => $order->get_billing_last_name(),
							'customer.email'              => $order->get_billing_email(),
							'customer.ip'                 => $order->get_customer_ip_address(),
							'customer.phone'              => $order->get_billing_phone(),
							'customer.language'           => strtoupper( $locale ),
							'customer.status'             => $order->get_customer_id() > 0 ? 'EXISTING' : 'NEW',
							'shipping.street1'            => $order->get_shipping_address_1(),
							'shipping.street2'            => $order->get_shipping_address_2(),
							'shipping.city'               => $order->get_shipping_city(),
							'shipping.state'              => str_replace( '-', '', $order->get_shipping_state() ),
							'shipping.postcode'           => $order->get_shipping_postcode(),
							'shipping.country'            => $order->get_shipping_country(),
							'shipping.comment'            => $order->get_customer_note(),
							'shipping.type'               => 'SHIPMENT',
							'billing.street1'             => $order->get_billing_address_1(),
							'billing.street2'             => $order->get_billing_address_2(),
							'billing.city'                => $order->get_billing_city(),
							'billing.state'               => str_replace( '-', '', $order->get_billing_state() ),
							'billing.postcode'            => $order->get_billing_postcode(),
							'billing.country'             => $order->get_billing_country(),
						];

						$key = 0;
						/** @var WC_Order_Item_Product $product */
						foreach ( $order->get_items() as $product ) {
							$data[ 'cart.items[' . $key . '].name' ]           = $product->get_name();
							$data[ 'cart.items[' . $key . '].quantity' ]       = $product->get_quantity();
							$data[ 'cart.items[' . $key . '].originalPrice' ]  = number_format( (float) $product->get_product()
								->get_price(), 2, '.', '' );
							$data[ 'cart.items[' . $key ++ . '].totalAmount' ] = number_format( (float) $product->get_total(), 2, '.', '' );
						}

						$catalystpay = new Catalystpay(
							$this->settings['api_channel'],
							$this->settings['api_token'],
							$this->settings['test_mode'] === 'yes'
						);

						$result = $catalystpay->init( $data );

						if ( $catalystpay->hasErrors() ) {
							wc_get_logger()->critical(
								sprintf( 'CatalystPay debug ( Init payment ): %s', json_encode( (array) $result ) ),
								[
									'source' => 'catalystpay-errors',
								]
							);

							wc_add_notice( __( 'Your transaction is not completed .', 'wc-catalystpay' ), 'error' );

							return false;
						} else {
							$wpdb->_insert_replace_helper(
								$wpdb->prefix . 'catalystpay_order',
								[
									'order_id'          => $order->get_id(),
									'transaction_token' => $result['ndc'],
								],
								null,
								'replace'
							);

							return [
								'settings' => json_encode( [
									'script'       => $catalystpay->getServerUrl() . '/paymentWidgets.js?checkoutId=' . $result['id'],
									'api_merchant' => $this->settings['api_merchant'],
									'locale'       => $locale,
									'amount'       => $order->get_total(),
									'currency'     => $order->get_currency(),
									'gpay'         => 'yes' === $this->settings['gpay'],
									'apple_pay'    => 'yes' === $this->settings['apple_pay'],
									'rocket_fuel'  => 'yes' === $this->settings['rocket_fuel'],
								] ),
								'result'   => 'success',
								'redirect' => $this->get_return_url( $order ),
							];
						}
					}
				} else {
					$locale = mb_substr( get_locale(), 0, 2 );
					false === in_array( $locale, $this->config['supported_locales'], true ) && $locale = 'en';
					$data = [
						'amount'                      => $order->get_total(),
						'currency'                    => $order->get_currency(),
						'paymentType'                 => $this->settings['preauthorize'] === 'yes' ? 'PA' : 'DB',
						'locale'                      => $locale,
						'customer.merchantCustomerId' => $order->get_customer_id(),
						'customer.givenName'          => $order->get_billing_first_name(),
						'customer.surname'            => $order->get_billing_last_name(),
						'customer.email'              => $order->get_billing_email(),
						'customer.ip'                 => $order->get_customer_ip_address(),
						'customer.phone'              => $order->get_billing_phone(),
						'customer.language'           => strtoupper( $locale ),
						'customer.status'             => $order->get_customer_id() > 0 ? 'EXISTING' : 'NEW',
						'shipping.street1'            => $order->get_shipping_address_1(),
						'shipping.street2'            => $order->get_shipping_address_2(),
						'shipping.city'               => $order->get_shipping_city(),
						'shipping.state'              => str_replace( '-', '', $order->get_shipping_state() ),
						'shipping.postcode'           => $order->get_shipping_postcode(),
						'shipping.country'            => $order->get_shipping_country(),
						'shipping.comment'            => $order->get_customer_note(),
						'shipping.type'               => 'SHIPMENT',
						'billing.street1'             => $order->get_billing_address_1(),
						'billing.street2'             => $order->get_billing_address_2(),
						'billing.city'                => $order->get_billing_city(),
						'billing.state'               => str_replace( '-', '', $order->get_billing_state() ),
						'billing.postcode'            => $order->get_billing_postcode(),
						'billing.country'             => $order->get_billing_country(),
					];

					$key = 0;
					/** @var WC_Order_Item_Product $product */
					foreach ( $order->get_items() as $product ) {
						$data[ 'cart.items[' . $key . '].name' ]           = $product->get_name();
						$data[ 'cart.items[' . $key . '].quantity' ]       = $product->get_quantity();
						$data[ 'cart.items[' . $key . '].originalPrice' ]  = number_format( (float) $product->get_product()
							->get_price(), 2, '.', '' );
						$data[ 'cart.items[' . $key ++ . '].totalAmount' ] = number_format( (float) $product->get_total(), 2, '.', '' );
					}

					$catalystpay = new Catalystpay(
						$this->settings['api_channel'],
						$this->settings['api_token'],
						$this->settings['test_mode'] === 'yes'
					);

					$CatalystPaySDK = new CatalystPaySDK(
						$this->settings['api_token'],
						$this->settings['api_channel'],
						$this->settings['test_mode'] ? false : true,

					);

					$baseOptions = [
						"testMode"           => 'EXTERNAL',
						"createRegistration" => 'true',
					];

					$responseData = $CatalystPaySDK->prepareRegisterCheckout( $baseOptions );

					if ( $catalystpay->hasErrors() ) {
						wc_get_logger()->critical(
							sprintf( 'CatalystPay debug ( Init payment ): %s', json_encode( (array) $responseData ) ),
							[
								'source' => 'catalystpay-errors',
							]
						);

						wc_add_notice( __( 'Your transaction is not completed .', 'wc-catalystpay' ), 'error' );

						return false;
					} else {
						$wpdb->_insert_replace_helper(
							$wpdb->prefix . 'catalystpay_order',
							[
								'order_id'          => $order->get_id(),
								'transaction_token' => $responseData->getId(),
								//'transaction_token' => 'fdsrzg',

							],
							null,
							'replace'
						);

						return [
							'settings' => json_encode( [
								'script'       => $catalystpay->getServerUrl() . '/paymentWidgets.js?checkoutId=' . $responseData->getId() . '/registration',
								'api_merchant' => $this->settings['api_merchant'],
								'locale'       => $locale,
								'amount'       => $order->get_total(),
								'currency'     => $order->get_currency(),
								'gpay'         => 'yes' === $this->settings['gpay'],
								'apple_pay'    => 'yes' === $this->settings['apple_pay'],
								'rocket_fuel'  => 'yes' === $this->settings['rocket_fuel'],
							] ),
							'result'   => 'success',
							'redirect' => $this->subscription_success( $order, $responseData->getId() ),
						];
					}
				}
			}

			public function payment_success( $order, $result ) {


				global $wpdb;
				$order = wc_get_order( $order->id );

				if ( isset( $result['id'] ) ) {
					$id = $result['id'];

					$catalystpay = new Catalystpay(
						$this->settings['api_channel'],
						$this->settings['api_token'],
						$this->settings['test_mode'] === 'yes'
					);

					$result1 = $catalystpay->getTransactionInfo( $id );

					if ( $catalystpay->hasErrors() ) {
						wc_add_notice( __( 'Your transaction is not completed.', 'wc-catalystpay' ), 'error' );
						$errors = $catalystpay->getErrors();
						$order->add_order_note( __( 'Your transaction is not completed.', 'wc-catalystpay' ) . ' ' . current( $errors ) );
						$order->update_status( 'cancelled', isset( $result['id'] ) ? 'Txn: ' . esc_html( $result['id'] ) . ' - ' : '' );
						wc_print_notices();
					} else {
						$wpdb->update(
							$wpdb->prefix . 'catalystpay_order',
							[ 'transaction_id' => $result['id'] ],
							[ 'order_id' => $order->get_id() ],
						);

						$status = 'failed';
						if ( ! empty( $result['result']['code'] ) ) {
							if ( preg_match( '/^(000.000.|000.100.1|000.[36]|000.400.[1][12]0)/', $result['result']['code'] ) ) {
								$status = 'success';
							} elseif ( preg_match( '/^(000\.200)/', $result['result']['code'] ) ) {
								$status = 'pending';
							}
						}

						$this->$status( $order, $result );

						return $this->get_return_url( $order );
					}
				}

			}


			public function subscription_success( $order, $resultid ) {
				$catalystpay = new Catalystpay(
					$this->settings['api_channel'],
					$this->settings['api_token'],
					$this->settings['test_mode'] === 'yes'
				);

				$result = $catalystpay->getRegistrationStatus( $resultid );
				if ( $result ) {
					$data = [
						'paymentBrand'              => "VISA",
						'paymentType'               => 'PA',
						'amount'                    => $order->get_total(),
						'currency'                  => $order->get_currency(),
						"standingInstructionType"   => "RECURRING",
						"standingInstructionMode"   => "REPEATED",
						"standingInstructionSource" => "MIT",
						'testMode'                  => 'EXTERNAL',
					];

					$registerPayment = $catalystpay->sendRegistrationTokenPayment( $resultid, $data );
					if ( $registerPayment ) {
						// Get the first item from the order
						$items = $order->get_items();
						if ( ! empty( $items ) ) {
							$first_item = reset( $items );
							$product_id = $first_item->get_product_id();

							global $wpdb;
							$table_name           = $wpdb->prefix . 'subscriptions';
							$sel                  = $wpdb->prepare( "SELECT * FROM $table_name WHERE product_id = %d", $product_id );
							$subscription_details = $wpdb->get_results( $sel );

							if ( ! empty( $subscription_details ) ) {
								// Single product get
								$first_subscription = $subscription_details[0];
								$trial_frequency    = esc_html( $first_subscription->trial_subscription_frequency );
								$trial_duration     = esc_html( $first_subscription->trial_subscription_duration );
								$frequency          = esc_html( $first_subscription->subscription_frequency );

								$current_date = date( 'Y-m-d' );
								if ( $trial_frequency == "day" ) {
									$startdate = date( 'Y-m-d', strtotime( "+$trial_duration days", strtotime( $current_date ) ) );
								} elseif ( $trial_frequency == "week" ) {
									$trial_days = $trial_duration * 7;
									$startdate  = date( 'Y-m-d', strtotime( "+$trial_days days", strtotime( $current_date ) ) );
								} elseif ( $trial_frequency == "month" ) {
									$trial_days = $trial_duration * 30;
									$startdate  = date( 'Y-m-d', strtotime( "+$trial_days days", strtotime( $current_date ) ) );
								} elseif ( $trial_frequency == "year" ) {
									$trial_days = $trial_duration * 365;
									$startdate  = date( 'Y-m-d', strtotime( "+$trial_days days", strtotime( $current_date ) ) );
								}

								if ( $frequency == 'day' ) {
									$dayofmonth = "*";
									$month      = "*";
									$dayofweek  = "?";
									$year       = "*";
								} elseif ( $frequency == 'week' ) {
									$dayofmonth = "?";
									$month      = "*";
									$dayofweek  = "3"; // Assuming Tuesday is represented by 3
									$year       = "*";
								} elseif ( $frequency == 'month' ) {
									$dayofmonth = "L"; // Last day of the month
									$month      = "*";
									$dayofweek  = "?";
									$year       = "*";
								} elseif ( $frequency == 'year' ) {
									$dayofmonth = "24";
									$month      = "1";
									$dayofweek  = "?";
									$year       = "*";
								} else {
									echo "frequency not found";
								}

								$subscribe_payment_data = [
									'entityId'                          => $order->get_id(),
									'amount'                            => $order->get_total(),
									'currency'                          => $order->get_currency(),
									'paymentType'                       => 'DB',
									'registrationId'                    => $registerPayment['id'],
									"standingInstruction.type"          => "RECURRING",
									"standingInstruction.mode"          => "REPEATED",
									"standingInstruction.source"        => "MIT",
									"standingInstruction.recurringType" => "SUBSCRIPTION",
									"testMode"                          => "EXTERNAL",
									"job.second"                        => 01,
									"job.minute"                        => 01,
									"job.hour"                          => 01,
									"job.dayOfMonth"                    => $dayofmonth,
									"job.month"                         => $month,
									"job.dayOfWeek"                     => $dayofweek,
									"job.year"                          => $year,
									"job.startDate"                     => $startdate,
								];
								$result_pay             = $catalystpay->paymentSubscriptionCard( $subscribe_payment_data );
								$transaction_id         = $resultid;
								$order_id               = $order->get_id();

								$update = "UPDATE `{$wpdb->prefix}wc_orders` SET `transaction_id` =  '$transaction_id' WHERE `id` = $order_id";
								$wpdb->query( $update );

								$sel        = "SELECT * FROM `{$wpdb->prefix}wc_orders` WHERE `id` = $order_id";
								$order_data = $wpdb->get_row( $sel, ARRAY_A );

								if ( $order_data && isset( $order_data['transaction_id'] ) && $order_data['transaction_id'] != '' ) {
									$order->update_status( 'active' );
								} else {

								}

								return $this->get_return_url( $order );


							} else {
								echo 'No subscription details found for product ID: ' . $product_id;
							}
						} else {
							echo 'No items found in the order.';
						}
					}
				} else {
					error_log( 'Failed to get registration status or missing ID. Registration ID: ' . $resultid );

					wc_add_notice( __( 'Failed to get registration status. Please contact support.', 'wc-catalystpay' ), 'error' );

					return wc_get_checkout_url();
				}
			}


			public function process_refund( $order_id, $amount = null, $reason = '' ) {
				return true;
			}

			public function receipt_page( $order_id ) {
				global $wpdb;

				$order = new WC_Order( $order_id );

				if ( isset( $_GET['id'] ) ) {
					$id = sanitize_text_field( $_GET['id'] );

					$catalystpay = new Catalystpay(
						$this->settings['api_channel'],
						$this->settings['api_token'],
						$this->settings['test_mode'] === 'yes'
					);

					$result = $catalystpay->getTransactionInfo( $id );

					if ( $catalystpay->hasErrors() ) {
						wc_add_notice( __( 'Your transaction is not completed.', 'wc-catalystpay' ), 'error' );
						$errors = $catalystpay->getErrors();
						$order->add_order_note( __( 'Your transaction is not completed.', 'wc-catalystpay' ) . ' ' . current( $errors ) );
						$order->update_status( 'cancelled', isset( $result['id'] ) ? 'Txn: ' . esc_html( $result['id'] ) . ' - ' : '' );
						wc_print_notices();
					} else {
						$wpdb->update(
							$wpdb->prefix . 'catalystpay_order',
							[ 'transaction_id' => $result['id'] ],
							[ 'order_id' => $order->get_id() ],
						);

						$registrationId = $result['registrationId'];

						if ( ! empty( $registrationId ) ) {
							$registration_table_name = $wpdb->prefix . 'catalystpay_registration';

							$subscription_data = [
								'user_id'            => $post_id,
								'registration_token' => $registrationId,
							];
							$wpdb->insert( $registration_table_name, $subscription_data );
						}

						$status = 'failed';
						if ( ! empty( $result['result']['code'] ) ) {
							if ( preg_match( '/^(000.000.|000.100.1|000.[36]|000.400.[1][12]0)/', $result['result']['code'] ) ) {
								$status = 'success';
							} elseif ( preg_match( '/^(000\.200)/', $result['result']['code'] ) ) {
								$status = 'pending';
							}
						}

						$this->$status( $order, $result );
					}
				}
			}

			public function success( WC_Order $order, $result ) {
				global $wpdb;

				$result['id'] = esc_html( $result['id'] );

				if ( isset( $result['paymentType'] ) && 'PA' === $result['paymentType'] ) {
					$order->add_meta_data( 'is_pre_authorization', true );
					$order->add_meta_data( 'transaction_id', $result['id'] );
					$order->add_order_note( 'Preauthorize transaction, need to be captured' );
					$order->set_status( 'on-hold', 'Txn: ' . $result['id'] . ' - ' );
				} else {
					$order->add_meta_data( 'is_pre_authorization', true );
					$order->add_meta_data( 'transaction_id', $result['id'] );
					$order->add_order_note( 'Preauthorize transaction, need to be captured' );
					$order->set_status( 'Completed', 'Txn: ' . $result['id'] . ' - ' );
				}

				$wpdb->update(
					$wpdb->prefix . 'catalystpay_order',
					[ 'transaction_id' => $result['id'] ],
					[ 'order_id' => $order->get_id() ],
				);

				$registrationId = $result['registrationId'];

				if ( ! empty( $registrationId ) ) {
					$registration_table_name = $wpdb->prefix . 'catalystpay_registration';

					$subscription_data = [
						'user_id'            => $post_id,
						'registration_token' => $registrationId,
					];
					$wpdb->insert( $registration_table_name, $subscription_data );
				}


				$order->add_order_note( __( 'Payment has been processed successfully.', 'wc-catalystpay' ) .
				                        __( 'Transaction ID: ', 'wc-catalystpay' ) .
				                        $result['id']
				);

				$order->save();

				WC()->cart->empty_cart();

				wp_redirect( $this->get_return_url( $order ) );
			}

			public function failed( WC_Order $order, $result ) {
				$order->update_status( 'cancelled', 'Txn: ' . esc_html( $result['id'] ) . ' - ' );
				wc_add_notice( __( 'Your transaction is not completed .', 'wc-catalystpay' ), 'error' );
				wc_print_notices();
			}

			public function pending( WC_Order $order, $result ) {
				$order->update_status( 'on-hold', 'Txn: ' . esc_html( $result['id'] ) . ' - ' );
				$order->add_meta_data( 'gateway_note', __( 'Transaction is pending confirmation from Catalystpay', 'wc-catalystpay' ) );
				$order->save();

				$order->add_order_note( __( 'The order waiting gateway confirmation.', 'wc-catalystpay' ) . ' ' .
				                        __( 'Transaction ID: ', 'wc-catalystpay' ) .
				                        esc_html( $result['id'] ) );

				WC()->cart->empty_cart();

				wp_redirect( $this->get_return_url( $order ) );
			}

			public function check_environment() {
				$is_ok = true;

				// Check PHP version
				if ( ! version_compare( PHP_VERSION, CATALYSTPAY_SUPPORT_PHP, '>=' ) ) {
					// Add notice
					add_action( 'admin_notices', function () {
						echo '<div class="error"><p>'
						     . esc_html__( sprintf( '<strong>Catalystpay Payment Gateway</strong> requires PHP version %s or later.',
								CATALYSTPAY_SUPPORT_PHP ), 'wc-catalystpay' )
						     . '</p></div>';
					} );
					$is_ok = false;
				}

				// Check WordPress version
				if ( ! $this->wp_version_gte( CATALYSTPAY_SUPPORT_WP ) ) {
					add_action( 'admin_notices', function () {
						echo '<div class="error"><p>'
						     . esc_html__( sprintf( '<strong>Catalystpay Payment Gateway</strong> requires WordPress version %s or later. Please update WordPress to use this plugin.',
								CATALYSTPAY_SUPPORT_WP ), 'wc-catalystpay' )
						     . '</p></div>';
					} );
					$is_ok = false;
				}

				// Check if WooCommerce is installed and enabled
				if ( ! class_exists( 'WooCommerce' ) ) {
					add_action( 'admin_notices', function () {
						echo '<div class="error"><p>'
						     . esc_html__( '<strong>Catalystpay Payment Gateway</strong> requires WooCommerce to be active.',
								'wc-catalystpay' )
						     . '</p></div>';
					} );
					$is_ok = false;
				} elseif ( ! $this->wc_version_gte( CATALYSTPAY_SUPPORT_WC ) ) {
					add_action( 'admin_notices', function () {
						echo '<div class="error"><p>'
						     . esc_html__( sprintf( '<strong>Catalystpay Payment Gateway</strong> requires WooCommerce version %s or later.',
								CATALYSTPAY_SUPPORT_WC ), 'wc-catalystpay' )
						     . '</p></div>';
					} );
					$is_ok = false;
				}

				return $is_ok;
			}

			public static function wc_version_gte( $version ) {
				if ( defined( 'WC_VERSION' ) && WC_VERSION ) {
					return version_compare( WC_VERSION, $version, '>=' );
				} elseif ( defined( 'WOOCOMMERCE_VERSION' ) && WOOCOMMERCE_VERSION ) {
					return version_compare( WOOCOMMERCE_VERSION, $version, '>=' );
				} else {
					return false;
				}
			}

			public static function wp_version_gte( $version ) {
				$wp_version = get_bloginfo( 'version' );

				// Treat release candidate strings
				$wp_version = preg_replace( '/-RC.+/i', '', $wp_version );

				if ( $wp_version ) {
					return version_compare( $wp_version, $version, '>=' );
				}

				return false;
			}

			public static function php_version_gte( $version ) {
				return version_compare( PHP_VERSION, $version, '>=' );
			}
		}
	}
}

