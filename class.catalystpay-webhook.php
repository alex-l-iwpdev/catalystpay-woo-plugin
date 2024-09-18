<?php
require_once 'vendor/autoload.php';

use CatalystPay\CatalystPaySDK;
use CatalystPay\Notification;


class WebhookController extends WP_REST_Controller {
	public function register_routes() {
		register_rest_route( 'catalystpay', '/webhook', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'order_status' ],
				'args'                => [],
				'permission_callback' => '__return_true',
				'show_in_index'       => false,
			],
		] );
	}

	public function order_status( WP_REST_Request $request ) {
		global $wpdb;

		$headers            = $this->get_headers();
		$settings           = get_option( 'woocommerce_catalystpay_gateway_settings' );
		$catalystpay_config = include __DIR__ . '/config.php';

		$payload      = $request->get_body();
		$notification = new Notification( $payload );
		//Get decrypted message from webhook
		$webhook_info = $notification->getDecryptedMessage( $payload );

		if ( false === $webhook_info ) {
			error_log(
				'Webhook payload decryption problem: ' . json_encode( [
					'iv'       => $headers['X-Initialization-Vector'],
					'auth_tag' => $headers['X-Authentication-Tag'],
					'payload'  => $payload,
				] )
			);

			return new WP_REST_Response( [] );
		}

		$data = json_decode( $webhook_info, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			error_log( 'Webhook payload is not valid json string' );

			return new WP_REST_Response( [] );
		}

		if ( 'PAYMENT' === $data['type'] ) {
			$res = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}catalystpay_order WHERE transaction_token=%s", $data['payload']['ndc'] ),
				OBJECT
			);

			$transaction_id = $data['payload']['referencedId'] ?? $data['payload']['id'];

			if ( ! empty( $res ) ) {
				$catalystpay_order_info = json_decode( json_encode( $res[0] ), true );

				$wpdb->update(
					$wpdb->prefix . 'catalystpay_order',
					[ 'transaction_id' => $transaction_id ],
					[ 'order_id' => $catalystpay_order_info['order_id'] ],
				);

				$registrationId = $data['payload']['registrationId'];

				if ( ! empty( $registrationId ) ) {
					$registration_table_name = $wpdb->prefix . 'catalystpay_registration';

					$subscription_data = [
						'user_id'            => $post_id,
						'registration_token' => $registrationId,
					];
					$wpdb->insert( $registration_table_name, $subscription_data );
				}
			}

			if ( empty( $catalystpay_order_info ) ) {
				$res = $wpdb->get_results(
					$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}catalystpay_order WHERE transaction_id=%s", $transaction_id ),
					OBJECT
				);
				! empty( $res ) && $catalystpay_order_info = json_decode( json_encode( $res[0] ), true );

				if ( empty( $catalystpay_order_info ) ) {
					error_log( 'CatalystPay transaction: ' . $transaction_id . ' not found' );

					return new WP_REST_Response( [], 404 );
				}
			}

			$order = wc_get_order( $catalystpay_order_info['order_id'] );

			$paymentType = $data['payload']['paymentType'] ?? null;
			$log_txn_id  = $transaction_id;
			if ( in_array( $data['payload']['result']['code'], $catalystpay_config['success_payment_pattern'], true ) ) {
				switch ( $paymentType ) {
					case 'PA':
						$status = 'on-hold';
						break;
					case 'RF':
						! empty( $data['payload']['id'] ) && $log_txn_id = $data['payload']['id'];
						$status = 'refunded';
						break;
					case 'RV':
						! empty( $data['payload']['id'] ) && $log_txn_id = $data['payload']['id'];
						$status = 'cancelled';
						break;
					default:
						$status = 'processing';
				}

				$order->update_status( $status, 'Txn: ' . esc_html( $log_txn_id ) . ' - ' );

				return new WP_REST_Response( [] );
			}

			if ( in_array( $data['payload']['result']['code'], $catalystpay_config['rejected_payment_pattern'], true ) ) {
				switch ( $paymentType ) {
					case 'RF':
						! empty( $data['payload']['id'] ) && $log_txn_id = $data['payload']['id'];
						$status = 'refunded';
						break;
					case 'RV':
						! empty( $data['payload']['id'] ) && $log_txn_id = $data['payload']['id'];
						$status = 'cancelled';
						break;
					default:
						$status = 'failed';
				}

				$order->update_status( $status, 'Txn: ' . esc_html( $log_txn_id ) . ' - ' );

				return new WP_REST_Response( [] );
			}

			if ( in_array( $data['payload']['result']['code'], $catalystpay_config['chargeback_payment_pattern'], true ) ) {
				$order->update_status( 'cancelled', 'Txn: ' . esc_html( $data['payload']['id'] ?? $log_txn_id ) . ' - ' );
			}
		}

		return new WP_REST_Response( [] );
	}

	private function get_headers() {
		foreach ( $_SERVER as $name => $value ) {
			/* RFC2616 (HTTP/1.1) defines header fields as case-insensitive entities. */
			if ( strtolower( substr( $name, 0, 5 ) ) == 'http_' ) {
				$headers[ str_replace( ' ', '-', ucwords( strtolower( str_replace( '_', ' ', substr( $name, 5 ) ) ) ) ) ] = $value;
			}
		}

		return $headers;
	}
}

