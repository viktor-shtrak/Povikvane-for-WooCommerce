=== Povikvane SMS for WooCommerce ===
Contributors: shtrakbg
Tags: sms, woocommerce, order notifications, text messages, orders
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 8.3
Stable tag: 0.7.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Send order status SMS notifications and opt-in marketing messages from WooCommerce through your Povikvane account.

== Description ==

Povikvane SMS for WooCommerce sends text messages to your customers when their order changes status, using your own Povikvane account. A Povikvane account with message credits is required; the plugin does not work without one. Povikvane accounts are for businesses (companies and sole traders in the EU).

**What it does**

* Order messages: choose which statuses send a text (processing, completed, on hold, and more), edit the wording with placeholders such as the customer's name, order number and tracking link, and see the SMS length and credit estimate while you type.
* Delivery tracking: each message is marked Delivered or Failed through a webhook and regular checks, with a note on the order.
* Promotions (off by default): optional marketing messages to customers who agree at checkout or in My Account. Every promotional message carries an unsubscribe link, respects sending hours and a frequency limit, and checks consent again just before it is sent.
* Viber: send order updates over Viber using your approved templates, with an automatic SMS fallback (when your account has Viber).
* Message log: every message with its status, the reason when one was skipped, and a CSV export.
* Credits: your balance is shown and kept current, with a warning when it runs low.
* Safety: no messages for old orders, landline numbers or copied sites, and sending pauses if many messages are created at once.
* Privacy tools: suggested policy text, and personal data export and erase for customers.
* Works with the WooCommerce Blocks checkout, the classic checkout and High-Performance Order Storage.
* Bulgarian and English are included. The plugin follows your site language, and you can choose another in the settings.

A setup wizard opens after activation and walks you through connecting your account, choosing messages and sending a test.

== External services ==

This plugin connects to the Povikvane messaging API, operated by the provider of Povikvane, to send messages and to check their delivery status. A Povikvane account is required.

