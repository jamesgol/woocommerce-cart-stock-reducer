<?php

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

class WC_Cart_Stock_Reducer extends WC_Integration {

	public function __construct() {
		global $woocommerce;

		$this->id                 = 'woocommerce-cart-stock-reducer';
		$this->method_title       = __( 'WooCommerce Cart Stock Reducer', 'woocommerce-cart-stock-reducer' );
		$this->method_description = __( 'Allow WooCommerce inventory stock to be reduced when adding items to cart', 'woocommerce-cart-stock-reducer' );

		// Load the settings.
		$this->init_form_fields();
		$this->init_settings();

		// Define user set variables.
		$this->min_no_check          = $this->get_option( 'min_no_check' );
		$this->stock_pending          = $this->get_option( 'stock_pending' );

		// Actions.
		add_action( 'woocommerce_update_options_integration_' .  $this->id, array( $this, 'process_admin_options' ) );

		add_filter( 'woocommerce_get_availability', array( $this, 'get_avail' ), 10, 2 );
		add_filter( 'woocommerce_update_cart_validation', array( $this, 'update_cart_validation' ), 10, 4 );
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'add_cart_validation' ), 10, 3 );

		// @todo Add function to call load_plugin_textdomain()

	}

	public function update_cart_validation( $valid, $cart_item_key, $values, $quantity ) {
		$available = $this->get_stock_available( $values[ 'product_id' ], $values[ 'data' ], $cart_item_key );
		if ( $available < $quantity ) {
			wc_add_notice( __( 'Quantity requested not available', 'woocommerce-cart-stock-reducer' ), 'error' );
			$valid = false;
		}
		return $valid;
	}

	public function add_cart_validation( $valid, $product_id, $quantity ) {
		$available = $this->get_stock_available( $product_id );
		if ( $available < $quantity ) {
			wc_add_notice( __( 'Item is no longer available', 'woocommerce-cart-stock-reducer' ), 'error' );
			$valid = false;
		}
		return $valid;
	}


	/**
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
				if ( $stock_pending = $this->get_option( 'stock_pending' ) ) {
					// Override text via configurable option
					$info[ 'availability' ] = $stock_pending;
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

	public function get_stock_available( $item, $product = null, $ignore = null ) {
		if ( null === $product ) {
			$product = wc_get_product( $item );
		}
		if ( $product->managing_stock() ) {
			$stock = $product->get_total_stock();

			if ( ( $min_no_check = $this->get_option( 'min_no_check', false ) ) && $min_no_check < (int) $stock ) {
				// Don't bother searching through all the carts if there is more than 'min_no_check' quantity
				return $stock;
			}

			$in_carts = $this->quantity_in_carts( $product->id, $ignore );
			if ( 0 < $in_carts ) {
				$stock      = ( $stock - $in_carts );
				$product->stock = $stock;
			}

			return $stock;
		}
	}

	/**
	 * Search through all sessions and count quantity of $item in all carts
	 *
	 * @param int $item WooCommerce item ID
	 *
	 * @return int Total number of items
	 */
	public function quantity_in_carts( $item, $ignore = null ) {
		global $wpdb;
		$quantity = 0;
		$item = (int) $item;

		/* This should be the most efficient way to do this, though the proper way
		 * would be to retrieve a list of all the session and use get_option or
		 * the WooCommerce API to iterate through the sessions.  Though it is possible
		 * that using get_option and an external cache will be faster on a heavy site.
		 * Need to benchmark... In my free time.
    	*/
		$results = $wpdb->get_results( "SELECT option_name, option_value FROM $wpdb->options WHERE option_name LIKE '_wc_session_%' AND option_value LIKE '%\"product_id\";i:{$item};%'", OBJECT );
		if ( $results ) {
			foreach ( $results as $result ) {
				$session = unserialize( $result->option_value );
				if ( isset( $session[ 'cart' ] ) ) {
					$cart = unserialize( $session[ 'cart' ] );
					foreach ( $cart as $key => $row ) {
						if ( $key !== $ignore && $item === $row[ 'product_id'] ) {
							$quantity += $row[ 'quantity' ];
						}
					}
				}

			}
		}

		return $quantity;
	}

	public function init_form_fields() {
		$this->form_fields = array(
			'min_no_check' => array(
				'title'             => __( 'Minimum Stock to Skip Check', 'woocommerce-cart-stock-reducer' ),
				'type'              => 'text',
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
		);
	}


}