add_action( 'rest_api_init', function () {
	$controller = new WebhookController();
	$controller->register_routes();
} );


function create_subscription_table() {
	$ceate_subscription_table = get_option( 'ctpy_created_subscription_table', false );

	if ( ! $ceate_subscription_table ) {
		return;
	}

	global $wpdb;

	// Table 1
	$table1_name     = $wpdb->prefix . 'catalystpay_order';
	$charset_collate = $wpdb->get_charset_collate();

	$sql_table1 = "CREATE TABLE IF NOT EXISTS $sql_table1 (
        order_id INT(11) NOT NULL,
        transaction_token VARCHAR(255) NOT NULL,
        transaction_id VARCHAR(64) NULL,
        PRIMARY KEY (order_id)
    ) $charset_collate;";

	// Table 2
	$table2_name = $wpdb->prefix . 'catalystpay_registration';

	$sql_table2 = "CREATE TABLE IF NOT EXISTS $table2_name (
        id INT(11) NOT NULL AUTO_INCREMENT,
        user_id INT(11) NOT NULL,
        registration_token VARCHAR(255) NOT NULL,
        PRIMARY KEY (id)
    ) $charset_collate;";

	// Subscriptions table
	$subscriptions_table_name = $wpdb->prefix . 'subscriptions';

	$sql_subscriptions = "CREATE TABLE IF NOT EXISTS $subscriptions_table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        product_id mediumint(9) NOT NULL,
        subscription_status int NOT NULL,
        subscription_price varchar(255) NOT NULL,
        subscription_duration int NOT NULL,
        subscription_cycle int NOT NULL,
        subscription_frequency varchar(255) NOT NULL,
        trial_subscription_price varchar(255),
        trial_subscription_duration int,
        trial_subscription_cycle int,
        trial_subscription_frequency varchar(255),
        PRIMARY KEY (id)
    ) $charset_collate;";

	require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

	// Create Table 1
	dbDelta( $sql_table1 );

	// Create Table 2
	dbDelta( $sql_table2 );

	// Create Subscriptions Table
	dbDelta( $sql_subscriptions );

	add_option( 'ctpy_created_subscription_table', true, '', true );
}

add_action( 'after_setup_theme', 'create_subscription_table' );


function create_additional_tables() {

	$create_additional_tables = get_option( 'ctpy_created_additional_tables', false );

	if ( ! $create_additional_tables ) {
		return;
	}

	global $wpdb;
	$charset_collate = $wpdb->get_charset_collate();

	// Table for storing payment logs
	$table_payment_logs = $wpdb->prefix . 'catalystpay_payment_logs';
	$sql_payment_logs   = "CREATE TABLE IF NOT EXISTS $table_payment_logs (
        log_id INT(11) NOT NULL AUTO_INCREMENT,
        order_id INT(11) NOT NULL,
        payment_status VARCHAR(50) NOT NULL,
        payment_date DATETIME NOT NULL,
        amount DECIMAL(10, 2) NOT NULL,
        PRIMARY KEY (log_id),
        KEY order_id (order_id)
    ) $charset_collate;";

	// Table for storing user subscriptions
	$table_user_subscriptions = $wpdb->prefix . 'catalystpay_user_subscriptions';
	$sql_user_subscriptions   = "CREATE TABLE IF NOT EXISTS $table_user_subscriptions (
        subscription_id INT(11) NOT NULL AUTO_INCREMENT,
        user_id INT(11) NOT NULL,
        product_id INT(11) NOT NULL,
        start_date DATETIME NOT NULL,
        end_date DATETIME NOT NULL,
        status VARCHAR(50) NOT NULL,
        PRIMARY KEY (subscription_id),
        KEY user_id (user_id),
        KEY product_id (product_id)
    ) $charset_collate;";

	require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
	dbDelta( $sql_payment_logs );
	dbDelta( $sql_user_subscriptions );

	add_option( 'ctpy_created_additional_tables', true, '', true );
}

add_action( 'after_setup_theme', 'create_additional_tables' );


// Add custom subscription fields to WooCommerce product data tab
add_action( 'woocommerce_product_options_general_product_data', 'add_custom_subscription_fields' );

