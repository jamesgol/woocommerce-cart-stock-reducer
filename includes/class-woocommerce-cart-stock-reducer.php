<?php

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

class WC_Cart_Stock_Reducer extends WC_Integration {
	// Variables used for specific session
	private $item_expire_message = null;
	private $countdown_seconds   = array();
	private $expiration_notice_added = false;
	private $language = null;
	private $num_expiring_items = 0;
	private $checking_virtual_stock = false;
	private $virtual_depth = 0;
	private $sessions;

	public function __construct() {
		$this->id                 = 'woocommerce-cart-stock-reducer';
		$this->method_title       = __( 'Cart Stock Reducer', 'woocommerce-cart-stock-reducer' );
		$this->method_description = __( 'Allow WooCommerce inventory stock to be reduced when adding items to cart', 'woocommerce-cart-stock-reducer' );
		$this->plugins_url        = plugins_url( '/', dirname( __FILE__ ) );
		$this->plugin_dir         = realpath( dirname( __FILE__ ) . '/..' );

		// Load the settings.
		$this->init_form_fields();
		$this->init_settings();

		// Define user set variables.
		$this->cart_stock_reducer  = $this->get_option( 'cart_stock_reducer' );
		$this->stock_pending       = $this->get_option( 'stock_pending' );
		$this->stock_pending_expire_time = $this->get_option( 'stock_pending_expire_time' );
		$this->stock_pending_include_cart_items = $this->get_option( 'stock_pending_include_cart_items' );
		$this->expire_items        = $this->get_option( 'expire_items' );
		$this->expire_countdown    = $this->get_option( 'expire_countdown' );
		$this->expire_categories   = $this->get_option( 'expire_categories', null );
		$this->expire_time         = $this->get_option( 'expire_time' );
		$this->ignore_status       = $this->get_option( 'ignore_status', array() );

		// When to refresh all items
		$this->refresh_items_add          = $this->get_option( 'refresh_items_add', 'no' );
		$this->refresh_items_cart         = $this->get_option( 'refresh_items_cart', 'no' );
		$this->refresh_items_checkout     = $this->get_option( 'refresh_items_checkout', 'no' );
		$this->refresh_items_checkout_pay = $this->get_option( 'refresh_items_checkout_pay', 'no' );


		// Actions/Filters to setup WC_Integration elements
		add_action( 'woocommerce_update_options_integration_' .  $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_init', array( $this, 'woocommerce_init' ) );
		add_action( 'wp', array( $this, 'check_refresh_items' ) );

		// @todo Add admin interface validation/sanitation

		// Hooks for handling backend per-product settings
		add_action( 'woocommerce_product_options_inventory_product_data', array( $this, 'woocommerce_product_options_inventory_product_data' ) );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'woocommerce_admin_process_product_object' ), 10, 1 );

