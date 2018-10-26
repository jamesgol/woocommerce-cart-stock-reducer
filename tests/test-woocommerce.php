<?php
/**
 * Class WooCommerceTest
 *
 * @package Woocommerce_Cart_Stock_Reducer
 */

/**
 * Test for WooCommerce.
 */
class WooCommerceTest extends WC_Unit_Test_Case {

	/**
	 * Test if WooCommerce version is set.
	 */
	function test_version() {
		$version = constant('WOOCOMMERCE_VERSION');
		$this->assertNotNull( true );
	}
}
