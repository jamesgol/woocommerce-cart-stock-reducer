<?php

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

class WC_Cart_Stock_Reducer extends WC_Integration {

	private $expiration_time_cache = array();

	// Variables used for specific session
	private $item_expire_message = null;
	private $countdown_seconds   = array();
	private $expiration_notice_added = false;
	private $language = null;
	private $num_expiring_items = 0;

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
		$this->expire_time         = $this->get_option( 'expire_time' );
		$this->ignore_status       = $this->get_option( 'ignore_status', array() );

		// Actions/Filters to setup WC_Integration elements
		add_action( 'woocommerce_update_options_integration_' .  $this->id, array( $this, 'process_admin_options' ) );
		// @todo Add admin interface validation/sanitation

		// Filters related to stock quantity
		if ( 'yes' === $this->cart_stock_reducer ) {
			add_filter( 'woocommerce_get_availability', array( $this, 'get_avail' ), 10, 2 );
			add_filter( 'woocommerce_update_cart_validation', array( $this, 'update_cart_validation' ), 10, 4 );
			add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'add_cart_validation' ), 10, 5 );
			add_filter( 'woocommerce_quantity_input_args', array( $this, 'quantity_input_args' ), 10, 2 );
			add_filter( 'wc_csr_stock_pending_text', array( $this, 'replace_stock_pending_text' ), 10, 3 );
			add_filter( 'woocommerce_add_to_cart_redirect', array( $this, 'force_session_save' ), 10 );
			add_action( 'wc_csr_adjust_cart_expiration', array( $this, 'adjust_cart_expiration' ), 10, 2 );
			add_filter( 'woocommerce_get_undo_url', array( $this, 'get_undo_url' ), 10, 2 );
			add_filter( 'woocommerce_product_is_in_stock', array( $this, 'product_is_in_stock' ), 10, 2 );
			add_filter( 'woocommerce_variation_is_in_stock', array( $this, 'product_is_in_stock' ), 10, 2 );
			add_filter( 'woocommerce_available_variation', array( $this, 'product_available_variation' ), 10, 3 );
			add_filter( 'woocommerce_get_stock_quantity', array( $this, 'pre_get_stock_available' ), 10, 2 );
		}

		// Actions/Filters related to cart item expiration
		if ( ! is_admin() || defined( 'DOING_AJAX' ) && ! defined( 'DOING_CRON' ) ) {
			add_action( 'woocommerce_add_to_cart', array( $this, 'add_to_cart' ), 10, 6 );
			add_filter( 'woocommerce_add_cart_item', array( $this, 'add_cart_item' ), 10, 2 );
			add_action( 'woocommerce_cart_loaded_from_session', array( $this, 'check_expired_items' ), 10 );
			add_filter( 'woocommerce_notice_types', array( $this, 'add_countdown_to_notice' ), 10 );
			add_filter( 'wc_add_to_cart_message', array( $this, 'add_to_cart_message' ), 10, 2 );
			// Some Third-Party themes do not call 'woocommerce_before_main_content' action so let's call it on other likely actions
			add_action( 'woocommerce_before_single_product', array( $this, 'check_cart_items' ), 9 );
			add_action( 'woocommerce_check_cart_items', array( $this, 'check_cart_items' ), 9 );
			add_action( 'woocommerce_before_shop_loop', array( $this, 'check_cart_items' ), 9 );

		}

		wp_register_script( 'wc-csr-jquery-countdown', $this->plugins_url . 'assets/js/jquery-countdown/jquery.countdown.min.js', array( 'jquery', 'wc-csr-jquery-plugin' ), '2.0.2', true );
		wp_register_script( 'wc-csr-jquery-plugin', $this->plugins_url . 'assets/js/jquery-countdown/jquery.plugin.min.js', array( 'jquery' ), '2.0.2', true );
		add_action( 'init', array( $this, 'load_plugin_textdomain' ) );
		
		// Direct link to our settings page
		add_filter( 'plugin_action_links', array( $this, 'action_links' ), 10, 2 );

	}

	public function load_plugin_textdomain() {
		load_plugin_textdomain( 'woocommerce-cart-stock-reducer', false, plugin_basename( $this->plugin_dir ) . '/languages/' );
		$this->language = $this->find_countdown_language( apply_filters( 'wc_csr_countdown_locale', get_locale() ) );
		if ( $this->language ) {
			wp_register_script( 'wc-csr-jquery-countdown-locale', $this->plugins_url . "assets/js/jquery-countdown/jquery.countdown-{$this->language}.js", array( 'jquery',	'wc-csr-jquery-plugin',	'wc-csr-jquery-countdown' ), '2.0.2', true );
		}

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
			$file = $this->plugin_dir . '/assets/js/jquery-countdown/jquery.countdown-' . $lang . '.js';
			if ( file_exists( $file ) ) {
				return $lang;
			} elseif ( $part = substr( $lang, 0, strpos( $lang, '-' ) ) ) {
				$file = $this->plugin_dir . '/assets/js/jquery-countdown/jquery.countdown-' . $part . '.js';
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
	 * Called from hook 'woocommerce_add_to_cart_redirect', odd choice but it gets called after a succesful save of an item
	 * Need to force the session to be saved so the quantity can be correctly calculated for the page load in this same session
	 */
	public function force_session_save( $default ) {
		WC()->session->save_data();
		return $default;
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
		if ( 'quantity' === $args[ 'input_name' ] ) {
			$ignore = false;
		} else {
			// Ignore users quantity when looking at pages like the shopping cart
			$ignore = true;
		}
		$args[ 'max_value' ] = $this->get_stock_available( $product->id, $product->variation_id, $product, $ignore );

		return $args;
	}

	public function expire_notice_added() {
		if ( true === $this->expiration_notice_added ) {
			// Don't loop through notices if we already know it has been added
			return true;
		}
		foreach ( wc_get_notices() as $type => $notices ) {
			foreach ( $notices as $notice ) {
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
			$item_expire_span = '<span id="wc-csr-countdown"></span>';
			$expire_notice_text = sprintf( _n( 'Please checkout within %s to guarantee your item does not expire.', 'Please checkout within %s to guarantee your items do not expire.', $this->num_expiring_items, 'woocommerce-cart-stock-reducer' ), $item_expire_span );
			$expiring_cart_notice = apply_filters( 'wc_csr_expiring_cart_notice', $expire_notice_text, $item_expire_span, $expire_soonest, $this->num_expiring_items );
			wc_add_notice( $expiring_cart_notice, 'notice' );

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
		if ( 'yes' === $this->expire_items ) {
			$item_description = $cart->cart_contents[ $cart_id ][ 'data' ]->get_title();
			if ( isset( $cart->cart_contents[ $cart_id ][ 'variation_id' ] ) ) {
				$product = wc_get_product( $cart->cart_contents[ $cart_id ][ 'variation_id' ] );
				if ( method_exists( $product, 'get_formatted_variation_attributes' ) ) {
					$item_description .= ' (' . $product->get_formatted_variation_attributes( true ) . ')';
				}
			}

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
		if ( in_array( $this->expire_countdown, array( 'always', 'addonly') ) ) {
			$earliest_expiration_time = null;
			$number_items_expiring = 0;
			$cart = WC()->cart;
			foreach ( $cart->cart_contents as $cart_id => $item ) {
				if ( isset( $item[ 'csr_expire_time' ] ) ) {
					if ( $cart_item_key === $cart_id && ! $this->expire_notice_added() ) {
						$item_expire_span = '<span id="wc-csr-countdown"></span>';
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
			if ( false !== $this->item_managing_stock( $cart_item[ 'product_id' ], $cart_item[ 'variation_id' ] ) ) {
				// Only replace the URL if the item has managed stock
				$product = wc_get_product( empty( $cart_item[ 'variation_id' ] ) ? $cart_item[ 'product_id' ] : $cart_item[ 'product_id' ] );
				$url = $product->get_permalink();
			}
		}

		return $url;
	}


	function product_is_in_stock( $status, $product = null ) {
		if ( null === $product ) {
			global $product;
		}

		if ( is_a( $product, 'WC_Product' ) && $item = $this->item_managing_stock( $product->id, $product->variation_id ) ) {
			$available = $this->get_stock_available( $product->id, $product->variation_id, $product );
			if ( $available <= 0 && !empty( $product->total_stock ) ) {
				return false;
			}
		}

		return $status;
	}

	/*
	 *
	 */
	function product_available_variation( $var, $product, $variation ) {
		if ( version_compare( WC_VERSION, '2.7', '<' ) ) {
			// WooCommerce < 2.7 does not have the 'woocommerce_variation_is_in_stock', so this hack produces similar results
			if ( true === $var['is_in_stock'] && false !== strpos( $var['availability_html'], 'out-of-stock' ) ) {
				$var['is_in_stock'] = false;
			}
		}

		$max_qty = $this->get_stock_available( $product->id, $variation->variation_id, $variation, false );
		if ( $max_qty >= 0 ) {
			$var['max_qty'] = $max_qty;
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
				$code .= "jQuery('#{$class}').countdown({until: '+{$time}', format: 'dhmS', layout: '{d<}{dn} {dl} {d>}{h<}{hn} {hl} {h>}{m<}{mn} {ml} {m>}{s<}{sn} {sl}{s>}', expiryUrl: '{$url}'});";
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
			if ( 'yes' === $this->expire_items && $this->item_managing_stock( $item['product_id'], $item['variation_id'] ) ) {
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
				if ( null !== $expire_time_text && 'never' !== $expire_time_text ) {
					$item[ 'csr_expire_time' ] = strtotime( $expire_time_text );
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
		$available = $this->get_stock_available( $values[ 'product_id' ], $values[ 'variation_id' ], $values[ 'data' ], true );
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
		if ( $item = $this->item_managing_stock( $product_id, $variation_id ) ) {
			$available = $this->get_stock_available( $product_id, $variation_id );
			$product = wc_get_product( $item );
			$backorders_allowed = $product->backorders_allowed();
			$stock = $product->get_total_stock();
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
	 * @param int $product_id
	 * @param int $variation_id
	 *
	 * @return bool|int
	 */
	public function item_managing_stock( $product_id, $variation_id = null ) {
		$id = false;

		if ( ! empty( $variation_id ) ) {
			// First check variation
			$product = wc_get_product( $variation_id );
			$managing_stock = $product->managing_stock();
			if ( true === $managing_stock ) {
				$id = $variation_id;
			} elseif ( 'parent' === $managing_stock ) {
				$id = $product_id;
			}
		} else {
			$product = wc_get_product( $product_id );
			if ( true === $product->managing_stock() ) {
				$id = $product_id;
			}
		}

		return $id;
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

		$item = $this->item_managing_stock( $product->id, $product->variation_id );

		if ( $item && ( 'out-of-stock' === $info[ 'class' ] || 'in-stock' === $info[ 'class' ] ) ) {
			$available = $this->get_stock_available( $product->id, $product->variation_id, $product );

			if ( 'in-stock' === $info[ 'class' ] && $available > 0 ) {
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
				if ( $product->backorders_allowed() && $product->get_total_stock() > 0 ) {
					// If there are items in stock but backorders are allowed.  Only let backorders happen after existing
					// purchases have been completed or expired.  Otherwise the situation is too complicated.
					$info[ 'availability' ] = apply_filters( 'wc_csr_stock_backorder_pending_text', $this->stock_pending, $info, $product );
					$info[ 'class' ]        = 'out-of-stock';
				} elseif ( $product->backorders_allowed() && $product->backorders_require_notification() ) {
					$info[ 'availability' ] = apply_filters( 'wc_csr_stock_backorder_notify_text', __( 'Available on backorder', 'woocommerce' ), $info, $product );
					$info[ 'class' ]        = 'available-on-backorder';
				} elseif ( $product->backorders_allowed() ) {
					$info[ 'availability' ] = apply_filters( 'wc_csr_stock_backorder_text', __( 'In stock', 'woocommerce' ), $info, $product );
					$info[ 'class' ]        = 'in-stock';
				} elseif ( ! empty( $this->stock_pending ) && 'outofstock' !== $product->stock_status ) {
					// Override text via configurable option
					$info['availability'] = apply_filters( 'wc_csr_stock_pending_text', $this->stock_pending, $info, $product );
					$info['class']        = 'out-of-stock';
				} else	{
					$info[ 'availability' ] = __( 'Out of stock', 'woocommerce' );
					$info[ 'class' ]        = 'out-of-stock';
				}
			}
		}

		return $info;
	}

	public function replace_stock_pending_text( $pending_text, $info = null, $product = null ) {

		if ( null != $product && $item = $this->item_managing_stock( $product->id, $product->variation_id ) ) {
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
		if ( isset( $this->expiration_time_cache[ $item_id ] ) ) {
			return $this->expiration_time_cache[ $item_id ];
		}
		return false;
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
	 * @param int $item The item ID
	 * @param object $product WooCommerce WC_Product based class, if not passed the item ID will be used to query
	 * @param string $ignore Cart Item Key to ignore in the count
	 *
	 * @return int Quantity of items in stock
	 */
	public function get_stock_available( $product_id, $variation_id = null, $product = null, $ignore = false ) {
		$stock = 0;

		$id = $this->item_managing_stock( $product_id, $variation_id );

		if ( false === $id ) {
			// Item is not a managed item, do not return quantity
			return null;
		}

		if ( null === $product ) {
			$product = wc_get_product( $id );
		}
		$stock = get_post_meta( $product_id, '_stock', true );

		if ( $stock > 0 ) {
			if ( $id === $variation_id ) {
				$product_field = 'variation_id';
			} else {
				$product_field = 'product_id';
			}

			// The minimum quantity of stock to have in order to skip checking carts.  This should be higher than the amount you expect could sell before the carts expire.
			// Originally was a configuration variable, but this is such an advanced option I thought it would be better as a filter.
			// Plus you can use some math to make this decision
			$min_no_check = apply_filters( 'wc_csr_min_no_check', false, $id, $stock );
			if ( false != $min_no_check && $min_no_check < (int) $stock ) {
				// Don't bother searching through all the carts if there is more than 'min_no_check' quantity
				return $stock;
			}

			$in_carts = $this->quantity_in_carts( $id, $product_field, $ignore );
			if ( 0 < $in_carts ) {
				$stock = ( $stock - $in_carts );
			}
		}
		return $stock;
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
		global $wpdb;
		$quantity = 0;
		$item = (int) $item;

		/* This should be the most efficient way to do this, though the proper way
		 * would be to retrieve a list of all the session and use get_option or
		 * the WooCommerce API to iterate through the sessions.  Though it is possible
		 * that using get_option and an external cache will be faster on a heavy site.
		 * Need to benchmark... In my free time.
    	*/
		if ( version_compare( WOOCOMMERCE_VERSION, '2.5' ) >= 0 ) {
			// WooCommerce >= 2.5 stores session data in a separate table
			$results = $wpdb->get_results( "SELECT session_key, session_value FROM {$wpdb->prefix}woocommerce_sessions WHERE session_value LIKE '%\"{$field}\";i:{$item};%'", OBJECT );
		} else {
			$results = $wpdb->get_results( "SELECT option_name, option_value as session_value FROM {$wpdb->options} WHERE option_name LIKE '_wc_session_%' AND option_value LIKE '%\"{$field}\";i:{$item};%'", OBJECT );
		}

		if ( $results ) {
			$WC = WC();
			if ( isset( $WC, $WC->session ) ) {
				// A user report a fatal error when trying to call get_customer_id.
				// Even though it was likely some other plugin/themes fault, lets play safely.
				$customer_id = $WC->session->get_customer_id();
			} else {
				$customer_id = null;
			}
			foreach ( $results as $result ) {
				if ( null !== $customer_id &&
					( isset( $result->session_key ) && $result->session_key === $customer_id ) ||
					( isset( $result->option_name ) && $result->option_name === '_wc_session_' . $customer_id ) ) {
					$row_in_own_cart = true;
				} else {
					$row_in_own_cart = false;
				}
				if ( true === $ignore && true === $row_in_own_cart ) {
					continue;
				}
				$session = unserialize( $result->session_value );
				if ( isset( $session[ 'cart' ] ) ) {
					$cart = unserialize( $session[ 'cart' ] );
					foreach ( $cart as $key => $row ) {
						if ( isset( $row[ 'csr_expire_time' ] ) && $this->is_expired( $row[ 'csr_expire_time' ], isset( $session[ 'order_awaiting_payment' ] ) ? $session[ 'order_awaiting_payment' ] : null ) ) {
							// Skip items that are expired in carts
							continue;
						}
						if ( $item === $row[ $field ] ) {
							$quantity += $row[ 'quantity' ];
						}
						if ( isset( $row[ 'csr_expire_time' ] ) && false === $row_in_own_cart ) {
							// Don't track expiration time of items in the users own cart
							if ( ! isset( $this->expiration_time_cache[ $item ] ) || $row[ 'csr_expire_time' ] < $this->expiration_time_cache[ $item ] ) {
								// Cache the earliest time an item is expiring
								$this->expiration_time_cache[ $item ] = $row[ 'csr_expire_time' ];
							}
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
	 * @param int $order_awaiting_payment WooCommerce Order ID of order associated with session
	 *
	 * @return bool true if expired
	 */
	protected function is_expired( $expire_time = 'never', $order_awaiting_payment = null ) {
		$expired = false;
		if ( null !== $order_awaiting_payment && ( $order = new WC_Order( $order_awaiting_payment ) ) ) {
			// If a session is marked with an Order ID in 'order_awaiting_payment' check the status to decide if we should skip the expiration check
			if ( in_array( $order->post_status, apply_filters( 'wc_csr_expire_ignore_status', $this->ignore_status, $order->post_status, $expire_time, $order_awaiting_payment ) ) ) {
				return false;
			}
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
				'title'             => __( 'Cart Stock Reducer', 'woocommerce-cart-stock-reducer' ),
				'type'              => 'checkbox',
				'label'             => __( 'Enable Cart Stock Reducer', 'woocommerce-cart-stock-reducer' ),
				'default'           => 'yes',
				'description'       => __( 'If checked, stock quantity will be reduced when items are added to cart.', 'woocommerce-cart-stock-reducer' ),
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
			'expire_items' => array(
				'title'             => __( 'Expire Items', 'woocommerce-cart-stock-reducer' ),
				'type'              => 'checkbox',
				'label'             => __( 'Enable Item Expiration', 'woocommerce-cart-stock-reducer' ),
				'default'           => 'no',
				'description'       => __( "If checked, items that stock is managed for will expire from carts.  You MUST set an 'Expire Time' below if you use this option", 'woocommerce-cart-stock-reducer' ),
			),
			'expire_time' => array(
				'title'             => __( 'Expire Time', 'woocommerce-cart-stock-reducer' ),
				'type'              => 'text',
				'description'       => __( 'How long before item expires from cart', 'woocommerce-cart-stock-reducer' ),
				'desc_tip'          => true,
				'placeholder'       => 'Examples: 10 minutes, 1 hour, 6 hours, 1 day',
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
			'ignore_status' => array(
				'title'             => __( 'Ignore Order Status', 'woocommerce-cart-stock-reducer' ),
				'type'              => 'multiselect',
				'default'           => array(),
				'options'           => wc_get_order_statuses(),
				'description'       => __( '(Advanced Setting) WooCommerce order status that prohibit expiring items from cart', 'woocommerce-cart-stock-reducer' ),
			),
		);
	}

	public function pre_get_stock_available( $stock, $product ) {
		return $this->get_stock_available( $product->id );
	}

}