		// Filters related to stock quantity
		add_filter( 'woocommerce_update_cart_validation', array( $this, 'update_cart_validation' ), 10, 4 );
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'add_cart_validation' ), 10, 5 );
		add_filter( 'woocommerce_quantity_input_args', array( $this, 'quantity_input_args' ), 10, 2 );
		add_filter( 'wc_csr_stock_pending_text', array( $this, 'replace_stock_pending_text' ), 10, 3 );
		add_action( 'wc_csr_adjust_cart_expiration', array( $this, 'adjust_cart_expiration' ), 10, 2 );
		add_filter( 'woocommerce_get_undo_url', array( $this, 'get_undo_url' ), 10, 2 );
		add_filter( 'woocommerce_product_variation_get_stock_quantity', array( $this, 'product_get_stock_quantity' ), 10, 2 );
		add_filter( 'woocommerce_product_get_stock_quantity', array( $this, 'product_get_stock_quantity' ), 10, 2 );
		add_filter( 'woocommerce_product_variation_get_stock_status', array( $this, 'product_get_stock_status' ), 10, 2 );
		add_filter( 'woocommerce_product_get_stock_status', array( $this, 'product_get_stock_status' ), 10, 2 );
		add_filter( 'woocommerce_get_availability_class', array( $this, 'get_availability_class' ), 10, 2 );
		add_filter( 'woocommerce_get_availability_text', array( $this, 'get_availability_text' ), 10, 2 );

		add_filter( 'woocommerce_available_variation', array( $this, 'product_available_variation' ), 10, 3 );
		add_filter( 'woocommerce_post_class', array( $this, 'woocommerce_post_class' ), 10, 2  );


		// Actions/Filters related to cart item expiration
		if ( ! is_admin() || defined( 'DOING_AJAX' ) && ! defined( 'DOING_CRON' ) ) {
			add_action( 'woocommerce_add_to_cart', array( $this, 'add_to_cart' ), 10, 6 );
			add_filter( 'woocommerce_add_cart_item', array( $this, 'add_cart_item' ), 10, 2 );
			add_action( 'woocommerce_cart_loaded_from_session', array( $this, 'check_expired_items' ), 10 );
			add_filter( 'woocommerce_notice_types', array( $this, 'add_countdown_to_notice' ), 10 );
			add_filter( 'wc_add_to_cart_message_html', array( $this, 'add_to_cart_message' ), 10, 2 );

			// Some Third-Party themes do not call 'woocommerce_before_main_content' action so let's call it on other likely actions
			add_action( 'woocommerce_before_single_product', array( $this, 'check_cart_items' ), 9 );
			add_action( 'woocommerce_check_cart_items', array( $this, 'check_cart_items' ), 9 );
			add_action( 'woocommerce_before_shop_loop', array( $this, 'check_cart_items' ), 9 );

		}

		// @TODO Use minimized version if not debug
		wp_register_script( 'wc-csr-jquery-countdown', $this->plugins_url . 'assets/js/jquery-countdown/js/jquery.countdown.min.js', array( 'jquery', 'wc-csr-jquery-plugin' ), '2.1.0', true );
		wp_register_script( 'wc-csr-jquery-plugin', $this->plugins_url . 'assets/js/jquery-countdown/js/jquery.plugin.min.js', array( 'jquery' ), '2.1.0', true );
		wp_register_style( 'wc-csr-styles', $this->plugins_url . 'assets/css/woocommerce-cart-stock-reducer.css', array(), '2.10' );
		add_action( 'init', array( $this, 'load_plugin_textdomain' ) );

		// Direct link to our settings page
		add_filter( 'plugin_action_links', array( $this, 'action_links' ), 10, 2 );

	}

	public function load_plugin_textdomain() {
		load_plugin_textdomain( 'woocommerce-cart-stock-reducer', false, plugin_basename( $this->plugin_dir ) . '/languages/' );
		$this->language = $this->find_countdown_language( apply_filters( 'wc_csr_countdown_locale', get_locale() ) );
		if ( $this->language ) {
			wp_register_script( 'wc-csr-jquery-countdown-locale', $this->plugins_url . "assets/js/jquery-countdown/js/jquery.countdown-{$this->language}.js", array( 'jquery',	'wc-csr-jquery-plugin',	'wc-csr-jquery-countdown' ), '2.1.0', true );
		}

	}

	public function woocommerce_init() {
		require_once 'class-wc-csr-session.php';
		require_once 'class-wc-csr-sessions.php';
		$this->sessions = new WC_CSR_Sessions( $this );
	}

	public function woocommerce_admin_process_product_object( $product ) {
		if ( isset( $_POST['_csr_reducer_mode' ] ) && in_array( $_POST['_csr_reducer_mode'], array( 'default', 'always', 'never' ) ) ) {
			update_post_meta( $product->get_id(), '_csr_reducer_mode', $_POST['_csr_reducer_mode'] );
		}
		if ( isset( $_POST['_csr_expire_mode' ] ) && in_array( $_POST['_csr_expire_mode'], array( 'default', 'always', 'never' ) ) ) {
			update_post_meta( $product->get_id(), '_csr_expire_mode', $_POST['_csr_expire_mode'] );
		}
		if ( isset( $_POST['_csr_expire_custom_time' ] )  ) {
			$expire_time = sanitize_text_field( $_POST['_csr_expire_custom_time'] );
			$expire_custom_key = apply_filters( 'wc_csr_expire_custom_key', 'csr_expire_time', $product, null );
			update_post_meta( $product->get_id(), $expire_custom_key, $expire_time );
		}

	}

	public function woocommerce_product_options_inventory_product_data() {
		global $product_object;

		if ( in_array( $this->cart_stock_reducer, array( 'yes', 'no' ) ) ) {
		    // Only display field if it is possible to override
			woocommerce_wp_select(
				array(
					'id'          => '_csr_reducer_mode',
					'value'       => get_post_meta( $product_object->get_id(), '_csr_reducer_mode', true ),
					'label'       => __( 'Cart Stock Reducer', 'woocommerce-cart-stock-reducer' ),
					'options'     => array(
						'default' => __( 'Store-wide default', 'woocommerce-cart-stock-reducer' ),
						'always'  => __( 'Always reduce stock', 'woocommerce-cart-stock-reducer' ),
						'never'   => __( 'Never reduce stock', 'woocommerce-cart-stock-reducer' ),
					),
					'desc_tip'    => true,
					'description' => __( 'Override default Cart Stock Reducer mode.', 'woocommerce-cart-stock-reducer' ),
				)
			);
		}

		if ( in_array( $this->expire_items, array( 'no', 'yes', 'all' ) ) ) {
			// Only display field if it is possible to override
			woocommerce_wp_select(
				array(
					'id'          => '_csr_expire_mode',
					'value'       => get_post_meta( $product_object->get_id(), '_csr_expire_mode', true ),
					'label'       => __( 'Cart Stock Expiration', 'woocommerce-cart-stock-reducer' ),
					'options'     => array(
						'default' => __( 'Store-wide default', 'woocommerce-cart-stock-reducer' ),
						'always'  => __( 'Always expire item ', 'woocommerce-cart-stock-reducer' ),
						'never'   => __( 'Never expire item', 'woocommerce-cart-stock-reducer' ),
					),
					'desc_tip'    => true,
					'description' => __( 'override default Card Stock Reducer expiration mode.', 'woocommerce-cart-stock-reducer' ),
				)
			);


			$expire_custom_key = apply_filters( 'wc_csr_expire_custom_key', 'csr_expire_time', $product_object, null );

			woocommerce_wp_text_input(
				array(
					'id'                => '_csr_expire_custom_time',
					'value'             => get_post_meta( $product_object->get_id(), $expire_custom_key, true ),
					'label'             => __( 'Expiration Time Override', 'woocommerce-cart-stock-reducer' ),
					'desc_tip'          => true,
					'description'       => sprintf( __( 'Override the default cart expiration time (default is \'%s\')', 'woocommerce-cart-stock-reducer' ), $this->expire_time ),
					'type'              => 'text',
					'custom_attributes' => array(
						'step' => 'any',
					),
				)
			);
		}

	}

	public function check_refresh_items() {
		$refresh = false;

		if ( is_cart() && 'yes' === $this->refresh_items_cart ) {
			$refresh = true;
		} elseif ( is_checkout() && 'yes' === $this->refresh_items_checkout ) {
			$refresh = true;
		} elseif ( is_checkout_pay_page() && 'yes' === $this->refresh_items_checkout_pay ) {
			$refresh = true;
		}
		$refresh = apply_filters( 'wc_csr_check_refresh_items', $refresh, $this );
		if ( true === $refresh ) {
			$this->adjust_cart_expiration();
		}
	}

	public function woocommerce_post_class( $classes, $product ) {
		if ( ! $this->is_reducer_enabled( $product ) ) {
			return $classes;
		}

		$actual_stock = $this->get_actual_stock_available( $product );

		if ( $actual_stock > 0 ) {
			$virtual_stock = $this->get_virtual_stock_available( $product );
			if ( is_numeric( $virtual_stock ) && $virtual_stock < 1 && ! in_array( 'stockpending', $classes ) ) {
				$classes[] = 'stockpending';
			}
		}
		return $classes;
	}

	/**
	 * Search the countdown files for the closest localization match
	 *
	 * @param string $lang name to search
	 *
	 * @return null|string language name to use for countdown
	 */
	public function find_countdown_language( $lang = null ) {
		if ( !empty( $lang ) ) {
			// jquery-countdown uses - as separator instead of _
			$lang = str_replace( '_', '-', $lang );
			$file = $this->plugin_dir . '/assets/js/jquery-countdown/js/jquery.countdown-' . $lang . '.js';
			if ( file_exists( $file ) ) {
				return $lang;
			} elseif ( $part = substr( $lang, 0, strpos( $lang, '-' ) ) ) {
				$file = $this->plugin_dir . '/assets/js/jquery-countdown/js/jquery.countdown-' . $part . '.js';
				if ( file_exists( $file ) ) {
					return $part;
				}
			}
		}
		return null;
	}

	 /**
	  * Generate a direct link to settings page within WooCommerce
	  *
	  */
	public function action_links( $links, $file ) {
		if ( 'woocommerce-cart-stock-reducer/woocommerce-cart-stock-reducer.php' == $file ) {
			$settings_link = '<a href="' . admin_url( 'admin.php?page=wc-settings&tab=integration' ) . '">' . __( 'Settings', 'woocommerce-cart-stock-reducer' ) . '</a>';
			// make the 'Settings' link appear first
			array_unshift( $links, $settings_link );
		}
		return $links;
	}

	/**
	 * Called from 'woocommerce_quantity_input_args' filter to adjust the maximum quantity of items a user can select
	 *
	 * @param array $args
	 * @param object $product WC_Product type object
	 *
	 * @return array
	 */
	public function quantity_input_args( $args, $product ) {
		if ( ! $this->is_reducer_enabled( $product ) ) {
			return $args;
		}
		if ( 'quantity' === $args[ 'input_name' ] ) {
			$ignore = false;
		} else {
			// Ignore users quantity when looking at pages like the shopping cart
			$ignore = true;
		}
		if ( ! $product->is_sold_individually() ) {
			// Only products that aren't sold individually need a max value adjustment
			$virtual_stock =  $this->get_virtual_stock_available( $product, $ignore );
			// Use the lowest of the max_value, in case another plugin is reducing this number
			$args['max_value'] = min( $args['max_value'], $virtual_stock );
		}

		return $args;
	}

	public function expire_notice_added() {
		if ( true === $this->expiration_notice_added ) {
			// Don't loop through notices if we already know it has been added
			return true;
		}
		foreach ( wc_get_notices() as $type => $notices ) {
			foreach ( $notices as $notice ) {
				if ( is_array( $notice ) ) {
					// WooCommerce 3.9.1 changed the notices to be an array instead of string
					$notice = $notice['notice'];
				}
				if ( false !== strpos( $notice, 'wc-csr-countdown' ) ) {
					$this->expiration_notice_added = true;
					return true;
				}
			}
		}
		return false;
	}

	public function remove_expire_notice() {
		$entries_removed = 0;
		$wc_notices = wc_get_notices();
		foreach ( $wc_notices as $type => $notices ) {
			foreach ( $notices as $id => $notice ) {
				if ( is_array( $notice ) ) {
					// WooCommerce 3.9.1 changed the notices to be an array instead of string
					$notice = $notice['notice'];
				}
				if ( false !== strpos( $notice, 'wc-csr-countdown' ) ) {
					$entries_removed++;
					unset( $wc_notices[ $type ][ $id ] );
				}
			}
		}
		if ( $entries_removed > 0 ) {
			WC()->session->set( 'wc_notices', $wc_notices );
		}
		return $entries_removed;
	}

	/**
	 * Called by 'woocommerce_notice_types' filter to make sure any time a countdown is displayed the javascript is included
	 * @param $type
	 * @return mixed
	 */
	public function add_countdown_to_notice( $type ) {
		if ( $this->expire_notice_added() ) {
			$expire_soonest = $this->expire_items();
			$this->countdown( $expire_soonest );
		}
		return $type;
	}

	/**
	 * Called by 'woocommerce_check_cart_items' action to expire items from cart
	 */
	public function check_cart_items( ) {
		$expire_soonest = $this->expire_items();
		if ( 'always' !== $this->expire_countdown || 'POST' === strtoupper( $_SERVER[ 'REQUEST_METHOD' ] ) ) {
			// Return quickly when we don't care about notices
			return;
		}
		if ( 0 !== $expire_soonest && !$this->expire_notice_added()  ) {
			$item_expire_span = '<span class="wc-csr-countdown"></span>';
			$expire_notice_text = sprintf( _n( 'Please checkout within %s to guarantee your item does not expire.', 'Please checkout within %s to guarantee your items do not expire.', $this->num_expiring_items, 'woocommerce-cart-stock-reducer' ), $item_expire_span );
			$expiring_cart_notice = apply_filters( 'wc_csr_expiring_cart_notice', $expire_notice_text, $item_expire_span, $expire_soonest, $this->num_expiring_items );
			// With WooCommerce 3.x they remove and re-add notices when cart is updated so we are now manually including our own notice on the cart page
			echo "<div class='wc-csr-info'>$expiring_cart_notice</div>";
			$this->countdown( $expire_soonest );
		} elseif ( 0 === $expire_soonest ) {
			// Make sure a countdown notice is removed if there is not an item expiring
			$this->remove_expire_notice();
		}
	}

	/**
	 * Called by 'woocommerce_cart_loaded_from_session' action to expire items from cart
	 */
	public function check_expired_items( ) {
		$this->expire_items();
	}

	public function get_product_parent_id( $product ) {
		$product_id = null;
		if ( is_a( $product, 'WC_Product' ) ) {
			if ( is_a( $product, 'WC_Product_Variation' ) ) {
				$product_id = $product->get_parent_id();
			} else {
				$product_id = $product->get_id();
			}
		} elseif ( is_numeric( $product ) ) {
			$product_id = $product;
		} elseif ( is_array( $product ) && isset( $product['product_id'] ) ) {
			$product_id = $product['product_id'];
		}
		return $product_id;
	}

	public function is_reducer_enabled( $product = null ) {
		if ( 'never' === $this->cart_stock_reducer ) {
			// Never cannot be overridden so return quickly
			return false;
		}
		// Check for product override
		$product_id = $this->get_product_parent_id( $product );
		if ( $product_id ) {
			$override = get_post_meta( $product_id, '_csr_reducer_mode', true );
			if ( 'never' === $override ) {
				return false;
			} elseif ( 'always' === $override ) {
				return true;
			}
		}
		if ( !empty( $this->reducer_categories ) ) {
			// Categories can override anything but the global setting 'never'
			$cats = apply_filters( 'wc_csr_reducer_categories', explode(',', $this->reducer_categories ), $product_id );
			if ( has_term( $cats, 'product_cat', $product_id ) ) {
				return true;
			}
		}
		if ( 'yes' === $this->cart_stock_reducer ) {
			return true;
		}
		return false;
	}

	public function is_expiration_enabled( $product = null ) {
		if ( 'never' === $this->expire_items ) {
			// Never cannot be overridden so return quickly
			return false;
		}
		if ( is_array( $product ) && isset( $product['data'] ) ) {
			$product = $product['data'];
		}
		// Check for product override
		$product_id = $this->get_product_parent_id( $product );
		if ( $product_id ) {
			$override = get_post_meta( $product_id, '_csr_expire_mode', true );
			if ( 'never' === $override ) {
				return false;
			} elseif ( 'always' === $override ) {
				return true;
			}
		}
		if ( !empty( $this->expire_categories ) ) {
			// Categories can override anything but the global setting 'never'
			$cats = apply_filters( 'wc_csr_expire_categories', explode(',', $this->expire_categories ), $product_id );
			if ( has_term( $cats, 'product_cat', $product_id ) ) {
				return true;
			}
		}
		if ( 'all' === $this->expire_items ) {
			return true;
		} elseif ( 'yes' === $this->expire_items && $this->get_item_managing_stock( $product ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Expire items and returns the soonest time an item expires
	 * @return int Time when an item expires
	 */
	public function expire_items() {
		$expire_soonest = 0;
		$item_expiring_soonest = null;
		$num_expiring_items = 0;
		$cart = WC()->cart;
		if ( null === $cart ) {
			return;
		}
		$order_awaiting_payment = WC()->session->get( 'order_awaiting_payment', null );

		foreach ( $cart->cart_contents as $cart_id => $item ) {
			if ( isset( $item[ 'csr_expire_time' ] ) ) {
				if ( $this->is_expired( $item[ 'csr_expire_time' ], $order_awaiting_payment ) ) {
					// Item has expired
					$this->remove_expired_item( $cart_id, $cart );
				} elseif ( 0 === $expire_soonest || $item[ 'csr_expire_time' ] < $expire_soonest ) {
					// Keep track of the soonest expiration so we can notify
					$expire_soonest = $item[ 'csr_expire_time' ];
					$item_expiring_soonest = $cart_id;
					$num_expiring_items += $item[ 'quantity' ];
				} else {
					$num_expiring_items += $item[ 'quantity' ];
				}
			}

		}
		$this->num_expiring_items = $num_expiring_items;
		return $expire_soonest;

	}

	/**
	 * @param $cart_id
	 * @param null $cart
	 */
	protected function remove_expired_item( $cart_id, $cart = null ) {
		if ( null === $cart ) {
			// This should never happen, but better to be safe
			$cart = WC()->cart;
		}
		if ( !isset( $cart, $cart->cart_contents, $cart->cart_contents[ $cart_id ] ) ) {
			// If the cart items do not exist do not try to remove them.
			return;
		}
		if ( $this->is_expiration_enabled( $cart->cart_contents[ $cart_id ] ) ) {
			// remove whole container, not only one product inside
			$container = false;
			if ( function_exists('wc_pb_get_bundled_cart_item_container') ) {
				$container = wc_pb_get_bundled_cart_item_container( $cart->cart_contents[ $cart_id ], $cart->cart_contents, true );
			}
			// check composite after bundle, since it could include the bundle
			if ( function_exists('wc_cp_get_composited_cart_item_container') ) {
				$container_id = $container ? $cart->cart_contents[ $container ] : $cart->cart_contents[ $cart_id ];
				$composite = wc_cp_get_composited_cart_item_container( $container_id, $cart->cart_contents, true ); 
				$container = $composite ? $composite : $container;
			}

			if ( $container !== false ) {
				// Product is in container/composite
				$cart_id = $container;
				$item_description = $cart->cart_contents[ $cart_id ][ 'data' ]->get_title();
				$product = wc_get_product( $cart->cart_contents[ $cart_id ][ 'product_id' ] );
			} else {
				$item_description = $cart->cart_contents[ $cart_id ][ 'data' ]->get_title();
				if ( !empty( $cart->cart_contents[ $cart_id ][ 'variation_id' ] ) ) {
					$product = wc_get_product( $cart->cart_contents[ $cart_id ][ 'variation_id' ] );
					if ( method_exists( $product, 'wc_get_formatted_variation' ) ) {
						$item_description .= ' (' . $product->wc_get_formatted_variation( true ) . ')';
					}
				} else {
					$product = wc_get_product( $cart->cart_contents[ $cart_id ][ 'product_id' ] );
				}
			}
			// Include link to item removed during notice
			$item_description = '<a href="' . esc_url( $product->get_permalink() ) . '">' . $item_description . '</a>';
			$expired_cart_notice = apply_filters( 'wc_csr_expired_cart_notice', sprintf( __( "Sorry, '%s' was removed from your cart because you didn't checkout before the expiration time.", 'woocommerce-cart-stock-reducer' ), $item_description ), $cart_id, $cart );
			wc_add_notice( $expired_cart_notice, 'error' );
			do_action( 'wc_csr_before_remove_expired_item', $cart_id, $cart );
			$cart->remove_cart_item( $cart_id );
			WC()->session->set('cart', $cart->cart_contents);
		}

	}

	/**
	 * Called from the 'woocommerce_add_to_cart' action, to add a message/countdown to the page
	 *
	 * @param $cart_item_key
	 * @param $product_id
	 * @param $quantity
	 * @param $variation_id
	 * @param $variation
	 * @param $cart_item_data
	 */
	public function add_to_cart( $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data ) {

		if ( 'yes' === $this->refresh_items_add ) {
			// If enabled, refresh all cart items when a new item is added
			$this->adjust_cart_expiration();
		}

		// Force save asap instead of at shutdown
		WC()->session->save_data();
		$this->sessions->remove_cache_item( $product_id, $variation_id );

		if ( in_array( $this->expire_countdown, array( 'always', 'addonly') ) ) {
			$earliest_expiration_time = null;
			$number_items_expiring = 0;
			$cart = WC()->cart;
			foreach ( $cart->cart_contents as $cart_id => $item ) {
				if ( isset( $item[ 'csr_expire_time' ] ) ) {
					if ( $cart_item_key === $cart_id && ! $this->expire_notice_added() ) {
						$item_expire_span = '<span class="wc-csr-countdown"></span>';
						$this->countdown( $item['csr_expire_time'] );
						$this->item_expire_message = apply_filters( 'wc_csr_expire_notice', sprintf( __( 'Please checkout within %s or this item will be removed from your cart.', 'woocommerce-cart-stock-reducer' ), $item_expire_span ), $item_expire_span, $item['csr_expire_time'], $item['csr_expire_time_text'] );
					} else {
						if ( null === $earliest_expiration_time || $earliest_expiration_time < $item['csr_expire_time'] ) {
							$earliest_expiration_time = $item['csr_expire_time'];
							$number_items_expiring ++;
						}
					}
				}

			}
			if ( $number_items_expiring > 0 ) {
				$this->item_expire_message .= apply_filters( 'wc_csr_expire_notice_additional', sprintf( _n( ' There is %d item expiring sooner.', ' There are %d items expiring sooner.', $number_items_expiring, 'woocommerce-cart-stock-reducer' ), $number_items_expiring ), $cart_item_key, $number_items_expiring );
			}
		}
	}

	/**
	 * Called by 'wc_csr_adjust_cart_expiration' action to adjust the expiration times of the cart
	 *
	 * @param string $time Time string to reset item(s) to.  Default: Initial value per item
	 * @param string $cart_item_key Specific item to adjust expiration time on, Default: All items
	 */
	public function adjust_cart_expiration( $time = null, $cart_item_key = null ) {
		if ( $cart = WC()->cart ) {
			// Did we modify the cart
			$updated = false;

			foreach ($cart->cart_contents as $cart_id => $item) {
				if (isset($item['csr_expire_time'])) {
					if (isset($cart_item_key) && $cart_item_key !== $cart_id) {
						continue;
					}
					if ( empty( $time ) ) {
						$time = $item['csr_expire_time_text'];
					}
					$cart->cart_contents[$cart_id]['csr_expire_time'] = strtotime($time);
					$updated = true;
				}
			}
			if (true === $updated) {
				WC()->session->set('cart', $cart->cart_contents);
			}
		}
	}

	/**
	 * Called by 'woocommerce_get_undo_url' filter to change URL if item is managed
	 *
	 * @param string $url Default Undo URL
	 * @param null|string $cart_item_key Item key from users cart
	 *
	 * @return string URL for Undo link
	 */
	public function get_undo_url( $url, $cart_item_key = null ) {
		if ( null === $cart_item_key ) {
			$args = wp_parse_args( parse_url( $url, PHP_URL_QUERY ) );
			if ( isset( $args, $args[ 'undo_item' ] ) ) {
				$cart_item_key = $args[ 'undo_item' ];
			}
		}

		$cart = WC()->cart;
		if ( isset( $cart_item_key, $cart, $cart->removed_cart_contents[ $cart_item_key ] ) ) {
			$cart_item = $cart->removed_cart_contents[ $cart_item_key ];
			if ( false !== $this->get_item_managing_stock( null, $cart_item[ 'product_id' ], $cart_item[ 'variation_id' ] ) ) {
				// Only replace the URL if the item has managed stock
				$product = wc_get_product( empty( $cart_item[ 'variation_id' ] ) ? $cart_item[ 'product_id' ] : $cart_item[ 'product_id' ] );
				$url = $product->get_permalink();
			}
		}

		return $url;
	}

	/*
	 *
	 */
	public function product_available_variation( $var, $product, $variation ) {
		if ( ! $this->is_reducer_enabled( $product ) ) {
			return $var;
		}

		$field = $this->get_field_managing_stock( $variation );
		if ( 'product_id' === $field ) {
			// Stock is managed by main item ID
			$max_qty = $this->get_virtual_stock_available( $product, false );
		} else {
			$max_qty = $this->get_virtual_stock_available( $variation, false );
		}

		if ( $max_qty >= 0 ) {
			// Use the lowest of the max_qty, in case another plugin is reducing this number
			$var['max_qty'] = min( $var['max_qty'], $max_qty );
		}
		return $var;
	}

	/**
	 * Include a countdown timer
	 *
	 * @param int $time Time the countdown expires.  Seconds since the epoch
	 */
	protected function countdown( $time, $class = 'wc-csr-countdown' ) {
		if ( isset( $time ) ) {
			if ( empty( $this->countdown_seconds ) ) {
				// Only run this once per execution, in case we need to add more later
				add_action('wp_footer', array($this, 'countdown_footer'), 25);
				wp_enqueue_style( 'wc-csr-styles' );
				wp_enqueue_script('wc-csr-jquery-countdown');

				if ( $this->language ) {
					wp_enqueue_script( 'wc-csr-jquery-countdown-locale' );
				}
				$this->countdown_seconds[ $class ] = $time - time();
			}
		}

	}

	/**
	 * Called from the 'wp_footer' action when we want to add a footer
	 */
	public function countdown_footer() {
		if ( !empty( $this->countdown_seconds ) ) {
			// Don't add any more javascript code here, if it needs added to move it to an external file
			$code = '<script type="text/javascript">';
			$url = remove_query_arg( array( 'remove_item', 'removed_item', 'add-to-cart', 'added-to-cart' ) );
			foreach ( $this->countdown_seconds as $class => $time ) {
				// @TODO Fudge the count by one second, with recent optimizations it appears that the item expirations
				// are happening exactly on time and the items would display "Items expire in 0 seconds" and not refresh
				$time += 1;
				$code .= "jQuery('.{$class}').countdown({until: '+{$time}', format: 'dhmS', layout: '{d<}{dn} {dl} {d>}{h<}{hn} {hl} {h>}{m<}{mn} {ml} {m>}{s<}{sn} {sl}{s>}', expiryUrl: '{$url}'});";
			}
			$code .= '</script>';

			echo $code;
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
			if ( $this->is_expiration_enabled( $product ) && $managing_item = $this->get_item_managing_stock( $product ) ) {
				$expire_time_text = null;
				if ( ! empty( $this->expire_time ) ) {
					// Check global expiration time
					$expire_time_text = $this->expire_time;
				}
				$expire_custom_key = apply_filters( 'wc_csr_expire_custom_key', 'csr_expire_time', $item, $key );
				if ( ! empty( $expire_custom_key ) ) {
					// Check item specific expiration
					$item_expire_time = get_post_meta( $item[ 'product_id' ], $expire_custom_key, true );
					if ( ! empty( $item_expire_time ) ) {
						$expire_time_text = $item_expire_time;
					}
				}
				$expire_time_text = apply_filters( 'wc_csr_expire_time_text', $expire_time_text, $item, $key, $this );
				if ( null !== $expire_time_text && 'never' !== $expire_time_text ) {
					$item[ 'csr_expire_time' ] = apply_filters( 'wc_csr_expire_time', strtotime( $expire_time_text ), $expire_time_text, $item, $key, $this );
					$item[ 'csr_expire_time_text' ] = $expire_time_text;
				}
			}

		}
		return $item;
	}

	/**
	 * Called by the 'wc_add_to_cart_message' filter to append an internal message
	 * @param string $message
	 * @param int $product_id
	 *
	 * @return string
	 */
	function add_to_cart_message( $message, $product_id = null ) {
		if ( null != $this->item_expire_message ) {
			$message .= '  ' . $this->item_expire_message;
		}
		return $message;
	}

	/**
	 * Called via the 'woocommerce_update_cart_validation' filter to validate if the quantity can be updated
	 * The frontend should keep users from selecting a higher than allowed number, but don't trust those pesky users!
	 *
	 * @param $valid
	 * @param string $cart_item_key Specific key for the row in users cart
	 * @param array $values Item information
	 * @param int $quantity Quantity of item to be added
	 *
	 * @return bool true if quantity change to cart is valid
	 */
	public function update_cart_validation( $valid, $cart_item_key, $values, $quantity ) {
		$product = $values['data'];
		if ( ! $this->is_reducer_enabled( $product ) ) {
			return $valid;
		}
		$available = $this->get_virtual_stock_available( $product, true, false );
		if ( is_numeric( $available ) && $available < $quantity ) {
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
	public function add_cart_validation( $valid, $product_id, $quantity, $variation_id = null, $variations = array() ) {
		if ( ! $this->is_reducer_enabled( $product_id ) ) {
			return $valid;
		}
		if ( $item = $this->get_item_managing_stock( null, $product_id, $variation_id ) ) {
			$product = wc_get_product( $item );
			$backorders_allowed = $product->backorders_allowed();
			$stock = $product->get_stock_quantity( 'edit' );
			$available = $this->get_virtual_stock_available( $product, false, false );

			if ( true === $backorders_allowed ) {
				if ( $available < $quantity && $stock > 0 ) {
					$backorder_text = apply_filters( 'wc_csr_item_backorder_text', __( 'Item can not be backordered while there are pending orders', 'woocommerce-cart-stock-reducer' ), $product, $available, $stock );
					wc_add_notice( $backorder_text, 'error' );
					$valid = false;
				}
			} elseif ( $available < $quantity ) {
				if ( $available > 0 ) {
					wc_add_notice( sprintf( __( 'Quantity requested (%d) is no longer available, only %d available', 'woocommerce-cart-stock-reducer' ), $quantity, $available ), 'error' );
				} else {
					wc_add_notice( __( 'Item is no longer available', 'woocommerce-cart-stock-reducer' ), 'error' );
				}
				$valid = false;
			}

		}
		return $valid;
	}

	/**
	 * Determine which item is in control of managing the inventory
	 * @param object $product
	 * @param int $product_id
	 * @param int $variation_id
	 *
	 * @return bool|int
	 */
	public function get_item_managing_stock( $product = null, $product_id = null, $variation_id = null ) {
		$id = false;

		if ( !empty( $product ) ) {
			$managing_stock = $product->managing_stock();
			if ( 'parent' === $managing_stock ) {
				$id = $product->get_parent_id();
			} elseif ( true === $managing_stock ) {
				$id = $product->get_id();
			}
		} elseif ( ! empty( $variation_id ) ) {
			// First check variation
			$product        = wc_get_product( $variation_id );
			$managing_stock = $product->managing_stock();
			if ( 'parent' === $managing_stock ) {
				$id = $product->get_parent_id();
			} elseif ( true === $managing_stock ) {
				$id = $product->get_id();
			}
		} else {
			$product = wc_get_product( $product_id );
			if ( true === $product->managing_stock() ) {
				$id = $product->get_id();
			}
		}

		return $id;
	}

	/**
	 * Determine which field in DB to use for checking stock
	 * @param object $product
	 * @return string
	 */
	public function get_field_managing_stock( $product = null ) {
		$id = $this->get_item_managing_stock( $product );

		// @TODO verify this works on all variations
		$parent = $product->get_parent_id();
		if ( empty( $parent ) ) {
			$product_field = 'product_id';
		} else {
			if ( $id === $parent ) {
				$product_field = 'product_id';
			} else {
				$product_field = 'variation_id';
			}
		}

		return $product_field;
	}

	public function get_availability_text( $text, $product ) {
		if ( ! $this->is_reducer_enabled( $product ) ) {
			return $text;
		}

		$stock = $this->get_virtual_stock_available( $product );
		if ( isset( $stock ) && $stock <= 0 ) {
			if ( $product->backorders_allowed() ) {
				// If there are items in stock but backorders are allowed.  Only let backorders happen after existing
				// purchases have been completed or expired.  Otherwise the situation is too complicated.
				$text = apply_filters( 'wc_csr_stock_backorder_pending_text', $this->stock_pending, array(), $product );
			} elseif ( $product->backorders_allowed() && $product->backorders_require_notification() ) {
				$text = apply_filters( 'wc_csr_stock_backorder_notify_text', __( 'Available on backorder', 'woocommerce' ), array(), $product );
			} elseif ( $product->backorders_allowed() ) {
				$text = apply_filters( 'wc_csr_stock_backorder_text', __( 'In stock', 'woocommerce' ), array(), $product );
			} elseif ( ! empty( $this->stock_pending ) ) {
				// Override text via configurable option
				$text = apply_filters( 'wc_csr_stock_pending_text', $this->stock_pending, $text, $product );
			}
		}

		return $text;
	}

	public function get_availability_class( $class, $product ) {
		if ( ! $this->is_reducer_enabled( $product ) ) {
			return $class;
		}

		$stock = $this->get_virtual_stock_available( $product );

		if ( isset( $stock ) && $stock <= 0 ) {
			if ( $product->backorders_allowed() ) {
				// If there are items in stock but backorders are allowed.  Only let backorders happen after existing
				// purchases have been completed or expired.  Otherwise the situation is too complicated.
				$class = 'out-of-stock';
			} elseif ( $product->backorders_allowed() && $product->backorders_require_notification() ) {
				$class = 'available-on-backorder';
			} elseif ( $product->backorders_allowed() ) {
				$class = 'in-stock';
			} elseif ( ! empty( $this->stock_pending ) ) {
				$class = 'out-of-stock';
			}
		}

		return $class;
	}


	public function replace_stock_pending_text( $pending_text, $info = null, $product = null ) {

		if ( null != $product && $item = $this->get_item_managing_stock( $product ) ) {
			if ( !empty( $this->stock_pending_include_cart_items ) && $this->items_in_cart( $item ) ) {
				// Only append text if enabled and there are items actually in this users cart
				$pending_include_cart_items = str_ireplace( '%CSR_NUM_ITEMS%', $this->items_in_cart( $item ), $this->stock_pending_include_cart_items );
				$pending_text .= ' ' . $pending_include_cart_items;
			}

			if ( !empty( $this->stock_pending_expire_time ) && $this->expiration_time_cache( $item ) ) {
				if ( time() < $this->expiration_time_cache( $item ) ) {
					// Only append text if enabled and there are items that will expire
					$pending_expire_text = str_ireplace( '%CSR_EXPIRE_TIME%', human_time_diff( time(), $this->expiration_time_cache( $item ) ), $this->stock_pending_expire_time );
					// Was really hoping to use the jquery countdown here but the default WooCommerce templates
					// call esc_html so I can't easily include a class here :(
					$pending_text .= ' ' . $pending_expire_text;
				}
			}
		}

		return $pending_text;
	}

	private function expiration_time_cache( $item_id ) {
		$earliest = false;
		if ( $items = $this->sessions->find_items_in_carts( $item_id ) ) {
			$customer_id = $this->get_customer_id();
			foreach ( $items as $cart_id => $cart ) {
				if ( $customer_id == $cart_id ) {
					// Skip customers own items
					continue;
				}
				foreach ( $cart as $cart_item ) {
					if ( isset( $cart_item['csr_expire_time'] ) ) {
						if ( $this->is_expired( $cart_item['csr_expire_time'] ) ) {
							# Ignore expired items
							continue;
						}
						if ( false === $earliest || $cart_item['csr_expire_time'] < $earliest ) {
							$earliest = $cart_item['csr_expire_time'];
						}
					}
				}
			}
		}

		return $earliest;
	}

	private function items_in_cart( $item_id ) {
		$count = 0;
		if ( $cart = WC()->cart ) {
			foreach ($cart->cart_contents as $cart_id => $item) {
				if ( $item_id === $item[ 'product_id' ] || $item_id === $item[ 'variation_id' ] ) {
					$count += $item[ 'quantity' ];
				}
			}
		}
		return $count;
	}

	/**
	 * Get the quantity available of a specific item
	 *
	 * @param object $product WooCommerce WC_Product based class, if not passed the item ID will be used to query
	 * @param string $ignore Cart Item Key to ignore in the count
	 * @param bool $use_cache true if we should use cached data, false will force DB query
	 *
	 * @return int Quantity of items in stock
	 */
	public function get_virtual_stock_available( $product = null, $ignore = false, $use_cache = true ) {
		$stock = 0;

		$id = $this->get_item_managing_stock( $product );

		if ( false === $id ) {
			// Item is not a managed item, do not return quantity
			return null;
		}

		if ( false !== apply_filters( 'wc_csr_set_nocache', true, $product, $id ) ) {
			// Make sure this page is not cached
			WC_Cache_Helper::set_nocache_constants();
		}

		// Increase virtual depth count which is used to keep from double counting items in cart
		$this->virtual_depth++;

		$stock = $product->get_stock_quantity();

		if ( $stock > 0 && $this->virtual_depth <= 1 ) {
			$product_field = $this->get_field_managing_stock( $product );

			// The minimum quantity of stock to have in order to skip checking carts.  This should be higher than the amount you expect could sell before the carts expire.
			// Originally was a configuration variable, but this is such an advanced option I thought it would be better as a filter.
			// Plus you can use some math to make this decision
			$min_no_check = apply_filters( 'wc_csr_min_no_check', false, $id, $stock );
			if ( false != $min_no_check && $min_no_check < (int) $stock ) {
				// Don't bother searching through all the carts if there is more than 'min_no_check' quantity
				$this->virtual_depth--;
				return $stock;
			}

			$in_carts = $this->sessions->quantity_in_carts( $id, $product_field, $ignore, $use_cache );
			if ( 0 < $in_carts ) {
				$stock = ( $stock - $in_carts );
			}
		} else {
			// Item is actually not in stock, returning null keeps us from trying to handle the product
			$stock = null;
		}
		$this->virtual_depth--;
		return $stock;
	}

	/**
	 * Get the actual quantity available of a specific item
	 *
	 * @param object $product WooCommerce WC_Product based class, if not passed the item ID will be used to query
	 *
	 * @return int Quantity of items in stock
	 */
	public function get_actual_stock_available( $product = null ) {
		// Increase virtual depth count which is used to keep from double counting items in cart
		$this->virtual_depth++;

		$stock = $product->get_stock_quantity();

		$this->virtual_depth--;
		return $stock;
	}


	public function product_get_stock_status( $status, $product ) {
		if ( ! $this->is_reducer_enabled( $product ) ) {
			return $status;
		}

		if ( is_cart() || is_checkout() ) {
			$ignore = true;
		} else {
			$ignore = false;
		}

		// Make sure backend admin always shows real status
		$contains_functions = array( 'render_product_columns', 'render_is_in_stock_column', 'reserve_stock_for_order' );

		if ( false === apply_filters( 'wc_csr_hide_out_of_stock_items', false, $this, $status, $product ) ) {
			// If this is a product visibility check, don't check virtual status
			$contains_functions[] = 'is_visible';
		}

		if ( $this->trace_contains( apply_filters( 'wc_csr_whitelist_get_stock_status', $contains_functions, $status, $product ) ) ) {
			return $status;
		}

		$virtual_stock = $this->get_virtual_stock_available( $product, $ignore );
		if ( isset( $virtual_stock ) && $virtual_stock <= 0 ) {
				$status = 'outofstock';
		}
		return $status;
	}

	public function product_get_stock_quantity( $quantity, $product ) {
		if ( ! $this->is_reducer_enabled( $product ) ) {
			return $quantity;
		}

		if ( false === $this->checking_virtual_stock ) {
			$never_virtual_whitelist = array( 'wc_reduce_stock_levels', 'render_product_columns', 'validate_props', 'render_is_in_stock_column', 'render_name_column', 'bulk_edit_save' );
			if ( $this->trace_contains( apply_filters( 'wc_csr_whitelist_get_stock_quantity', $never_virtual_whitelist, $quantity, $product ) ) ) {
				// For WooCommerce 3.x we need to make sure we return the real quantity to these functions
				// otherwise they mark items as out of stock
				return $quantity;
			}
			// Safety net to stop any potential recursion
			$this->checking_virtual_stock = true;
			if ( is_cart() || is_checkout() || $this->trace_contains( array( 'has_enough_stock' ) ) ) {
				$ignore = true;
			} else {
				$ignore = false;
			}
			$virtual_stock = $this->get_virtual_stock_available( $product, $ignore );
			$this->checking_virtual_stock = false;
			if ( null !== $virtual_stock ) {
				return $virtual_stock;
			}
		}
		return $quantity;
	}


	/**
	 * This is an ugly hack to help us deal with the few edge cases where WooCommerce calls get_stock_quantity() but
	 * we have no way of catching if we should give them real or virtual stock.
	 * @param array $haystack
	 *
	 * @return bool
	 */
	protected function trace_contains( $haystack = array() ) {
		$trace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS );
		foreach ( $trace as $id => $frame ) {
			if ( in_array( $frame[ 'function' ], $haystack ) ) {
				return true;
			}
		}
		return false;
	}

	function get_customer_id() {
		$WC = WC();
		if ( isset( $WC, $WC->session ) ) {
			// A user report a fatal error when trying to call get_customer_id.
			// Even though it was likely some other plugin/themes fault, lets play safely.
			$customer_id = $WC->session->get_customer_id();
		} else {
			$customer_id = null;
		}
		return $customer_id;
	}

	/**
	 * Check if $expire_time has passed
	 *
	 * @param int|string $expire_time UNIX timestamp for expiration or 'never' if item never expires
	 * @param int $order_awaiting_payment WooCommerce Order ID of order associated with session
	 *
	 * @return bool true if expired
	 */
	public function is_expired( $expire_time = 'never', $order_awaiting_payment = null ) {
		$expired = false;
		try {
			if ( null !== $order_awaiting_payment && ( $order = new WC_Order( $order_awaiting_payment ) ) ) {
				$post_status = $order->get_status();
				// If a session is marked with an Order ID in 'order_awaiting_payment' check the status to decide if we should skip the expiration check
				if ( in_array( $post_status, apply_filters( 'wc_csr_expire_ignore_status', $this->ignore_status, $post_status, $expire_time, $order_awaiting_payment ) ) ) {
					return false;
				}
			}
		} catch ( Exception $e ) {
			// WC_Order throws an Exception if you try to check for an order that doesn't exist
		}
		if ( 'never' === $expire_time ) {
			// This should never happen, but better to be safe
			$expired = false;
		} elseif ( $expire_time < time() ) {
			$expired = true;
		}
		return $expired;
	}


	/**
	 * Validate and sanitize the 'expire_time_field'
	 *
	 * @param string $key Field name to validate
	 *
	 * @return string
	 */
	public function validate_expire_time_field( $key ) {
		$expire_time = sanitize_text_field( $_POST[ $this->plugin_id . $this->id . '_' . $key ] );
		$expire_enabled_key = $this->plugin_id . $this->id . '_expire_items';
		$expire_enabled = isset( $_POST[ $expire_enabled_key ] ) ? absint( $_POST[ $expire_enabled_key ] ) : 0;

		if ( !empty( $expire_time ) ) {
			$time = strtotime( $expire_time );
			if ( ! $time ) {
				$this->errors[] = sprintf( __( 'Invalid Expire Time: %s', 'woocommerce-cart-stock-reducer' ), $expire_time );
			} elseif ( false !== $time && $time < time() ) {
				$this->errors[] = sprintf( __( 'Cannot set Expire Time that would be in the past: %s', 'woocommerce-cart-stock-reducer' ), $expire_time );
			}
		} elseif ( 1 === $expire_enabled ) {
			$this->errors[] = sprintf( __( 'Expire time must be set if expiration is enabled', 'woocommerce-cart-stock-reducer' ) );
		}

		return $expire_time;
	}

	/**
	 * Display errors by overriding the display_errors() method
	 * @see display_errors()
	 */
	public function display_errors( ) {

		// loop through each error and display it
		foreach ( $this->errors as $key => $value ) {
			?>
			<div class="error">
				<p><?php echo $value ?></p>
			</div>
		<?php
		}
	}

	public function init_form_fields() {
		$this->form_fields = array(
			'cart_stock_reducer' => array(
				'title'             => __( 'Which Items to Reduce Stock', 'woocommerce-cart-stock-reducer' ),
				'type'              => 'select',
				'default'           => 'yes',
				'options'           => array(
					'yes' => __( 'All Managed Items', 'woocommerce-cart-stock-reducer' ),
					'no' => __( 'Do not reduce stock of items (Can be overridden per item)', 'woocommerce-cart-stock-reducer' ),
					'never' => __( 'Never reduce stock (Cannot be overridden per item)', 'woocommerce-cart-stock-reducer' ),),
			),
			// @TODO I want to hide the following unless 'no' is selected above
			'reducer_categories' => array(
				'title'             => __( 'Reducer Categories', 'woocommerce-cart-stock-reducer' ),
				'type'              => 'text',
				'description'       => __( 'Comma separated list of WordPress categories to select items that will be reduced.  Empty means follow system default. ', 'woocommerce-cart-stock-reducer' ),
				'desc_tip'          => false,
				'default'           => ''
			),
			'expire_items' => array(
				'title'             => __( 'Which Items Should Expire From Carts', 'woocommerce-cart-stock-reducer' ),
				'type'              => 'select',
				'default'           => 'no',
				'options'           => array( 'no' => __( 'Expiration Disabled By Default', 'woocommerce-cart-stock-reducer' ),
				                              'yes' => __( 'Expire Only Managed Items', 'woocommerce-cart-stock-reducer' ),
				                              'all' => __( 'Expire All Items', 'woocommerce-cart-stock-reducer' ),
				                              'never' => __( 'Never Expire Any Items (Cannot be overridden)', 'woocommerce-cart-stock-reducer' ),
				),
				'description'       => __( "You MUST set an 'Expire Time' below if you use this option", 'woocommerce-cart-stock-reducer' ),
			),
			'expire_time' => array(
				'title'             => __( 'Expire Time', 'woocommerce-cart-stock-reducer' ),
				'type'              => 'text',
				'description'       => __( 'How long before item expires from cart', 'woocommerce-cart-stock-reducer' ),
				'desc_tip'          => true,
				'placeholder'       => 'Examples: 10 minutes, 1 hour, 6 hours, 1 day',
				'default'           => ''
			),
			// @TODO I want to hide the following unless 'no' is selected above
			'expire_categories' => array(
				'title'             => __( 'Expire Categories', 'woocommerce-cart-stock-reducer' ),
				'type'              => 'text',
				'description'       => __( 'Comma separated list of WordPress categories to select items that will expire.  Empty means follow system default. ', 'woocommerce-cart-stock-reducer' ),
				'desc_tip'          => false,
				'default'           => ''
			),

			'expire_countdown' => array(
				'title'             => __( 'Expire Countdown', 'woocommerce-cart-stock-reducer' ),
				'type'              => 'select',
				'label'             => __( 'Enable Expiration Countdown', 'woocommerce-cart-stock-reducer' ),
				'default'           => 'always',
				'options'           => array( 'always' => __( 'Always', 'woocommerce-cart-stock-reducer' ),
											  'addonly' => __( 'Only when items are added', 'woocommerce-cart-stock-reducer' ),
											  'never' => __( 'Never', 'woocommerce-cart-stock-reducer' ) ),
				'description'       => __( 'When to display a countdown to expiration', 'woocommerce-cart-stock-reducer' ),
			),
			'stock_pending' => array(
				'title'             => __( 'Pending Order Text', 'woocommerce-cart-stock-reducer' ),
				'type'              => 'text',
				'description'       => __( 'Enter alternate text to be displayed when there are items in stock but held in an existing cart.', 'woocommerce-cart-stock-reducer' ),
				'desc_tip'          => true,
				'default'           => __( "This item is not available at this time due to pending orders.", 'woocommerce-cart-stock-reducer' ),
			),
			'stock_pending_expire_time' => array(
				'title'             => __( 'Append Expiration Time to Pending Order Text', 'woocommerce-cart-stock-reducer' ),
				'type'              => 'text',
				'description'       => sprintf( __( 'Enter text to be appended when there are items in stock but held in an existing cart. %s will be replaced with a countdown to when items might be available.', 'woocommerce-cart-stock-reducer' ), '%CSR_EXPIRE_TIME%' ),
				'desc_tip'          => false,
				'default'           => sprintf( __( 'Check back in %s to see if items become available.', 'woocommerce-cart-stock-reducer' ), '%CSR_EXPIRE_TIME%' ),
			),
			'stock_pending_include_cart_items' => array(
				'title'             => __( 'Append Included Items to Pending Order Text', 'woocommerce-cart-stock-reducer' ),
				'type'              => 'text',
				'description'       => sprintf( __( 'Enter text to be appended when the there are pending items in the users cart. %s will be replaced with the number of items in cart.', 'woocommerce-cart-stock-reducer' ), '%CSR_NUM_ITEMS%' ),
				'desc_tip'          => false,
				'default'           => sprintf( __( 'Pending orders include %s items already added to your cart.', 'woocommerce-cart-stock-reducer' ), '%CSR_NUM_ITEMS%' ),
			),
			'ignore_status' => array(
				'title'             => __( 'Ignore Order Status', 'woocommerce-cart-stock-reducer' ),
				'type'              => 'multiselect',
				'default'           => array(),
				'options'           => wc_get_order_statuses(),
				'description'       => __( '(Advanced Setting) WooCommerce order status that prohibit expiring items from cart', 'woocommerce-cart-stock-reducer' ),
			),
			'refresh_items_add' => array(
				'title'             => __( 'Refresh Item Expiration Time', 'woocommerce-cart-stock-reducer' ),
				'type'              => 'checkbox',
				'label'             => __( 'Refresh all items expiration time, when items are added to cart', 'woocommerce-cart-stock-reducer' ),
				'default'           => 'no',
			),
			'refresh_items_cart' => array(
				'type'              => 'checkbox',
				'label'             => __( 'Refresh all items expiration time, when cart page is loaded', 'woocommerce-cart-stock-reducer' ),
				'default'           => 'no',
			),
			'refresh_items_checkout' => array(
				'type'              => 'checkbox',
				'label'             => __( 'Refresh all items expiration time, when checkout page is loaded', 'woocommerce-cart-stock-reducer' ),
				'default'           => 'no',
			),
			'refresh_items_checkout_pay' => array(
				'type'              => 'checkbox',
				'label'             => __( 'Refresh all items expiration time, when checkout payment page is loaded', 'woocommerce-cart-stock-reducer' ),
				'default'           => 'no',
			),



		);
	}


}