function add_custom_subscription_fields() {
	global $post;
	$product_id = $post->ID;

	// Retrieve existing subscription values
	$subscription_checkbox        = get_post_meta( $product_id, '_subscription_checkbox', true );
	$subscription_price           = get_post_meta( $product_id, 'subscription_price', true );
	$subscription_duration        = get_post_meta( $product_id, 'subscription_duration', true );
	$subscription_cycle           = get_post_meta( $product_id, 'subscription_cycle', true );
	$subscription_frequency       = get_post_meta( $product_id, 'subscription_frequency', true );
	$trial_subscription_price     = get_post_meta( $product_id, 'trial_subscription_price', true );
	$trial_subscription_duration  = get_post_meta( $product_id, 'trial_subscription_duration', true );
	$trial_subscription_cycle     = get_post_meta( $product_id, 'trial_subscription_cycle', true );
	$trial_subscription_frequency = get_post_meta( $product_id, 'trial_subscription_frequency', true );

	?>
	<div class="options_group">
		<h3><?php esc_html_e( 'Subscription Management', 'textdomain' ); ?></h3>

		<!-- Subscription Checkbox -->
		<p class="form-field">
			<label for="_subscription_checkbox"><?php esc_html_e( 'Enable Subscription', 'woocommerce' ); ?></label>
			<input type="checkbox" class="checkbox" <?php checked( $subscription_checkbox, 'yes' ); ?>
				   id="_subscription_checkbox" name="_subscription_checkbox" value="yes">
			<?php esc_html_e( 'Check this box to enable subscription for this product.', 'woocommerce' ); ?>
		</p>

		<div id="subscription_fields"
			 style="<?php echo ( $subscription_checkbox === 'yes' ) ? '' : 'display: none;'; ?>">

			<?php
			// Retrieve existing subscription values
			$subscription_price = get_post_meta( $product_id, '_sale_price', true );
			$regular_price      = get_post_meta( $product_id, '_regular_price', true );

			// If subscription price is not set, use regular price
			$display_price = ! empty( $subscription_price ) ? $subscription_price : $regular_price;
			?>
			<!-- Recurring Profile -->
			<p class="form-field">
				<!-- <label for="subscription_price"><?php esc_html_e( 'Price', 'textdomain' ); ?>:</label> -->
				<input type="hidden" id="subscription_price" name="subscription_price"
					   value="<?php echo esc_attr( $display_price ); ?>"
					   placeholder="<?php esc_attr_e( 'Price', 'textdomain' ); ?>"
					   placeholder="<?php esc_attr_e( 'Price', 'textdomain' ); ?>" required>
			</p>
			<p class="form-field">
				<label for="subscription_duration"><?php esc_html_e( 'Duration', 'textdomain' ); ?>:</label>
				<input type="number" id="subscription_duration" name="subscription_duration"
					   value="<?php echo esc_attr( $subscription_duration ); ?>"
					   placeholder="<?php esc_attr_e( 'Duration', 'textdomain' ); ?>" required>
			</p>
			<p class="form-field">
				<label for="subscription_cycle"><?php esc_html_e( 'Cycle', 'textdomain' ); ?>:</label>
				<input type="number" id="subscription_cycle" name="subscription_cycle"
					   value="<?php echo esc_attr( $subscription_cycle ); ?>"
					   placeholder="<?php esc_attr_e( 'Cycle', 'textdomain' ); ?>">
			</p>
			<p class="form-field">
				<label for="subscription_frequency"><?php esc_html_e( 'Frequency', 'textdomain' ); ?>:</label>
				<select id="subscription_frequency" name="subscription_frequency" required>
					<option value=""><?php esc_html_e( 'Select Frequency', 'textdomain' ); ?></option>
					<option value="day" <?php selected( $subscription_frequency, 'day' ); ?>><?php esc_html_e( 'Day', 'textdomain' ); ?></option>
					<option value="week" <?php selected( $trial_subscription_frequency, 'week' ); ?>><?php esc_html_e( 'Week', 'textdomain' ); ?></option>
					<option value="month" <?php selected( $subscription_frequency, 'month' ); ?>><?php esc_html_e( 'Month', 'textdomain' ); ?></option>
					<option value="year" <?php selected( $subscription_frequency, 'year' ); ?>><?php esc_html_e( 'Year', 'textdomain' ); ?></option>
				</select>
			</p>


			<!-- Trial Profile -->
			<h4><?php esc_html_e( 'Add New Trial Profile', 'textdomain' ); ?></h4>
			<p class="form-field">
				<label for="trial_subscription_price"><?php esc_html_e( 'Trial Price', 'textdomain' ); ?>:</label>
				<input type="text" id="trial_subscription_price" name="trial_subscription_price"
					   value="<?php echo esc_attr( $trial_subscription_price ); ?>"
					   placeholder="<?php esc_attr_e( 'Price', 'textdomain' ); ?>" required>
			</p>
			<p class="form-field">
				<label for="trial_subscription_duration"><?php esc_html_e( 'Trial Duration', 'textdomain' ); ?>:</label>
				<input type="number" id="trial_subscription_duration" name="trial_subscription_duration"
					   value="<?php echo esc_attr( $trial_subscription_duration ); ?>"
					   placeholder="<?php esc_attr_e( 'Duration', 'textdomain' ); ?>" required>
			</p>
			<p class="form-field">
				<label for="trial_subscription_cycle"><?php esc_html_e( 'Trial Cycle', 'textdomain' ); ?>:</label>
				<input type="number" id="trial_subscription_cycle" name="trial_subscription_cycle"
					   value="<?php echo esc_attr( $trial_subscription_cycle ); ?>"
					   placeholder="<?php esc_attr_e( 'Cycle', 'textdomain' ); ?>">
			</p>
			<p class="form-field">
				<label for="trial_subscription_frequency"><?php esc_html_e( 'Trial Frequency', 'textdomain' ); ?>
					:</label>
				<select id="trial_subscription_frequency" name="trial_subscription_frequency" required>
					<option value=""><?php esc_html_e( 'Select Frequency', 'textdomain' ); ?></option>
					<option value="day" <?php selected( $trial_subscription_frequency, 'day' ); ?>><?php esc_html_e( 'Day', 'textdomain' ); ?></option>
					<option value="week" <?php selected( $trial_subscription_frequency, 'week' ); ?>><?php esc_html_e( 'Week', 'textdomain' ); ?></option>
					<option value="month" <?php selected( $trial_subscription_frequency, 'month' ); ?>><?php esc_html_e( 'Month', 'textdomain' ); ?></option>
					<option value="year" <?php selected( $trial_subscription_frequency, 'year' ); ?>><?php esc_html_e( 'Year', 'textdomain' ); ?></option>
				</select>
			</p>

		</div>
	</div>
	<?php
}

