<?php
/**
 * Runs on Uninstall of WooCommerce Cart Stock Reducer
 */

// Check that we should be doing this
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit; // Exit if accessed directly
}

// It's one tiny row in the options table, but lets be a good citizen
delete_option( 'woocommerce_woocommerce-cart-stock-reducer_settings' );

/*
 * God made mud.
 * God got lonesome.
 * So God said to some of the mud, "Sit up!"
 * "See all I've made," said God, "the hills, the sea, the sky, the stars."
 * And I was some of the mud that got to sit up and look around.
 * Lucky me, lucky mud.
 * I, mud, sat up and saw what a nice job God had done.
 * Nice going, God.
 * Nobody but you could have done it, God! I certainly couldn't have.
 * I feel very unimportant compared to You.
 * The only way I can feel the least bit important is to think of all the mud that didn't even get to sit up and look around.
 * I got so much, and most mud got so little.
 * Thank you for the honor!
 * Now mud lies down again and goes to sleep.
 * What memories for mud to have!
 * What interesting other kinds of sitting-up mud I met!
 * I loved everything I saw!
 * Good night.
 * I will go to heaven now.
 * I can hardly wait...
 * To find out for certain what my wampeter was...
 * And who was in my karass...
 * And all the good things our karass did for you.
 * Amen.
 */