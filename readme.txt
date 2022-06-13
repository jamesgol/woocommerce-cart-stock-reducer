=== WooCommerce Cart Stock Reducer ===
Contributors: jamesgol
Donate link: https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=GAXXM656QPNGY
Tags: woocommerce, cart, expire, countdown, stock
Requires at least: 4.0
Tested up to: 5.8.1
Stable tag: 3.90
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

= Does this plugin work with Suhosin? =

Yes it does, but the default character length (64) for POST/REQUEST variable names is not sufficient. I suggest following
the recomendations from WooCommerce and increasing these values. For more information:
https://docs.woocommerce.com/document/problems-with-large-amounts-of-data-not-saving-variations-rates-etc/


== Changelog ==
= 3.90 = 
* Purge cache when a user logs in

= 3.85 =
* Keep reserve_stock_for_order from throwing exception when all items are in users cart
* Fix bug where a 'Quick Edit' or 'Bulk Edit' on the backend could change actually stock if the product is in a users cart

= 3.75 =
* Use WordPress object cache if enabled (Another major speed boost)
* Add configurable setting to refresh expiration time of cart items when new items are added, viewing cart, viewing checkout, or viewing checkout payment page
* Allow categories to be used to specify which items to expire from carts
* Allow reducing and expiring of items to be configured globally as well as per item

= 3.50 =
* Add support for removing products from composites/containers (Thanks photogenic89)
* Major speed increase for busy sites

= 3.40 = 
* Reduce time between checking stock and saving sessions (race condition between people adding same item to cart)
* Removed support for WooCommerce < 3.00
* Bug fix when another plugin causes WC_Order to not exist
* Allow backend to show quantity in carts even if plugin isn't enabled

= 3.30 = 
* Add 'stockpending' class to producs that are only virtually out of stock
* Add 'wc_csr_set_nocache' filter that can be used to disable setting of the no cache constants
* Added get_actual_stock_function that returns the actual stock of an item instead of the virtual stock
* Added advanced filters 'wc_csr_whitelist_get_stock_status' and 'wc_csr_whitelist_get_stock_quantity' that allow whitelisting functions that return actual data instead of virtual

= 3.15 =
* Fix bug that would allow more than one item 'old individually' to be purchased

= 3.10 =
* Handle a missed changed notice format in WooCommerce version 3.9

= 3.10 =
* Handle changed notice format in WooCommerce version 3.9

= 3.08 = 
* Fix display bug introduced in 3.06

= 3.06 = 
* Resolve issue where variations managed by the primary item were not being counted

= 3.05 =
* Ignore expired items when deciding earliest to expire item
* Ensure actual stock levels are displayed on the backend
* Fix styling of 'quantity in carts' column on the backend

= 3.00 =
* Set minimum WooCommerce version to 3.0
* Change method that quantity in carts are calculated to be much more efficient (less queries)
* Display quantity in carts on WooCommerce backend table
* If WooCommerce 'Hide out of stock items from the catalog' feature is enabled make sure virtually out of stock items are not hidden (can be override with a filter)
* Allow expiration time to be adjusted dynamically with 'wc_csr_expire_time_text' and 'wc_csr_expire_time' filters

= 2.10 =
* Fix issue where items that are actually out of stock show pending
* Make sure we are backwards compatible with WooCommerce < 2.6
* Add whitelist of functions that we return real stock for and not virtual to deal with some WC 3.x issues
* Fix issue with timer stopping on cart update

= 2.00 =
* Add support for WooCommerce 3.0
* Upgrade jquery countdown to version 2.1.0 (http://keith-wood.name/countdown.html)
* Include item URL link in notice when an item expires from cart

= 1.75 =
* Add configuration option to set WooCommerce order status to ignore expiration on
* Change Undo URL on items that are managed so the user is redirected to product page instead of adding back to cart
* Fix issue with multiple plugins registering WooCommerce integrations
* Handle backordered items properly
* Automatically use local language for countdown if there is a translation available
* Properly pluralize 'Please checkout' text

= 1.55 =
* Move cart expiration check so it does not happen on every page load

= 1.50 =
* Fix issue causing auto refreshes from adding items to cart when AJAX isn't used
* Fix bug where we would sometimes act on variations that weren't marked managed
* Make sure total price is updated when an item is expired
* Ensure we return proper quantity to non managed stock items
* Move cart expiration check to an action that will always be called regardless of which theme is used
* Add count of number of expiring items to notice when adding new item and set the countdown for the newly added item
* Include variation info in notice when item has expired (if WooCommerce version >= 2.5)
* Add filter 'wc_csr_expire_ignore_status' to allow passing array of WooCommerce order status to ignore expiration on

= 1.25 =
* If you are using WooCommerce >= 2.5 you must update to this version
* Refresh the page when an expiration countdown hits 0
* Allow sessions to be loaded from new table in WooCommerce >= 2.5
* Fix fatal error when something else destroys the users session

= 1.15 =
* Fix countdown not showing when using cart widget to delete item
* Fix minor notice being logged when used with some other unknown plugin

= 1.05 =
* Fix issue with url in 'View Cart' button being null
* Add loading translations
* Allow expiration time to be empty if expiration is disabled

= 1.03 =
* Had issues with my deployment script so had to bump version up

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

* Look into adding ajax and mini-cart notices
* Take some screenshots for wordpress.org
* Add some kind of indicator to cart so you know what items will expire

== Thanks ==

* Bob DeYoung of [BlueLotusWorks](https://github.com/bluelotusworks) for testing, feedback, and support