// Save custom subscription fields when the product is saved
add_action( 'woocommerce_process_product_meta', 'save_custom_subscription_fields' );

function save_custom_subscription_fields( $post_id ) {
	$subscription_checkbox = isset( $_POST['_subscription_checkbox'] ) ? 'yes' : 'no';
	update_post_meta( $post_id, '_subscription_checkbox', $subscription_checkbox );

	if ( $subscription_checkbox === 'yes' ) {
		$subscription_price           = isset( $_POST['subscription_price'] ) ? sanitize_text_field( $_POST['subscription_price'] ) : '';
		$subscription_duration        = isset( $_POST['subscription_duration'] ) ? absint( $_POST['subscription_duration'] ) : 0;
		$subscription_cycle           = isset( $_POST['subscription_cycle'] ) ? absint( $_POST['subscription_cycle'] ) : 0;
		$subscription_frequency       = isset( $_POST['subscription_frequency'] ) ? sanitize_text_field( $_POST['subscription_frequency'] ) : '';
		$trial_subscription_price     = isset( $_POST['trial_subscription_price'] ) ? sanitize_text_field( $_POST['trial_subscription_price'] ) : '';
		$trial_subscription_duration  = isset( $_POST['trial_subscription_duration'] ) ? absint( $_POST['trial_subscription_duration'] ) : 0;
		$trial_subscription_cycle     = isset( $_POST['trial_subscription_cycle'] ) ? absint( $_POST['trial_subscription_cycle'] ) : 0;
		$trial_subscription_frequency = isset( $_POST['trial_subscription_frequency'] ) ? sanitize_text_field( $_POST['trial_subscription_frequency'] ) : '';

		global $wpdb;
		$table_name = $wpdb->prefix . 'subscriptions';

		$subscription_data = [
			'product_id'                   => $post_id,
			'subscription_status'          => 1,
			'subscription_price'           => $subscription_price,
			'subscription_duration'        => $subscription_duration,
			'subscription_cycle'           => $subscription_cycle,
			'subscription_frequency'       => $subscription_frequency,
			'trial_subscription_price'     => $trial_subscription_price,
			'trial_subscription_duration'  => $trial_subscription_duration,
			'trial_subscription_cycle'     => $trial_subscription_cycle,
			'trial_subscription_frequency' => $trial_subscription_frequency,
		];

		// Check if a subscription entry already exists for this product
		$existing_subscription = $wpdb->get_row(
			$wpdb->prepare( "SELECT id FROM $table_name WHERE product_id = %d", $post_id )
		);

		if ( $existing_subscription ) {
			// Update existing subscription
			$wpdb->update(
				$table_name,
				$subscription_data,
				[ 'id' => $existing_subscription->id ]
			);
		} else {
			// Insert new subscription
			$wpdb->insert( $table_name, $subscription_data );
		}
	} else {
		global $wpdb;
		$table_name = $wpdb->prefix . 'subscriptions';

		// Delete subscription entry from the table
		$wpdb->delete(
			$table_name,
			[ 'product_id' => $post_id ]
		);
	}
}

// Display subscription information on the product page
// add_action('woocommerce_single_product_summary', 'display_subscription_checkbox_value', 25);
// function display_subscription_checkbox_value() {
//     global $post;
//     $subscription_checkbox = get_post_meta($post->ID, '_subscription_checkbox', true);
//     if ($subscription_checkbox === 'yes') {
//         echo '<p>This product is available for subscription.</p>';
//     }
// }

// Add JavaScript to handle showing/hiding subscription fields
add_action( 'admin_footer', 'subscription_fields_js' );
function subscription_fields_js() {
	?>
	<script type="text/javascript">
		jQuery( document ).ready( function( $ ) {
			function toggleSubscriptionFields() {
				if ( $( '#_subscription_checkbox' ).is( ':checked' ) ) {
					$( '#subscription_fields' ).show();
					$( '#subscription_fields input, #subscription_fields select' ).attr( 'required', 'required' );
				} else {
					$( '#subscription_fields' ).hide();
					$( '#subscription_fields input, #subscription_fields select' ).removeAttr( 'required' );
				}
			}

			$( '#_subscription_checkbox' ).change( function() {
				toggleSubscriptionFields();
			} );

			toggleSubscriptionFields(); // Initial check on page load
		} );
	</script>
	<?php
}

