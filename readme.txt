=== Povikvane SMS for WooCommerce ===
Contributors: shtrakbg
Tags: sms, woocommerce, order notifications, text messages, orders
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 8.3
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Send order status SMS notifications and opt-in marketing messages from WooCommerce through your Povikvane account.

== Description ==

Povikvane SMS for WooCommerce sends text messages to your customers when their order changes status, using your own Povikvane account.

A Povikvane account with message credits is required. The plugin does not work without one.

This is an early development version; features are being added.

== External services ==

This plugin connects to the Povikvane messaging API, operated by the provider of Povikvane, to send SMS messages and to check their delivery status. A Povikvane account is required.

* Service: Povikvane messaging API (https://app.povikvane.com).
* What is sent: the recipient phone number, the message text, and an order reference, for every message you configure the plugin to send. Delivery status requests send only the message identifier.
* When it is sent: only when an enabled order event occurs, when an administrator sends a message or a test message manually, and when the plugin checks the delivery status of messages it has sent. Marketing messages are only sent to customers who gave consent.
* Your API key and service ID are stored in your site database (encrypted) and are sent to the service to authenticate each request.
* Terms of Service: TODO add URL.
* Privacy Policy: TODO add URL.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install it from the Plugins screen.
2. Activate the plugin. WooCommerce 11.0 or newer is required.
3. Enter your Povikvane API key and service ID in the plugin settings (available in a later version).

== Changelog ==

= 0.2.0 =
* Order status messages: choose which statuses send a text, edit the text with placeholders and see the SMS length and credit estimate while typing.
* Safety guards: no messages for old orders, landline numbers or copied sites; sending pauses when many messages are created at once.
* Manual SMS box on the order screen.
* Skipped orders are logged with the reason and noted on the order.

= 0.1.1 =
* Added optional debug logging to WooCommerce > Status > Logs.
* Settings page now shows whether the balance and lookup endpoints are enabled on the service.

= 0.1.0 =
* Initial development skeleton.
