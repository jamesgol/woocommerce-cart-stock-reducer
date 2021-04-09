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

	private $csr = null;

	private $sessions_loaded = false;

	public function __construct( $csr = null ) {
		$this->csr = $csr;

		$WC = WC();
		if ( isset( $WC, $WC->session ) ) {
			// Pre-prime with the current customers session
			$this->current_customer_id = $customer_id = $WC->session->get_customer_id();
			$this->sessions[ $customer_id ] = $WC->session;
		}
		add_filter( 'manage_product_posts_columns', array( $this, 'product_columns' ), 11, 1 );
		add_action( 'manage_product_posts_custom_column', array( $this, 'render_product_columns' ), 11, 1 );
	}

	/**
	 * Gets a cache prefix. This is used in session names so the entire cache can be invalidated with 1 function call.
	 *
	 * @return string
	 */
	private function get_cache_prefix() {
		return WC_Cache_Helper::get_cache_prefix( WC_SESSION_CACHE_GROUP );
	}

	protected function get_all_items_in_carts( $refresh_cache = false ) {
		global $wpdb;

		if ( false === $this->sessions_loaded || true === $refresh_cache ) {
			$results = $wpdb->get_results( "SELECT session_key, session_value, session_expiry FROM {$wpdb->prefix}woocommerce_sessions", OBJECT );
			if ( $results ) {
				foreach ( $results as $result ) {
					$customer_id = $result->session_key;
					$expiry      = $result->session_expiry;

					if ( ! array_key_exists( $customer_id, $this->sessions ) ) {
						$this->sessions[ $customer_id ] = new WC_CSR_Session( $customer_id, $result->session_value, $expiry );
					}
				}
			}
			$this->sessions_loaded = true;
		}
		return $this->sessions;
	}

	public function get_sessions() {
		return $this->sessions;
	}

	public function get_session( $session = null ) {
		if ( isset( $session, $this->sessions[ $session ] ) ) {
			return $this->sessions[ $session ];
		}
		return null;
	}

	/**
	 * Search through all sessions and count quantity of $item in all carts
	 *
	 * @param int $item WooCommerce item ID
	 * @param string $field Which field to use to match.  'variation_id' or 'product_id'
	 * @param bool $ignore true if active users count should be ignored
	 * @param bool $use_cache true if we should use cached data, false will force DB query
	 *
	 * @return int|double Total number of items
	 */
	public function quantity_in_carts( $item, $field = 'product_id', $ignore = false, $use_cache = true ) {
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

		$items = $this->find_items_in_carts( $item, $use_cache );

		foreach ( $items as $session_id => $session_data ) {
			if ( $ignore && $session_id == $this->current_customer_id ) {
				// Skip users own items if $ignore is true
				continue;
			}
			foreach ( $session_data as $item_data ) {
				if ( true === apply_filters( 'wc_csr_skip_cart_item', false, $item, $session_id, $item_data, $this ) ) {
					// Allow users to determine if items should be ignored in the total count.
					// Useful only if you want specific users items to be counted in the virtual stock
					continue;
				}
				if ( isset( $item_data['csr_expire_time'] ) ) {
					if ( $session = $this->get_session( $session_id ) ) {
						$order_awaiting_payment = $session->get( 'order_awaiting_payment', null );
					} else {
						$order_awaiting_payment = null;
					}
					if ( $this->csr->is_expired( $item_data['csr_expire_time'], $order_awaiting_payment ) ) {
						// Skip items that are expired in carts
						continue;
					}
				}


				if ( $item === $item_data['product_id'] || $item === $item_data['variation_id'] ) {
					$quantity += $item_data['quantity'];
				}
			}
		}

		// Force quantity to number, but allow other than int
		return 0 + $quantity;
	}

	public function find_items_in_carts( $item, $use_cache = true ) {
		$items = array();

		$this->get_all_items_in_carts( $use_cache ? false : true );

		foreach ( $this->sessions as $session_id => $session ) {
			if ( $cart = $session->cart ) {
				foreach ( $session->cart as $cart_id => $cart_item ) {
					if ( $item === $cart_item['product_id'] || $item === $cart_item['variation_id'] ) {
						$items[ $session_id ][ $cart_item['key'] ] = $cart_item;
					}
				}
			}
		}
		return $items;
	}

	/**
	 * Define custom columns for products.
	 *
	 * @param  array $existing_columns
	 *
	 * @return array
	 */
	public function product_columns( $existing_columns ) {
		$existing_columns = $this->array_insert_after( $existing_columns, 'is_in_stock', array( 'qty_in_carts' => __( 'Quantity in Carts', 'woocommerce-cart-stock-reducer' ) ) );
		wp_enqueue_style( 'wc-csr-styles' );


		return $existing_columns;
	}

	public function array_insert_after( $array, $after_key, $new = array() ) {
		$pos = array_search( $after_key, array_keys( $array ) );
		$pos++;
		$result = array_slice( $array, 0, $pos ) + $new + array_slice( $array, $pos );

		return $result;
	}

	/**
	 * Output custom columns for products.
	 *
	 * @param string $column
	 */
	public function render_product_columns( $column ) {
		global $post, $the_product;

		if ( 'qty_in_carts' === $column ) {

			if ( empty( $the_product ) || $the_product->get_id() != $post->ID ) {
				$the_product = wc_get_product( $post );
			}

			// Only continue if we have a product.
			if ( empty( $the_product ) ) {
				return;
			}

			echo (int) $this->quantity_in_carts( $post->ID );
		}

	}
}
