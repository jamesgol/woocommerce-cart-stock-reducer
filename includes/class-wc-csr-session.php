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

}