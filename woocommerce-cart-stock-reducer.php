<?php
/*
Plugin Name: WooCommerce Cart Stock Reducer
Description: Allow WooCommerce inventory stock to be reduced when adding items to cart and/or expire items from the cart
Version: 3.90
Author: James Golovich
License: GPL2
Requires at least: 4.0
Tested up to: 5.8.1
Text Domain: woocommerce-cart-stock-reducer
Domain Path: /languages/
WC requires at least: 3.0
WC tested up to: 5.6.0
*/

/*  Copyright 2014  James Golovich  (email : james@gnuinter.net)

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License, version 2, as
    published by the Free Software Foundation.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

function make_cart_stock_reducer_go_now( $integrations = array() ) {
	if ( version_compare( WC_VERSION, '3.0', '<' ) ) {
		WC_Admin_Settings::add_message( __( 'WooCommerce Cart Stock Reducer requires WooCommerce 3.0 or newer.', 'woocommerce-cart-stock-reducer' ) );
		return $integrations;
	}
	if ( class_exists( 'WC_Integration' ) ) {
		// Three... Two... One... MAKE ROCKET GO NOW!
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-woocommerce-cart-stock-reducer.php';
		$integrations[] = 'WC_Cart_Stock_Reducer';
		return $integrations;
	}
}

add_action( 'woocommerce_integrations', 'make_cart_stock_reducer_go_now' );