* Service: Povikvane messaging API (https://app.povikvane.com).
* What is sent: the recipient phone number, the message text and an order reference, for every message you configure the plugin to send. Delivery status requests send only the message identifier. Balance requests send only your credentials.
* When it is sent: only when an enabled order event occurs, when an administrator sends a message or a test message manually, when the plugin checks the delivery status of messages it has sent, and when it refreshes your credit balance. Promotional messages are only sent to customers who gave consent.
* Your customers' phone numbers belong to your shop, which decides why and how they are used. Povikvane sends the messages on your behalf and keeps a record of them for up to 12 months; see its Privacy Policy.
* Your API key and service ID are stored in your site database (the key is encrypted) and are sent to the service to authenticate each request.
* The service sends delivery reports back to your site at a secret address shown in the plugin settings. No customer data is sent to the service by this.
* Terms of Service: https://app.povikvane.com/terms
* Privacy Policy: https://app.povikvane.com/privacy

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install it from the Plugins screen.
2. Activate the plugin. WooCommerce 11.0 or newer is required.
3. Follow the setup wizard that opens, or go to WooCommerce > Povikvane - Viber & SMS. Enter your Povikvane API key and service ID, choose your messages and send a test.

== Frequently Asked Questions ==

= Do I need a Povikvane account? =

Yes. The plugin sends messages through your own Povikvane account and uses its credits. Povikvane is offered to businesses registered in Bulgaria or another EU country.

= Will customers get promotional messages without asking? =

No. Promotions are off by default, and only customers who tick the consent box, at checkout or in My Account, receive them. Order status messages are separate and describe the customer's own order.

= How do customers unsubscribe? =

Every promotional message contains an unsubscribe link. A customer can also change their choice in My Account.

= What happens when my credits run out? =

Messages wait and are sent once credits are available, and you see a notice in the admin. A message that waits too long is dropped rather than sent late.

= Can I send in Bulgarian? =

Yes. Bulgarian is included, and the default message texts follow the language setting. You can edit every text.

== Changelog ==

= 0.7.1 =
* Every item in "Needs attention" can be dismissed with its X and comes back after a week if the problem is still there.
* The latest messages show whether each went out as SMS or Viber.
* Clearer Bulgarian wording for the results ("Доставени", "Недоставени").

= 0.7.0 =
* Settings are now split into Settings (account, language, Viber, delivery reports) and Advanced (protections, credits warning, data retention, diagnostics). Old links still work.
* The Overview shows results for the last 7, 30 or 90 days: sent, delivered, did not arrive, credits used and Viber use including SMS fallbacks.
* A "Needs attention" list on the Overview with a button for each problem: no credits, rejected key, low credits, many failures, delivery reports not arriving, Viber statuses that go out as SMS, late scheduled tasks.
* The message log has a "Needs attention" view with plain-language reasons for failures and a Resend selected button.
* A Support information box in Advanced to copy to support: versions, connection state, settings and recent results, never your key, phone numbers or message text.

= 0.6.5 =
* Viber templates with custom fields now work: Povikvane Viber templates can only contain a first name, a last name and custom fields 1 to 3. The first two fill themselves, and for each custom field you choose what it is filled with (order number, order total, shop name and so on) per order status. Until every field has a value, that message is sent as an SMS.

= 0.6.4 =
* Drop-downs now match the Povikvane design instead of the browser's own, with keyboard support and a clear reason shown for templates that cannot be used yet.
* Tidier order message rows: the Viber template field only shows when Viber is chosen, and the Viber explanation is folded away.

= 0.6.3 =
* Viber no longer requires your own Viber sender: accounts that send through Povikvane's shared Viber sender can use Viber too. Availability now depends on the Viber templates being enabled for your account.

= 0.6.2 =
* Message statuses on the Overview and in the message log now update by themselves, and a message that is still on its way is checked with the service within seconds, so a delivery shows up without reloading or waiting for a webhook.
* The low credits warning can be dismissed (it returns after a week if credits are still low) and no longer shows the warning level.
* New Viber card on the Connection tab: whether Viber is available and why not, and the list of your Viber templates with what is still needed for each.

= 0.6.1 =
* One menu entry: WooCommerce > Povikvane - Viber & SMS. The message log is a tab on the same screen.
* New "Test the webhook address" button checks that your site answers at its webhook address.
* The Messages tab now says why Viber is not offered (not checked, templates not enabled, no Viber sender, SMS fallback not enabled yet).
* The Bulgarian translation was reviewed and reworded.

= 0.6.0 =
* Viber for order messages: pick an approved Viber template for an order status and the message goes out over Viber, with an automatic SMS if Viber cannot deliver it. Anything unusual (template not approved, a link in the message, a missing value, an SMS longer than one part) is sent as a normal SMS, so no update is lost.
* The message log shows which channel delivered each message and the credits actually charged.

= 0.5.0 =
* New setup wizard: connect your account, choose messages, send a test message and watch it arrive, and add the webhook. It opens after activation, and a reminder and an Overview checklist stay until setup is done.
* Redesigned settings: one page with tabs (Overview, Connection, Messages, Promotions, Safety & data), status at a glance, and a message log that matches.
* The Settings saved confirmation now shows, and the page remembers which tab you were on.
* Bulgarian translation included. The plugin follows the site language, and a Language setting lets you choose Bulgarian or English; default message texts follow it too.

= 0.4.0 =
* Promotional SMS (off by default): an unticked consent checkbox at checkout (Blocks and classic) and in My Account, an unsubscribe link in every promotional message, sending hours, a frequency limit and an optional review request after a completed order.
* Consent is checked again just before each promotional message is sent, so a withdrawal always wins.
* Download the list of customers who agreed, ready to import into Povikvane.
* Privacy tools: suggested policy text, personal data export and erase.

= 0.3.0 =
* Delivery tracking: messages now become "Delivered" or "Failed" through a webhook and regular checks, with order notes.
* New message log screen with filters, search, Resend and CSV export.
* Interrupted sends are recovered safely without sending duplicates.
* Old message text and records are erased automatically (configurable).
* Fix: the low-credits warning level was not applied in 0.2.1.

= 0.2.1 =
* The credit balance now stays current: it drops as messages are sent, is refreshed from the service shortly afterwards and every 15 minutes, and updates in the background when you open the settings page.
* New low-credits warning: an admin notice and a daily email when credits fall below a level you choose.

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
