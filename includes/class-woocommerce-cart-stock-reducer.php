<?php

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

class WC_Cart_Stock_Reducer extends WC_Integration {

	public function __construct() {
		$this->id                 = 'woocommerce-cart-stock-reducer';
		$this->method_title       = __( 'WooCommerce Cart Stock Reducer', 'woocommerce-cart-stock-reducer' );
		$this->method_description = __( 'Allow WooCommerce inventory stock to be reduced when adding items to cart', 'woocommerce-cart-stock-reducer' );

		// Load the settings.
		$this->init_form_fields();
		$this->init_settings();

		// Define user set variables.
		$this->min_no_check        = $this->get_option( 'min_no_check' );
		$this->stock_pending       = $this->get_option( 'stock_pending' );
		$this->expire_items        = $this->get_option( 'expire_items' );
		$this->expire_time         = $this->get_option( 'expire_time' );
		$this->expire_custom_key   = $this->get_option( 'expire_custom_key' );

		// Actions/Filters to setup WC_Integration elements
		add_action( 'woocommerce_update_options_integration_' .  $this->id, array( $this, 'process_admin_options' ) );
		// @todo Add admin interface validation/sanitation

		// Filters related to stock quantity
		add_filter( 'woocommerce_get_availability', array( $this, 'get_avail' ), 10, 2 );
		add_filter( 'woocommerce_update_cart_validation', array( $this, 'update_cart_validation' ), 10, 4 );
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'add_cart_validation' ), 10, 3 );

		// Actions/Filters related to cart item expiration
		add_filter( 'woocommerce_add_cart_item', array( $this, 'add_cart_item' ), 10, 2 );
		add_action( 'woocommerce_check_cart_items', array( $this, 'check_cart_items' ), 10 );


		// @todo Add function to call load_plugin_textdomain()

	}

	/**
	 * Called by 'woocommerce_check_cart_items' action to expire items from cart
	 */
	public function check_cart_items( ) {
		$cart = WC()->cart;
		foreach ( $cart->cart_contents as $cart_id => $item ) {
			if ( isset( $item[ 'csr_expire_time' ] ) && $this->is_expired( $item[ 'csr_expire_time' ] ) ) {
				// Item has expired
				$this->remove_expired_item( $cart_id, $cart );
			}

		}
	}

	protected function remove_expired_item( $cart_id, $cart = null ) {
		if ( null === $cart ) {
			// This should never happen, but better to be safe
			$cart = WC()->cart;
		}
		if ( 'yes' === $this->expire_items ) {
			// @todo Make notice nicer.  Include product name (link?)
			wc_add_notice( __( 'Item has expired and has been removed from your cart', 'woocommerce-cart-stock' ), 'error' );
			unset( $cart->cart_contents[ $cart_id ] );
		}

	}

	/**
	 * Called by 'woocommerce_add_cart_item' filter to add expiration time to cart items
	 *
	 * @param int $item Item ID
	 * @param string $key Unique Cart Item ID
	 *
	 * @return mixed
	 */
	public function add_cart_item( $item, $key ) {
		if ( isset( $item[ 'data' ] ) ) {
			$product = $item[ 'data' ];
			if ( $product->managing_stock() && 'yes' === $this->expire_items ) {
				$expire_time_text = null;
				if ( ! empty( $this->expire_time ) ) {
					// Check global expiration time
					$expire_time_text = $this->expire_time;
				}
				if ( ! empty( $this->expire_custom_key ) ) {
					// Check item specific expiration
					$item_expire_time = get_post_meta( $item[ 'product_id' ], $this->expire_custom_key, true );
					if ( ! empty( $item_expire_time ) ) {
						$expire_time_text = $item_expire_time;
					}
				}
				if ( null !== $expire_time_text && 'never' !== $expire_time_text ) {
					$item[ 'csr_expire_time' ] = strtotime( $expire_time_text );
					// @todo Properly format notice and make more user friendly.  jquery countdown?
					wc_add_notice( __( "Item will expire from cart in $expire_time_text!", 'woocommerce-cart-stock-reducer' ), 'notice' );
				}
			}

		}
		return $item;
	}

	/**
	 * Called via the 'woocommerce_update_cart_validation' filter to validate if the quantity can be updated
	 *
	 * @param $valid
	 * @param string $cart_item_key Specific key for the row in users cart
	 * @param array $values Item information
	 * @param int $quantity Quantity of item to be added
	 *
	 * @return bool true if quantity change to cart is valid
	 */
	public function update_cart_validation( $valid, $cart_item_key, $values, $quantity ) {
		$available = $this->get_stock_available( $values[ 'product_id' ], $values[ 'data' ], $cart_item_key );
		if ( $available < $quantity ) {
			wc_add_notice( __( 'Quantity requested not available', 'woocommerce-cart-stock-reducer' ), 'error' );
			$valid = false;
		}
		return $valid;
	}

	/**
	 * Called via the 'woocommerce_add_to_cart_validation' filter to validate if an item can be added to cart
	 * This will likely only be called if someone hasn't refreshed the item page when an item goes unavailable
	 *
	 * @param $valid
	 * @param int $product_id Item to be added
	 * @param int $quantity Quantity of item to be added
	 *
	 * @return bool true if addition to cart is valid
	 */
	public function add_cart_validation( $valid, $product_id, $quantity ) {
		$available = $this->get_stock_available( $product_id );
		if ( $available < $quantity ) {
			wc_add_notice( __( 'Item is no longer available', 'woocommerce-cart-stock-reducer' ), 'error' );
			$valid = false;
		}
		return $valid;
	}


	/**
	 * Return the quantity back to get_availability()
	 *
	 * @param array $info
	 * @param object $product WooCommerce WC_Product based class
	 *
	 * @return array Info passed back to get_availability
	 */
	public function get_avail( $info, $product ) {

		if ( 'in-stock' === $info[ 'class' ] ) {
			$available = $this->get_stock_available( $product->id, $product );

			if ( 0 < $available ) {
				// Parts taken from WooCommerce core in order to keep text identical
				switch ( get_option( 'woocommerce_stock_format' ) ) {
					case 'no_amount' :
						$info[ 'availability' ] = __( 'In stock', 'woocommerce' );
						break;

					case 'low_amount' :
						if ( $available <= get_option( 'woocommerce_notify_low_stock_amount' ) ) {
							$info[ 'availability' ] = sprintf( __( 'Only %s left in stock', 'woocommerce' ), $available );

							if ( $product->backorders_allowed() && $product->backorders_require_notification() ) {
								$info[ 'availability' ] .= ' ' . __( '(can be backordered)', 'woocommerce' );
							}
						} else {
							$info[ 'availability' ] = __( 'In stock', 'woocommerce' );
						}
						break;

					default :
						$info[ 'availability' ] = sprintf( __( '%s in stock', 'woocommerce' ), $available );
						if ( $product->backorders_allowed() && $product->backorders_require_notification() ) {
							$info[ 'availability' ] .= ' ' . __( '(can be backordered)', 'woocommerce' );
						}
						break;
				}
			} else {
				if ( ! empty( $this->stock_pending ) ) {
					// Override text via configurable option
					$info[ 'availability' ] = $this->stock_pending;
					$info[ 'class' ]        = 'out-of-stock';
				} elseif ( $product->backorders_allowed() && $product->backorders_require_notification() ) {
					$info[ 'availability' ] = __( 'Available on backorder', 'woocommerce' );
					$info[ 'class' ]        = 'available-on-backorder';
				} elseif ( $product->backorders_allowed() ) {
					$info[ 'availability' ] = __( 'In stock', 'woocommerce' );
					$info[ 'class' ]        = 'in-stock';
				} else {
					$info[ 'availability' ] = __( 'Out of stock', 'woocommerce' );
					$info[ 'class' ]        = 'out-of-stock';
				}
			}
		}

		return $info;
	}

	/**
	 * Get the quantity available of a specific item
	 *
	 * @param int $item The item ID
	 * @param object $product WooCommerce WC_Product based class, if not passed the item ID will be used to query
	 * @param string $ignore Cart Item Key to ignore in the count
	 *
	 * @return int Quantity of items in stock
	 */
	public function get_stock_available( $item, $product = null, $ignore = null ) {
		$stock = 0;

		if ( null === $product ) {
			$product = wc_get_product( $item );
		}
		if ( $is_managing_stock = $product->managing_stock() ) {
			if ( true === $is_managing_stock && isset( $product->variation_id ) ) {
				$product_field = 'variation_id';
				$product_id = $product->variation_id;
			} else {
				$product_field = 'product_id';
				$product_id = $product->id;
			}
			$stock = $product->get_total_stock();

			if ( ! empty( $this->min_no_check  ) && $this->min_no_check < (int) $stock ) {
				// Don't bother searching through all the carts if there is more than 'min_no_check' quantity
				return $stock;
			}

			$in_carts = $this->quantity_in_carts( $product_id, $product_field, $ignore );
			if ( 0 < $in_carts ) {
				$stock = ( $stock - $in_carts );
				// Trick WooCommerce into thinking there is no stock available, this does NOT get updated in the DB
				$product->stock = $stock;
			}
		}
		return $stock;
	}

	/**
	 * Search through all sessions and count quantity of $item in all carts
	 *
	 * @param int $item WooCommerce item ID
	 * @param string $field Which field to use to match.  'variation_id' or 'product_id'
	 * @param string $ignore Cart Item Key to ignore in the count
	 *
	 * @return int Total number of items
	 */
	public function quantity_in_carts( $item, $field = 'product_id', $ignore = null ) {
		global $wpdb;
		$quantity = 0;
		$item = (int) $item;

		/* This should be the most efficient way to do this, though the proper way
		 * would be to retrieve a list of all the session and use get_option or
		 * the WooCommerce API to iterate through the sessions.  Though it is possible
		 * that using get_option and an external cache will be faster on a heavy site.
		 * Need to benchmark... In my free time.
    	*/
		$results = $wpdb->get_results( "SELECT option_name, option_value FROM $wpdb->options WHERE option_name LIKE '_wc_session_%' AND option_value LIKE '%\"{$field}\";i:{$item};%'", OBJECT );
		if ( $results ) {
			foreach ( $results as $result ) {
				$session = unserialize( $result->option_value );
				if ( isset( $session[ 'cart' ] ) ) {
					$cart = unserialize( $session[ 'cart' ] );
					foreach ( $cart as $key => $row ) {
						if ( isset( $row[ 'csr_expire_time' ] ) && $this->is_expired( $row[ 'csr_expire_time' ] ) ) {
							// Skip items that are expired in carts
							continue;
						}
						if ( $key !== $ignore && $item === $row[ $field ] ) {
							$quantity += $row[ 'quantity' ];
						}
					}
				}

			}
		}

		return $quantity;
	}

	/**
	 * Check if $expire_time has passed
	 *
	 * @param int|string $expire_time UNIX timestamp for expiration or 'never' if item never expires
	 *
	 * @return bool true if expired
	 */
	protected function is_expired( $expire_time = 'never' ) {
		$expired = false;
		if ( 'never' === $expire_time ) {
			// This should never happen, but better to be safe
			$expired = false;
		} elseif ( $expire_time < time() ) {
			$expired = true;
		}
		return $expired;
	}

	public function init_form_fields() {
		$this->form_fields = array(
			'min_no_check' => array(
				'title'             => __( 'Minimum Stock to Skip Check', 'woocommerce-cart-stock-reducer' ),
				'type'              => 'decimal',
				'description'       => __( 'Enter the minimum quantity of stock to have in order to skip checking carts.  This should be higher than the amount you expect could sell before the carts expire.', 'woocommerce-cart-stock-reducer' ),
				'desc_tip'          => true,
				'default'           => ''
			),
			'stock_pending' => array(
				'title'             => __( 'Pending Order Text', 'woocommerce-cart-stock-reducer' ),
				'type'              => 'text',
				'description'       => __( 'Enter alternate text to be displayed when there are items in stock but held in an existing cart.', 'woocommerce-cart-stock-reducer' ),
				'desc_tip'          => true,
				'default'           => ''
			),
			'expire_items' => array(
				'title'             => __( 'Expire Items', 'woocommerce-cart-stock-reducer' ),
				'type'              => 'checkbox',
				'label'             => __( 'Enable Item Expiration', 'woocommerce-cart-stock-reducer' ),
				'default'           => 'no',
				'description'       => __( 'If checked, items that stock is managed for will expire from carts', 'woocommerce-cart-stock-reducer' ),
			),
			'expire_time' => array(
				'title'             => __( 'Expire Time', 'woocommerce-cart-stock-reducer' ),
				'type'              => 'text',
				// @todo Need better explanation about valid times (ie: anything strtotime will take)
				'description'       => __( 'How long before item expires from cart', 'woocommerce-cart-stock-reducer' ),
				'desc_tip'          => true,
				'default'           => ''
			),
			'expire_custom_key' => array(
				'title'             => __( 'Expire Custom Key', 'woocommerce-cart-stock-reducer' ),
				'type'              => 'text',
				'description'       => __( 'Enter "Custom Field" name that can be referenced on a specific item to set expiration time.', 'woocommerce-cart-stock-reducer' ),
				'desc_tip'          => true,
				'default'           => 'csr_expire_time'
			),


		);
	}


}