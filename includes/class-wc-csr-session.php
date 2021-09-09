<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handle data for arbitrary customer session.  WC_Session_Handler is too strict in handling only current session
 * Implements the WC_Session abstract class.
 *
 * From 2.5 this uses a custom table for session storage. Based on https://github.com/kloon/woocommerce-large-sessions.
 *
 * @class    WC_CSR_Session
 * @category Class
 */
class WC_CSR_Session extends WC_Session {

	/** @var string session due to expire timestamp */
	private $_session_expiring;

	/** @var string session expiration timestamp */
	private $_session_expiration;

	/** @var string Custom session table name */
	private $_table;

	/**
	 * Unserialized Session Data.
	 *
	 * @var array $_unserialized_data Data array.
	 */
	private $_unserialized_data;

	/**
	 * Constructor for the session class.
	 */
	public function __construct( $customer_id = null, $data = null, $expiry = null ) {
		global $wpdb;

		$this->_table = $wpdb->prefix . 'woocommerce_sessions';
		if ( null !== $customer_id ) {
			$this->_customer_id = $customer_id;
		}
		if ( null !== $data ) {
			$this->_data = maybe_unserialize( $data );
		}
		if ( null !== $expiry ) {
			$this->_session_expiration = $expiry;
		}
	}


	/**
	 * Set session expiration.
	 */
	public function set_session_expiration() {
		$this->_session_expiring   = time() + intval( apply_filters( 'wc_session_expiring', 60 * 60 * 47 ) ); // 47 Hours.
		$this->_session_expiration = time() + intval( apply_filters( 'wc_session_expiration', 60 * 60 * 48 ) ); // 48 Hours.
	}

	/**
	 * Gets a cache prefix. This is used in session names so the entire cache can be invalidated with 1 function call.
	 *
	 * @return string
	 */
	private function get_cache_prefix() {
		return WC_Cache_Helper::get_cache_prefix( WC_SESSION_CACHE_GROUP );
	}

	/**
	 * Get a session variable, but only unserialize it once
	 *
	 * @param string $key Key to get.
	 * @param mixed  $default used if the session variable isn't set.
	 * @return array|string value of session variable
	 */
	public function get( $key, $default = null ) {
		$key = sanitize_key( $key );
		if ( isset( $this->_unserialized_data[ $key ] ) ) {
			$value = $this->_unserialized_data[ $key ];
		} elseif ( isset( $this->_data[ $key ] ) ) {
			$value = $this->_unserialized_data[ $key ] = maybe_unserialize( $this->_data[ $key ] );
		} else {
			$value = $default;
		}
		return $value;
	}
}