// Add subscription checkbox to product
// add_action('woocommerce_product_options_general_product_data', 'add_subscription_checkbox_to_product');
// function add_subscription_checkbox_to_product() {
//     woocommerce_wp_checkbox(
//         array(
//             'id'            => '_subscription_checkbox',
//             'label'         => __('Enable Subscription', 'woocommerce'),
//             'description'   => __('Check this box to enable subscription for this product.', 'woocommerce'),
//             'desc_tip'      => true,
//         )
//     );
// }

// Save subscription checkbox value
add_action( 'woocommerce_process_product_meta', 'save_subscription_data' );
function save_subscription_data( $post_id ) {
	// Save the checkbox value
	$subscription_checkbox = isset( $_POST['_subscription_checkbox'] ) ? 'yes' : 'no';
	update_post_meta( $post_id, '_subscription_checkbox', $subscription_checkbox );

	// Only proceed if the subscription is enabled
	if ( $subscription_checkbox === 'yes' ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'subscriptions';

		$subscription_data = [
			'product_id'                   => $post_id,
			'subscription_price'           => sanitize_text_field( $_POST['subscription_price'] ),
			'subscription_duration'        => absint( $_POST['subscription_duration'] ),
			'subscription_cycle'           => absint( $_POST['subscription_cycle'] ),
			'subscription_frequency'       => sanitize_text_field( $_POST['subscription_frequency'] ),
			'trial_subscription_price'     => sanitize_text_field( $_POST['trial_subscription_price'] ),
			'trial_subscription_duration'  => absint( $_POST['trial_subscription_duration'] ),
			'trial_subscription_cycle'     => absint( $_POST['trial_subscription_cycle'] ),
			'trial_subscription_frequency' => sanitize_text_field( $_POST['trial_subscription_frequency'] ),
		];

		// Check if a subscription entry already exists for this product
		$existing_subscription = $wpdb->get_row(
			$wpdb->prepare( "SELECT id FROM $table_name WHERE product_id = %d", $post_id )
		);

		if ( $existing_subscription ) {
			// Update existing subscription
			$wpdb->update(
				$table_name,
				$subscription_data,
				[ 'id' => $existing_subscription->id ]
			);
		} else {
			// Insert new subscription
			$wpdb->insert( $table_name, $subscription_data );
		}
	}
}

// Display subscription information on the product page
add_action( 'woocommerce_single_product_summary', 'display_subscription_checkbox_value', 25 );
function display_subscription_checkbox_value() {
	global $post;
	$subscription_checkbox = get_post_meta( $post->ID, '_subscription_checkbox', true );
	if ( $subscription_checkbox === 'yes' ) {
		echo '<p>This product is available for subscription.</p>';
	}
}


add_action( 'woocommerce_after_shop_loop_item', 'display_subscribe_or_add_to_cart_button', 5 );
function display_subscribe_or_add_to_cart_button() {
	global $product, $wpdb;

	// Check if the product has a subscription entry in the subscriptions table
	$table_name         = $wpdb->prefix . 'subscriptions';
	$subscription_entry = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_name WHERE product_id = %d", $product->get_id() ) );

	if ( $subscription_entry ) {
		// If subscription price exists, display the Subscribe button
		$text = esc_html__( 'Subscribe', 'woocommerce' );
	}
}

function update_subscription_data( $product_id, $subscription_data ) {
	global $wpdb;
	$table_name = $wpdb->prefix . 'subscriptions';

	$data = [
		'subscription_price'           => sanitize_text_field( $subscription_data['subscription_price'] ),
		'subscription_duration'        => absint( $subscription_data['subscription_duration'] ),
		'subscription_cycle'           => absint( $subscription_data['subscription_cycle'] ),
		'subscription_frequency'       => sanitize_text_field( $subscription_data['subscription_frequency'] ),
		'trial_subscription_price'     => sanitize_text_field( $subscription_data['trial_subscription_price'] ),
		'trial_subscription_duration'  => absint( $subscription_data['trial_subscription_duration'] ),
		'trial_subscription_cycle'     => absint( $subscription_data['trial_subscription_cycle'] ),
		'trial_subscription_frequency' => sanitize_text_field( $subscription_data['trial_subscription_frequency'] ),
	];

	$existing_subscription = $wpdb->get_row(
		$wpdb->prepare( "SELECT id FROM $table_name WHERE product_id = %d", $product_id )
	);

	if ( $existing_subscription ) {
		$wpdb->update(
			$table_name,
			$data,
			[ 'id' => $existing_subscription->id ]
		);
	} else {
		$wpdb->insert( $table_name, array_merge( $data, [ 'product_id' => $product_id ] ) );
	}
}


function is_product_in_subscription_table( $product_id ) {
	global $wpdb;

	$table_name = $wpdb->prefix . 'subscriptions';
	$result     = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_name WHERE product_id = %d", $product_id ) );

	return $result ? true : false;
}


add_filter( 'woocommerce_order_button_text', 'customize_checkout_button_text', 10, 1 );
function customize_checkout_button_text( $button_text ) {

	$cart                    = WC()->cart->get_cart();
	$is_subscription_product = false;
	foreach ( $cart as $cart_item_key => $cart_item ) {
		if ( is_product_in_subscription_table( $cart_item['product_id'] ) ) {
			$is_subscription_product = true;
			break;
		}
	}
	if ( $is_subscription_product ) {
		$button_text = 'Subscribe Now';
	} else {
		$button_text = 'Place Order';
	}

	return $button_text;
}

