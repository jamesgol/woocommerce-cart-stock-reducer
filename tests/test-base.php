<?php
/**
 * Class BaseTest
 *
 * @package Woocommerce_Cart_Stock_Reducer
 */

/**
 * Test for WooCommerce.
 */
class BaseTest extends WC_Unit_Test_Case {
	public $csr;

	public $name = 'woocommerce-cart-stock-reducer';

	public function setUp() {
		parent::setUp();
		$integrations = WC()->integrations->get_integrations();
		$this->csr = $integrations[ $this->name ];
	}

	/**
	 * Test if WooCommerce integration is active
	 */
	function test_integration() {
		$integrations = WC()->integrations;
		$this->assertInstanceOf( 'WC_Integrations', $integrations );

		$this->assertArrayHasKey( $this->name, $integrations->integrations );
		$this->assertArrayHasKey( $this->name, $integrations->get_integrations() );
		$this->assertInstanceOf( 'WC_Cart_Stock_Reducer', $integrations->integrations[ $this->name ] );
	}

	/**
	 * Test if CSR is enabled
	 */
	function test_csr_enabled() {
		$enabled = $this->csr->cart_stock_reducer;
		$this->assertEquals( 'yes', $enabled );
	}


}
