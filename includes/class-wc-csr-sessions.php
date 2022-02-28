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

	private $cache_prefix = 'carts_with_item';

	private $cache_group = 'WC-CSR';

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

		/*
		 *  Caching the results of the inventory counts is easy, but ensuring the counts are accurate through all
		 *  of the many places the carts can be adjusted is hard.
		 *
		 */
		add_action( 'woocommerce_remove_cart_item', array( $this, 'woocommerce_remove_cart_item' ), 10, 2 );
		add_action( 'woocommerce_after_cart_item_quantity_update', array( $this, 'woocommerce_update_cart_item_quantity'), 10, 4);
		add_action( 'woocommerce_before_cart_emptied', array( $this, 'woocommerce_before_cart_emptied' ), 10, 1 );
		add_action( 'woocommerce_updated_product_stock', array( $this, 'woocommerce_updated_product_stock' ), 10, 1 );
		add_action( 'woocommerce_ajax_order_items_removed', array( $this, 'woocommerce_ajax_order_items_removed' ), 10, 4 );
		add_action( 'woocommerce_variation_before_set_stock', array( $this, 'woocommerce_variation_before_set_stock' ), 10, 1 );
		add_action( 'woocommerce_product_before_set_stock', array( $this, 'woocommerce_product_before_set_stock' ), 10, 1 );
		add_filter( 'woocommerce_prevent_adjust_line_item_product_stock', array( $this, 'woocommerce_prevent_adjust_line_item_product_stock' ), 10, 3 );
		add_action( 'wp_login', array( $this, 'wp_login' ), 11, 2 );

	}

	public function wp_login( $user_login, $user ) {
		# When a user logs in they might have items in their cart already.  Purge the cache on those items so they are properly recalculated
		$cart = WC()->cart;
		if ( null !== $cart ) {
			foreach ( $cart->cart_contents as $cart_id => $item ) {
				$this->remove_cache_item( $item['product_id'], $item['variation_id'] );
			}
		}
	}

	public function woocommerce_prevent_adjust_line_item_product_stock( $value, $item, $item_quantity ) {
		if ( is_callable( array( $item, 'get_product' ) ) ) {
			$product = $item->get_product();
			if ( $product ) {
				$this->remove_cache_item( $product->get_id() );
			}
		}

		return $value;
	}

	public function woocommerce_product_before_set_stock( $product ) {
		if ( is_callable( array( $product, 'get_id' ) ) ) {
			$this->remove_cache_item( $product->get_id() );
		}
	}

	public function woocommerce_variation_before_set_stock( $product ) {
		if ( is_callable( array( $product, 'get_id' ) ) ) {
			$this->remove_cache_item( $product->get_id() );
		}
	}

	public function woocommerce_updated_product_stock( $product_id_with_stock = null ) {
		if ( null !== $product_id_with_stock ) {
			$this->remove_cache_item( $product_id_with_stock );
		}
	}

	public function woocommerce_before_cart_emptied( $clear_persistent_cart ) {
		$cart = WC()->cart->get_cart();
		foreach ( $cart as $cart_item_key => $values ) {
			if ( !empty( $values['product_id'] ) ) {
				$this->remove_cache_item( $values['product_id'] );
			}
			if ( !empty( $values['variation_id'] ) ) {
				$this->remove_cache_item( $values['variation_id'] );
			}
		}
	}

	public function woocommerce_update_cart_item_quantity( $cart_item_key, $quantity, $old_quantity, $cart ) {
		$this->remove_cache_item_by_key( $cart_item_key, $cart );
	}

	public function woocommerce_remove_cart_item( $cart_item_key, $cart ) {
		$this->remove_cache_item_by_key( $cart_item_key, $cart );
	}

	public function remove_cache_item_by_key( $cart_item_key, $cart ) {
		if ( isset( $cart_item_key, $cart, $cart->cart_contents[ $cart_item_key ] ) ) {
			$item = $cart->cart_contents[ $cart_item_key ];
			$this->remove_cache_item( $item['product_id'], $item['variation_id'] );
		}
	}

	public function remove_cache_item() {
		foreach ( func_get_args() as $item ) {
			wp_cache_delete( $this->get_cache_key( $item ), $this->cache_group );
		}
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

	public function get_cache_key( $item ) {
		return "{$this->cache_prefix}_$item";
	}

	public function find_items_in_carts( $item, $use_cache = true ) {
		$items = array();

		if ( true === $use_cache ) {
			$cached_items = wp_cache_get( $this->get_cache_key( $item ), $this->cache_group );
			if ( false !== $cached_items ) {
				return $cached_items;
			}
		}

		$this->get_all_items_in_carts( $use_cache ? false : true );

		if ( isset( $this->csr, $this->csr->expire_time ) ) {
			// Start with the global expiration time
			$earliest_expiration_time = strtotime( $this->csr->expire_time );
		} else {
			// If no global use 10 minutes
			$earliest_expiration_time = time() + 600;
		}

		foreach ( $this->sessions as $session_id => $session ) {
			if ( $cart = $session->cart ) {
				foreach ( $session->cart as $cart_id => $cart_item ) {
					if ( $item === $cart_item['product_id'] || $item === $cart_item['variation_id'] ) {
						$items[ $session_id ][ $cart_item['key'] ] = $cart_item;
						if ( isset( $cart_item['csr_expire_time'] ) && time() < $cart_item['csr_expire_time'] && $cart_item['csr_expire_time'] < $earliest_expiration_time ) {
							// Only cache as long as the earliest expiration time in the future
							$earliest_expiration_time = $cart_item['csr_expire_time'];
						}
					}
				}
			}
		}

		$expiration_in_seconds = absint( time() - $earliest_expiration_time );
		// Always cache entry even if the function was told not to use the cache
		wp_cache_set( $this->get_cache_key( $item ), $items, $this->cache_group, $expiration_in_seconds );

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