// // Step 3: Hook into the WooCommerce checkout page
// add_action( 'woocommerce_review_order_before_submit', 'customize_checkout_button' );
// function customize_checkout_button() {
//     // Output any additional content before the order button
//     // This function can be used for additional customization if needed
// }

function customize_shop_page_buttons_text() {
	global $product;

	// Check if the product is a subscription product and exists in the subscription table
	if ( is_product_in_subscription_table( $product->get_id() ) ) {
		return 'Subscribe';
	} else {
		return 'ADD TO CART';
	}
}

// Hook into the shop page to customize the button text
add_filter( 'woocommerce_product_add_to_cart_text', 'customize_shop_page_buttons_text', 10, 2 );


// Add new option to filter orders by "Subscription Orders"
function add_subscription_orders_filter() {

	$selected = isset( $_GET['order_status'] ) && $_GET['order_status'] === 'subscription_orders' ? 'selected' : '';
	echo '<select name="order_status" class="w-30">';
	echo '<option value="subscription_orders" ' . $selected . '>Subscription Orders</option>';
	echo '</select>';

}

// Filter orders by subscription orders
function filter_orders_by_subscription_orders( $query ) {
	global $pagenow, $typenow;

	if ( $pagenow == 'edit.php' && $typenow == 'shop_order' && isset( $_GET['order_status'] ) && $_GET['order_status'] === 'subscription_orders' ) {
		// Get product IDs from subscriptions table
		global $wpdb;
		$table_name  = $wpdb->prefix . 'subscriptions';
		$product_ids = $wpdb->get_col( "SELECT product_id FROM $table_name" );

		if ( ! empty( $product_ids ) ) {
			$meta_query = [];
			foreach ( $product_ids as $product_id ) {
				$meta_query[] = [
					'key'     => '_product_id',
					'value'   => $product_id,
					'compare' => '=',
				];
			}
			$query->set( 'meta_query', $meta_query );
		} else {
			// No product IDs found in subscriptions table, set to return no orders
			$query->set( 'post__in', [ 0 ] );
		}
	}
}

// Hook into WooCommerce admin orders page to add filter and filter orders
add_action( 'restrict_manage_posts', 'add_subscription_orders_filter' );
add_action( 'pre_get_posts', 'filter_orders_by_subscription_orders' );


function add_subscription_menu() {
	add_menu_page(
		__( 'Subscription', 'textdomain' ), // Page title
		__( 'Subscription', 'textdomain' ), // Menu title
		'manage_options', // Capability required to access the menu
		'subscription_menu', // Menu slug
		'subscription_menu_callback', // Callback function to display content
		'dashicons-email', // Icon for the menu item
		30 // Position in the admin menu
	);
}

add_action( 'admin_menu', 'add_subscription_menu' );


function enqueue_subscription_scripts() {
	?>
	<script type="text/javascript">
		jQuery( document ).ready( function( $ ) {
			$( '.cancel-subscription' ).on( 'click', function( e ) {
				e.preventDefault();

				if ( confirm( 'Are you sure you want to cancel this subscription?' ) ) {
					var orderId = $( this ).data( 'order-id' );

					$.ajax( {
						type: 'post',
						url: ajaxurl,
						data: {
							action: 'cancel_subscription',
							order_id: orderId,
							nonce: '<?php echo wp_create_nonce( "subscription_nonce" ); ?>'
						},
						success: function( response ) {
							if ( response.success ) {
								alert( 'Subscription cancelled successfully.' );
								location.reload();
							} else {
								alert( 'Failed to cancel subscription.' );
							}
						}
					} );
				}
			} );
		} );
	</script>
	<?php
}

add_action( 'admin_footer', 'enqueue_subscription_scripts' );

function cancel_subscription() {
	check_ajax_referer( 'subscription_nonce', 'nonce' );

	if ( isset( $_POST['order_id'] ) ) {
		global $wpdb;
		$order_id = intval( $_POST['order_id'] );

		// Update the order status to 'cancelled' or any other status you prefer
		$result = $wpdb->update(
			'wp_wc_orders',
			[ 'status' => 'wc-deactive' ],
			[ 'id' => $order_id ],
			[ '%s' ],
			[ '%d' ]
		);

		if ( $result !== false ) {
			wp_send_json_success();
		} else {
			wp_send_json_error();
		}
	}

	wp_send_json_error();
}

add_action( 'wp_ajax_cancel_subscription', 'cancel_subscription' );

