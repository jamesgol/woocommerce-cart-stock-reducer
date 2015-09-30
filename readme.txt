=== WooCommerce Cart Stock Reducer ===
Contributors: jamesgol
Donate link: https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=GAXXM656QPNGY
Tags: woocommerce
Requires at least: 4.0
Tested up to: 4.3.1
Stable tag: 0.75
WC requires at least: 2.2
WC tested up to: 2.4.7
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Allow WooCommerce inventory stock to be reduced when adding items to cart and/or expire items from the cart

== Description ==

[WooCommerce](http://www.woothemes.com/woocommerce/) doesn't remove an item from inventory until someone actually buys
that item.  This plugin can reduce the "virtual" stock quantity of an item without actually modifying the item
inventory, so there should be no problems with missing data if somehow the cart data is deleted.  This plugin isn't for
everyone, but people that are selling specialty items will find it useful and hopefully eliminate some customer
support nightmares.

The plugin can automatically expire items from the cart (disabled by default) with a configurable expiration time.
Expiration times are plain english using whatever types php's strtotime can support (Examples: 10 minutes, 1 hour, 6 hours, 1 day)
Per item expiration time can be configured by adding a Custom Field to each item using the configured
'Expire Custom Key' (default name is 'csr_expire_time').

Expiration can be enabled independently of reducing the cart stock, so this plugin can also be used to expire items at
other intervals than the default WooCommerce cart/session expiration.

An issue was opened on the [WooCommerce issue tracker](https://github.com/woothemes/woocommerce/issues/5966) regarding
this problem and someone posted on Facebook about it, which caught my attention.

Please submit bug reports, feature requests, and pull requests via the [GitHub repository](https://github.com/jamesgol/woocommerce-cart-stock-reducer)

== Installation ==

1. Download plugin from [GitHub](https://github.com/jamesgol/woocommerce-cart-stock-reducer) or the [wordpress.org repository](https://wordpress.org/plugins/woocommerce-cart-stock-reducer/)
1. Upload plugin and activate through the 'Plugins' menu in WordPress
1. Configure plugin specific settings under the WooCommerce->Settings->Integration admin page


== Frequently Asked Questions ==

= What happens if two users click the add to cart at the same time? =

The first request that hits the server will get the item if there is only one available.  The other person will receive
a "Item is no longer available" message.

= What happens if someone tries to increase the quantity from their shopping cart and that amount is unavailable? =

They will receive a "Quantity requested not available" message and their original quantity will be retained.

= What setting should I use for 'Minimum Stock to Skip Check'? =

You can set this by using the 'wc_csr_min_no_check' filter.  This is an advanced option and should only be used on high
volume sites with predictable orders.  The setting to use depends on your stock quantites and how much you expect to
sell.  If you have a stock of 100 and only expect to sell 10 per hour you could set this to 25 and set the expiration
to one hour and you should be safe. Always err on the side of caution, you don't want to run out of stock when someone
believes they will get an item.  If in doubt, don't use this option.

= What adjustments need to be made to caching? =

We recommend turning off page and database caching for pages affected by this plugin. For example, assuming your site 
uses the default Woocommerce "shop" page and W3 Total Cache, add "shop/*" (without the quotes) to "Never cache the 
following pages:" at the page cache and database cache settings.


== Changelog ==
= 1.0 =
* Add 2 new options to append strings to the pending order text.
* Add 'wc_csr_stock_pending_text' filter (used internally) to replace pending order text
* Add a link from the plugins page to settings (Thanks Gabriel Reguly!)
* Cache stock value so we don't continually decrement the value every time we check it
* Add call to action 'wc_csr_before_remove_expired_item' to remove_expired_item function
* Add 'wc_csr_adjust_cart_expiration' action to adjust existing items expire time
* Move 'Expire Custom Key' from config to filter 'wc_csr_expire_custom_key'
* Move 'Minimum Stock to Skip Check' from config to a filter 'wc_csr_min_no_check'

= 0.75 =
* Allow countdown timer to be configured when it is shown (Always, Never, Only When Items are added)
* Allow cart expiration to happen without managing being enabled
* Add countdown timer instead of just displaying static string
* Added 'wc_csr_expiring_cart_notice' filter to allow changing of countdown text in cart
* Moved expire notice to 'wc_add_to_cart_message' filter so it get appended on the existing message
* Added 'wc_csr_expire_notice' filter to allow changing of expiration notice displayed after adding item to cart
* Added 'wc_csr_expired_cart_notice' filter to allow changing of notice displayed when item expires from cart

= 0.5 =
* Handle variable products
* Add cart expiration

= 0.1 =
* First initial release

== TODO ==

* Make expiration strings nicer
* Test with backordered products
* Look into adding ajax and mini-cart notices
* Take some screenshots for wordpress.org
* Add some kind of indicator to cart so you know what items will expire
* Add option to refresh page (or do something) when items have expired from cart

== Thanks ==

* Bob DeYoung of [BlueLotusWorks](https://github.com/bluelotusworks) for testing, feedback, and support

