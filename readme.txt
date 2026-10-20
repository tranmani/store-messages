=== Store Messages ===
Contributors: storemessages
Tags: woocommerce, email, tracking, order notes, email templates
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.1
Requires Plugins: woocommerce
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Beautiful, customisable emails from your WooCommerce store to your customers, sent from the order screen. Templates and layout are plain HTML you control.

== Description ==

WooCommerce's "Note to customer" email is the usual way to send a tracking link, and it looks dated. Store Messages gives every message to your customers a clean, modern layout, and adds a "Send a message" box to the order screen.

* **Send from the order** — pick a message, paste the tracking link (or type the number and pick the carrier), add a personal note, preview, send.
* **Built-in occasions** — order shipped (tracking), ready for pickup, item unavailable, refund issued, order delayed, payment reminder, review request and a blank personal message. Add as many of your own as you like.
* **Plain HTML** — every template and the layout around them are HTML in a code editor with a live preview (desktop and mobile), sample or real order data, and test sends.
* **Placeholders** — `{first_name}`, `{order_number}`, `{tracking_url}`, `{order_items}`, `{refund_amount}` and more, plus `{if tracking_url}…{else}…{/if}` blocks.
* **Better customer notes** — "Note to customer" is sent with your layout; a tracking link pasted into a note becomes a "Track your package" button.
* **One email instead of two** — mark the order Completed while sending, without WooCommerce's "Order complete" email on top.
* **Tracking for customers** — shown on the order in My account and in WooCommerce's own emails.
* **A record of everything** — each send becomes a private order note, appears in the order's history and on the Activity tab.
* **Carriers** — Swiss Post, La Poste/Colissimo, DHL, DPD, GLS, UPS, FedEx, PostNL, Poste Italiane, Correos, InPost, Royal Mail, USPS and a universal tracker; edit or add your own.
* **Delivery** — sends through `wp_mail()`, so your SMTP or email-delivery plugin applies. Plain-text alternative included.
* Updates from GitHub through WordPress's normal "Update now".

No accounts, no tracking pixels, no upsells.

== Installation ==

1. Download `store-messages.zip` from the latest GitHub release and upload it on Plugins → Add New → Upload.
2. Activate. Go to WooCommerce → Messages to adjust the layout and templates.
3. Open any order and use the "Send a message" box.

== Frequently Asked Questions ==

= Do my emails still go through my SMTP plugin? =

Yes. Messages are sent with `wp_mail()`.

= Can a shop manager edit the HTML? =

Yes. Administrators keep their HTML exactly as written; other users are limited to the HTML WordPress allows in posts plus email layout tags.

= How do I change where updates come from? =

Use the `stms_update_repository` filter, or turn update checks off on the Settings tab.

== Changelog ==

= 1.1.0 =
* WooCommerce's own emails (order received, processing, completed, refunded, new account, password reset, the emails to you…) now use the Store Messages layout: same logo, card, colours and footer. Their wording stays WooCommerce's. Product images are shown in the order table.
* WordPress's plain-text emails (password reset, new user, email change…) are sent as HTML in the layout, with the original text as the plain-text part.
* Both can be switched off on the Settings tab ("All other emails").
* Takes over from MailPoet's "use the MailPoet editor for WooCommerce emails" when both are active.
* An empty preview line no longer hides the start of the message in the inbox list.

= 1.0.0 =
* First release.