// Callback function to display content for the custom menu page
function subscription_menu_callback() {
	global $wpdb;
	$table_name            = 'wp_wc_order_product_lookup';
	$get_sub_product_table = 'wp_subscriptions';
	$product_get           = $wpdb->prefix . 'wc_product_meta_lookup';
	$customer_get          = $wpdb->prefix . 'wc_customer_lookup';


	// Check if subscription filter is applied
	$is_subscription_filter = isset( $_GET['order_type'] ) && $_GET['order_type'] === 'subscription';

	$query_sub                = "SELECT product_id FROM $get_sub_product_table";
	$subscription_product_ids = $wpdb->get_col( $query_sub );

	$product_id_list = implode( ',', array_map( 'intval', $subscription_product_ids ) );

	$query = "SELECT * FROM $table_name 
    LEFT JOIN wp_wc_orders 
    ON $table_name.order_id = wp_wc_orders.id 
    LEFT JOIN  $product_get
    ON $table_name.product_id = $product_get.product_id
    LEFT JOIN  $customer_get
    ON $table_name.customer_id = $customer_get.customer_id



    WHERE $table_name.product_id IN ($product_id_list)
    ORDER BY $table_name.order_id DESC
    ";


	$orders = $wpdb->get_results( $query );

	// // Display filter dropdown
	// echo '<div class="wrap">';
	// echo '<h1 class="wp-heading-inline">Orders</h1>';
	// echo '<hr class="wp-header-end">';
	// echo '<form method="get">';
	// echo '<input type="hidden" name="page" value="subscription_menu">';
	// echo '<label for="order_type">Order Type:</label>';
	// echo '<select id="order_type" name="order_type">';
	// echo '<option value="">All Orders</option>';
	// echo '<option value="subscription"' . selected($is_subscription_filter, true, false) . '>Subscription Orders</option>';
	// echo '</select>';
	// echo '<input type="submit" class="button" value="Filter">';
	// echo '</form>';

	if ( ! empty( $orders ) ) {
		echo '<h2>Orders Ordered by Product</h2>';
		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead>';
		echo '<tr><th>Product Name</th>
    
 
        <th>Order Price</th>
        <th>Customer Name</th>
        <th>Order Date</th>
        <th>Edit</th>
        <th>Cancel Subscription</th>
        </tr>';
		echo '</thead>';
		echo '<tbody>';
		foreach ( $orders as $order ) {

			$order_time        = strtotime( $order->date_created_gmt );
			$current_time      = current_time( 'timestamp' );
			$time_diff_seconds = $current_time - $order_time;


			if ( $time_diff_seconds <= 24 * 60 * 60 ) {
				$time_ago = human_time_diff( $order_time, $current_time ) . ' ago';
			} else {
				// For orders older than 24 hours, display the date
				$time_ago = date_i18n( get_option( 'date_format' ), $order_time );
			}
			echo '<tr>';
			echo '<td>' . $order->sku . '</td>';
			// echo '<td>' . $order->order_id  . '</td>';
			// echo '<td>' . $order->product_id . '</td>';
			echo '<td>' . $order->total_amount . '</td>';
			echo '<td>' . $order->username . '</td>';
			echo '<td>' . $time_ago . '</td>';

			echo '<td><a href="admin.php?page=wc-orders&action=edit&id=' . $order->order_id . '" class="button">Edit</a></td>';
			if ( $order->status == 'wc-active' ) {
				echo '<td><a href="#" data-order-id="' . $order->order_id . '" class="button cancel-subscription">Cancel</a></td>';
			} else {
				echo '<td>Deactive</td>';
			}


			echo '</tr>';
		}
		echo '</tbody>';
		echo '</table>';
	} else {
		echo '<p>No orders found.</p>';
	}
	echo '</div>';
}


// Hook into the 'views_edit-shop_order' action
add_action( 'views_edit-shop_order', 'add_subscription_order_status_option' );

// Callback function to add subscription order status option
function add_subscription_order_status_option( $views ) {
	global $wpdb;

	// Check if the WooCommerce plugin is active
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}

	$subscription_count = 0;

	// Count subscription orders
	$subscription_orders = wc_get_orders( [
		'limit'        => - 1,
		'meta_key'     => '_product_id',
		'meta_compare' => 'EXISTS',
	] );

	if ( $subscription_orders ) {
		$subscription_count = count( $subscription_orders );
	}

	// Add the subscription order status option
	$views['subscription'] = sprintf(
		'<a href="%s"%s>%s <span class="count">(%s)</span></a>',
		admin_url( 'edit.php?post_type=shop_order&order_status=subscription' ),
		isset( $_GET['order_status'] ) && $_GET['order_status'] === 'subscription' ? ' class="current"' : '',
		__( 'Subscription', 'textdomain' ),
		$subscription_count
	);

	return $views;
}


// Register new order statuses
function register_custom_order_statuses() {
	register_post_status( 'wc-active', [
		'label'                     => _x( 'Active', 'Order status', 'text_domain' ),
		'public'                    => true,
		'exclude_from_search'       => false,
		'show_in_admin_all_list'    => true,
		'show_in_admin_status_list' => true,
		'label_count'               => _n_noop( 'Active (%s)', 'Active (%s)', 'text_domain' ),
	] );

	register_post_status( 'wc-deactive', [
		'label'                     => _x( 'Deactive', 'Order status', 'text_domain' ),
		'public'                    => true,
		'exclude_from_search'       => false,
		'show_in_admin_all_list'    => true,
		'show_in_admin_status_list' => true,
		'label_count'               => _n_noop( 'Deactive (%s)', 'Deactive (%s)', 'text_domain' ),
	] );
}

add_action( 'init', 'register_custom_order_statuses' );

