<?php
/**
 * PHPUnit bootstrap file
 *
 * @package Woocommerce_Cart_Stock_Reducer
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	throw new Exception( "Could not find $_tests_dir/includes/functions.php, have you run bin/install-wp-tests.sh ?" );
}

// Code from https://github.com/liquidweb/woocommerce-custom-orders-table

$_bootstrap = dirname( __DIR__ ) . '/vendor/woocommerce/woocommerce/tests/bootstrap.php';

// Verify that Composer dependencies have been installed.
if ( ! file_exists( $_bootstrap ) ) {
	echo "\033[0;31mUnable to find the WooCommerce test bootstrap file. Have you run `composer install`?\033[0;m" . PHP_EOL;
	exit( 1 );
}

// Gives access to tests_add_filter() function.
require_once $_tests_dir . '/includes/functions.php';
// Manually load the plugin on muplugins_loaded.
function _manually_load_plugin() {
	require dirname( dirname( __FILE__ ) ) . '/woocommerce-cart-stock-reducer.php';
}
tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

// Finally, Start up the WP testing environment.
require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once $_bootstrap;
