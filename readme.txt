=== Povikvane SMS for WooCommerce ===
Contributors: shtrakbg
Tags: sms, woocommerce, order notifications, text messages, orders
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.12.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Send order status SMS notifications and opt-in marketing messages from WooCommerce through your Povikvane account.

== Description ==

Povikvane SMS for WooCommerce sends text messages to your customers when their order changes status, using your own Povikvane account. A Povikvane account with message credits is required; the plugin does not work without one. Povikvane accounts are for businesses (companies and sole traders in the EU).

**What it does**

* Order messages: choose which statuses send a text (processing, completed, on hold, and more), edit the wording with placeholders such as the order number, order total and shop name, and see the SMS length and credit estimate while you type.
* Delivery tracking: each message is marked Delivered or Failed through a webhook and regular checks, with a note on the order.
* Promotions (off by default): optional marketing messages to customers who agree at checkout or in My Account. Every promotional message carries an unsubscribe link, respects sending hours and a frequency limit, and checks consent again just before it is sent.
* Viber: one switch sends order updates over Viber whenever an approved Viber template has the same wording as the message. Each message shows whether it is approved, and messages without an approved template go out as SMS. An SMS fallback is optional.
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
* Optional, only when an administrator presses "Sync customers to Povikvane": the phone number, name and consent date of customers who agreed to promotional messages are sent to Povikvane and added to a contact group. When such a customer later withdraws consent, or a privacy erasure request covers their order phone number, the contact is deleted there. Customers who opted out in Povikvane are never sent again.
* Optional, only when an administrator presses "Connect with Povikvane": the browser opens Povikvane's approval page with the shop name, shop address and this site's delivery-report address. After approval, Povikvane returns a one-time code, which the plugin exchanges for the API key, service ID and signing secret. The key and secret are stored encrypted on the site.
* Optional, only when an administrator sends a message for review: the text of a message is sent to Povikvane to be approved as a Viber template.
* Your API key and service ID are stored in your site database (the key is encrypted) and are sent to the service to authenticate each request.
* The secret address your site receives delivery reports on is sent to Povikvane (only when it is a public https address) so that it knows where to send them. The service then sends delivery, template and opt-out reports to that address, signed with a secret that is created for you and kept encrypted on your site.
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

Every promotional message contains an unsubscribe link. A customer can also change their choice in My Account, or use the [povikvane_optout] shortcode on a page of your shop, where anyone can type their phone number to stop promotional messages.

= What happens if a Viber message cannot be delivered? =

If you turned on the SMS fallback (not part of the Starter and Bronze plans), Povikvane sends the SMS itself when Viber cannot deliver. Separately, if Povikvane clearly refuses a Viber message (for example a template problem), the plugin sends that order update as an ordinary SMS instead. It does this only for clear refusals. If the answer is unclear (a timeout, "too many requests", "request in progress" or a server error), the Viber message may already be on its way, so the plugin never sends an SMS on top of it: it retries the same Viber message, or marks it as unconfirmed so you can check it in the message log.

= What happens when my credits run out? =

Messages wait and are sent once credits are available, and you see a notice in the admin. A message that waits too long is dropped rather than sent late.

= Can I send in Bulgarian? =

Yes. Bulgarian is included, and the default message texts follow the language setting. You can edit every text.

== Changelog ==

= 0.12.0 =
* New: "Connect with Povikvane". One button in the setup wizard and on the Settings tab takes you to Povikvane, you log in and approve this shop, and the plugin sets up the API key, service ID and signed delivery reports for you. There is nothing to copy. Entering a key by hand still works.
* The Settings tab shows which shop and company the site is connected as, and has a Disconnect button that removes the key from the site (your message history is kept).
* If the shop is disconnected in Povikvane, the plugin says so on the Overview and in the settings, stops sending, and offers to connect again.
* The one-click connection needs the site to use https and the same address for the site and its admin; otherwise the button explains why and the API key can be used instead.
* The plugin now runs on PHP 8.1 and newer (it previously asked for 8.3).
* Each person can pick the language of the plugin screens from the header; it does not change customer messages.
* Bulgarian is now the default language. A language chosen in the plugin now applies on WordPress 6.5 and later; before, the plugin could stay in English.
* Skipping landline numbers is on by default (sites that had it off by accident are corrected once).
* The setup wizard now includes the custom field labels check for every shop, not only when Viber is on.