// Add custom order statuses to WooCommerce
function add_custom_order_statuses( $order_statuses ) {
	$new_order_statuses = [];

	// Add new order statuses after processing status
	foreach ( $order_statuses as $key => $status ) {
		$new_order_statuses[ $key ] = $status;

		if ( 'wc-processing' === $key ) {
			$new_order_statuses['wc-active']   = _x( 'Active', 'Order status', 'text_domain' );
			$new_order_statuses['wc-deactive'] = _x( 'Deactive', 'Order status', 'text_domain' );
		}
	}

	return $new_order_statuses;
}

add_filter( 'wc_order_statuses', 'add_custom_order_statuses' );

function custom_add_cancel_button_to_my_orders( $actions, $order ) {
	if ( $order->has_status( 'active' ) ) {
		$cancel_url        = wp_nonce_url(
			add_query_arg(
				'cancel_order',
				$order->get_id(),
				wc_get_endpoint_url( 'view-order' )
			),
			'woocommerce-cancel_order'
		);
		$actions['cancel'] = [
			'url'  => $cancel_url,
			'name' => __( 'Cancel', 'woocommerce' ),
		];
	}

	return $actions;
}

add_filter( 'woocommerce_my_account_my_orders_actions', 'custom_add_cancel_button_to_my_orders', 10, 2 );

function custom_enqueue_styles() {
	if ( is_account_page() ) {
		?>
		<style>
			.woocommerce-account .button.cancel {
				background-color: #a00;
				color: #fff;
				border-color: #900;
			}

			.woocommerce-account .button.cancel:hover {
				background-color: #900;
				border-color: #800;
			}
		</style>
		<?php
	}
}

add_action( 'wp_head', 'custom_enqueue_styles' );

function custom_handle_order_cancellation() {
	if ( isset( $_GET['cancel_order'] ) && is_user_logged_in() && wp_verify_nonce( $_REQUEST['_wpnonce'], 'woocommerce-cancel_order' ) ) {
		$order_id = intval( $_GET['cancel_order'] );
		$order    = wc_get_order( $order_id );

		if ( $order && $order->get_status() == 'active' && $order->get_user_id() === get_current_user_id() ) {
			$order->update_status( 'cancelled', __( 'Order cancelled by customer', 'woocommerce' ) );
			wc_add_notice( __( 'Your order has been cancelled.', 'woocommerce' ), 'notice' );
			wp_redirect( wc_get_account_endpoint_url( 'orders' ) );
			exit;
		}
	}
}

add_action( 'template_redirect', 'custom_handle_order_cancellation' );


add_action( 'admin_enqueue_scripts', 'enqueue_custom_script' );

function enqueue_custom_script() {
	wp_enqueue_script( 'custom-script', plugin_dir_url( __FILE__ ) . 'assets/js/script.js', [], '1.0', true );
}

// Add Capture button to order list for Pending Payment orders
add_action( 'admin_enqueue_scripts', 'enqueue_capture_button_script' );
function enqueue_capture_button_script() {
	wp_enqueue_script( 'capture-button-script', get_template_directory_uri() . '/js/script.js', [], '1.0', true );
}

add_action( 'wp_ajax_capture_complete_order_action', 'capture_complete_order_callback' );

function capture_complete_order_callback() {
	$order_id = isset( $_POST['order_id'] ) ? intval( $_POST['order_id'] ) : 0;
	$order    = wc_get_order( $order_id );
	global $wpdb;
	$sel        = "SELECT * FROM `{$wpdb->prefix}catalystpay_order` WHERE `order_id` = $order_id";
	$order_data = $wpdb->get_row( $sel, ARRAY_A );

	$sel1      = "SELECT * FROM `{$wpdb->prefix}wc_orders` WHERE `id` = $order_id";
	$orderdata = $wpdb->get_row( $sel1, ARRAY_A );

	if ( $order && $order->get_status() === 'pending' ) {
		$order->payment_complete();
		$order->update_status( 'completed' ); // Update order status to completed

		$settings       = get_option( 'woocommerce_catalystpay_gateway_settings' );
		$CatalystPaySDK = new CatalystPaySDK(
			$settings['api_token'],
			$settings['api_channel'],
			$settings['test_mode'] ? false : true,
		);

		$result = json_decode( $CatalystPaySDK->getPaymentStatus( $order_data['transaction_token'] )->getJson(), true );


		// Perform the capture action (adjust this based on your payment gateway)
		$data1 = [
			'paymentId'   => $result['id'],
			'amount'      => $orderdata['total_amount'],
			'currency'    => $orderdata['currency'],
			'paymentType' => CatalystPaySDK::PAYMENT_TYPE_CAPTURE,
		];


		//Prepare Check out form
		$responseData = $CatalystPaySDK->paymentsByOperations( $data1 );

		$transaction_id1 = $responseData['id'];

		$update = "UPDATE `{$wpdb->prefix}wc_orders` SET `transaction_id` =  '$transaction_id1' WHERE `id` = $order_id";
		$wpdb->query( $update );

		echo 'Order captured and completed successfully!';
	} else {
		echo 'Error capturing and completing order.';
	}

	wp_die();
}
