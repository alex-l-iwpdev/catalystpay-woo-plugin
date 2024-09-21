<?php
/**
 * Db helpers class.
 *
 * @package wc-catalystpay
 */

namespace WcCatalystpayGetway\Plugin\Helpers;

/**
 * DBHelpers class file.
 */
class DBHelpers {

	public static function get_transfer_token_by_order_id( $order_id ) {
		global $wpdb;

		$prefix     = $wpdb->prefix;
		$table_name = $prefix . 'catalystpay_order';

		$result = $wpdb->get_results(
			$wpdb->prepare( "SELECT transaction_token FROM $table_name WHERE order_id = %d", $order_id )
		);

		if ( empty( $result ) || is_wp_error( $result ) ) {
			return [];
		}

		return $result[0]->transaction_token;
	}
}
