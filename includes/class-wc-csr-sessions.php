<?php

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 *
 * Class WC_CSR_Sessions
 *
 */
class WC_CSR_Sessions  {

	private $sessions = array();

	private $current_customer_id = null;

	public function __construct() {
		$WC = WC();
		if ( isset( $WC, $WC->session ) ) {
			// Pre-prime with the current customers session
			$this->current_customer_id = $customer_id = $WC->session->get_customer_id();
			$this->sessions[ $customer_id ] = $WC->session;
		}
		$this->prime_cache();
	}

	/**
	 * Gets a cache prefix. This is used in session names so the entire cache can be invalidated with 1 function call.
	 *
	 * @return string
	 */
	private function get_cache_prefix() {
		return WC_Cache_Helper::get_cache_prefix( WC_SESSION_CACHE_GROUP );
	}


	protected function prime_cache() {
		global $wpdb;

		if ( version_compare( WOOCOMMERCE_VERSION, '2.5' ) >= 0 ) {
			// WooCommerce >= 2.5 stores session data in a separate table
			$results = $wpdb->get_results( "SELECT session_key, session_value, session_expiry FROM {$wpdb->prefix}woocommerce_sessions", OBJECT );
		} else {
			$results = $wpdb->get_results( "SELECT option_name, option_value as session_value FROM {$wpdb->options} WHERE option_name LIKE '_wc_session_%'", OBJECT );
			// @TODO Need to figure out how < 2.5 did expiry
		}
		if ( $results ) {
			foreach ( $results as $result ) {
				if ( isset( $result->option_name ) ) {
					// Remove '_wc_session_' from string to get cart ID (on WC < 3.0)
					$customer_id = substr( $result->option_name, 12 );
				} else {
					$customer_id = $result->session_key;
				}
				if ( empty( $result->session_expiry ) ) {
					// @TODO Temporary till figure out what < 2.5 did
					$expiry = time() + 60 * 60 * 48;
				}
				if ( !array_key_exists( $customer_id, $this->sessions ) ) {
					$this->sessions[ $customer_id ] = new WC_CSR_Session( $customer_id, $result->session_value, $expiry );
					wp_cache_set( $this->get_cache_prefix() . $customer_id, $result->session_value, WC_SESSION_CACHE_GROUP, $expiry - time() );
				}
			}
		}
		return $this->sessions;
	}

	public function get_sessions() {
		return $this->sessions;
	}

	/**
	 * Search through all sessions and count quantity of $item in all carts
	 *
	 * @param int $item WooCommerce item ID
	 * @param string $field Which field to use to match.  'variation_id' or 'product_id'
	 * @param bool $ignore true if active users count should be ignored
	 *
	 * @return int Total number of items
	 */
	public function quantity_in_carts( $item, $field = 'product_id', $ignore = false ) {
		$quantity = 0;
		$item = (int) $item;
		$customer_id = null;

		/**
		 * The old method of querying per item on a page wasn't scalable (especially with WC > 3.0) which would
		 * end up running the query multiple times per item.
		 *
		 * Presumably most sites have a limited number of active sessions/carts, so pre-cache all of the sessions.
		 * This does double duty since it uses the same cache groups as WC already does.
		 *
		 * I'm guessing this will have to be revisted when someone has tons of active sessions comes along, maybe
		 * by then this functionality will be built into WC
		 */

		foreach ( $this->sessions as $id => $session ) {
			if ( $ignore && $id == $this->current_customer_id ) {
				// Skip users own items if $ignore is true
				continue;
			}
			if ( $cart = $session->cart ) {
				foreach ( $session->cart as $cart_id => $cart_item ) {
					if ( $cart_item[ $field ] === $item ) {
						$quantity += $cart_item['quantity'];
					}
				}
			}
		}
		return $quantity;
	}

	public function find_items_in_carts( $item ) {
		$items = array();

		foreach ( $this->sessions as $session_id => $session ) {
			if ( $cart = $session->cart ) {
				foreach ( $session->cart as $cart_id => $cart_item ) {
					if ( $item === $cart_item['product_id'] || $item === $cart_item['variation_id'] ) {
						$items[$session_id] = $cart_item;
					}
				}
			}
		}
		return $items;
	}

}