= 0.11.0 =
* Delivery reports from Povikvane are now signed. The plugin asks Povikvane for a secret when it registers its address, keeps it encrypted, and refuses any report to its address that does not carry a valid signature. Settings shows whether reports are signed and has a button to create a new secret.
* A report that Povikvane sends again (it retries for up to 33 hours) is handled only once.
* Template changes are picked up in the background, so Povikvane gets its answer at once.
* Settings shows when Povikvane could not reach your site on its last attempts.

= 0.10.0 =
* The plugin now registers its delivery-report address in Povikvane by itself when your key has none, so you no longer paste it by hand. An address somebody already set is never replaced unless you press the button.
* Settings: a new "In Povikvane" line shows whether the address is registered and whether the last delivery worked, with buttons to register the address and to ask Povikvane to test it. Sites that are not on a public https address are told why delivery reports will be found by checking regularly instead.

= 0.9.0 =
* Sending messages to Povikvane for approval as Viber templates now works from the Messages tab. If a template name is taken, the plugin adds v2, v3 and so on. A limit reached (10 waiting, 20 new per day) is explained in plain words.
* Templates that Povikvane approves or rejects show up in the plugin at once, without waiting for the next refresh.
* Custom field labels can now be filled in or replaced from the plugin.
* "Sync customers to Povikvane" now works: customers who agreed are added to the group "Webshop SMS opt-in" together with the date they agreed. A customer who later withdraws consent is removed from that group, and a WordPress privacy erasure request removes the contact as well.
* When Povikvane reports that a customer opted out, promotional messages to them stop here too. A message to a number that opted out in Povikvane is marked as failed with a clear reason and costs nothing.

= 0.8.1 =
* Setup wizard, messages step: an optional Viber switch with the SMS fallback beside it. If your plan has no SMS fallback the switch is greyed out with a tooltip that says so. The SMS length estimate under each message is only shown when it matters: it is hidden when Viber is on and the fallback is off.
* The plugin checks the labels of your three custom fields in Povikvane and tells you whether you have none, some or all of them, then offers to fill in or replace them with the WooCommerce ones (order number, order total, shop name). Changing the labels from the plugin switches on when Povikvane supports it.
* New Viber templates sent from the plugin always put the order number in custom field 1, the total in field 2 and the shop name in field 3, so the labels stay true.
* New: the shortcode [povikvane_optout] puts a page on your shop where anyone can stop promotional SMS by typing their phone number. The Promotions tab explains all three ways to opt out (the link in every message, My Account, and this page) and how to add the page.
* The copy buttons (webhook address, support information) now work on sites without https.
* "Continue setup" on the Overview and in the reminder takes you to the first unfinished step instead of the start.
* The consent checkbox text now reads "Искам да получавам съобщения с оферти и новини от магазина. Може да се отпишете по всяко време."
* Bulgarian: "Service ID" is now "Идентификатор на услугата".

= 0.8.0 =
* Viber is now one switch for the whole shop instead of a choice per message. When it is on, the Messages tab shows how many approved Viber templates you have, and each message shows whether it is approved, awaiting approval, rejected or not approved yet. A message goes out over Viber when an approved template has the same wording; the fields of the template are matched to the order values automatically, so there is nothing to map by hand.
* Editing a message that is approved for Viber warns that the new wording goes out as SMS until it is approved, and offers to send it for approval.
* A "Send selected messages for approval" button with a confirmation that the Povikvane team will review them. It switches on as soon as Povikvane enables template requests for your account.
* The SMS fallback is off by default, because the Starter and Bronze plans do not include it. Turn it on in the Viber card if your plan has it.
* The placeholders {first_name}, {last_name}, {order_status_url} and {tracking_number} are no longer offered. Texts that still contain them lose them automatically. The default texts were rewritten so they can be approved as Viber templates.
* The review request has its own tab.
* The Users list and each user profile show whether the customer agreed to promotional SMS and when, and the WooCommerce Customers report gets the same column. A "Sync customers to Povikvane" button (to a group of its own) switches on when Povikvane enables it; until then use the CSV download.

= 0.7.2 =
* New switch in Advanced > Data and privacy: "Delete all Povikvane data when the plugin is deleted". When it is on, deleting the plugin removes the message log, the consent records, the settings and the saved API key. It is off by default, and deactivating the plugin never deletes anything.

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
