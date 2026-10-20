# Store Messages

Beautiful, customisable emails from your WooCommerce store to your customers, sent from the order screen. Templates and layout are plain HTML you control.

WooCommerce's "Note to customer" email is the usual way to send a tracking link, and it looks dated. Store Messages gives every message a clean, modern layout and adds a **Send a message** box to the order screen.

![Order screen: Send a message box](docs/screenshots/order-box.png)

## Features

- **Send from the order**: pick a message, paste the tracking link (or type the number and pick the carrier), add a personal note, preview, send.
- **Built-in occasions**: order shipped (tracking), ready for pickup, item unavailable, refund issued, order delayed, payment reminder, review request and a blank personal message. Add as many of your own as you like.
- **Plain HTML**: every template and the layout around them are HTML in a code editor with a live preview (desktop and mobile), sample or real order data, and test sends.
- **Placeholders**: `{first_name}`, `{order_number}`, `{tracking_url}`, `{order_items}`, `{refund_amount}` and more, plus `{if tracking_url}…{else}…{/if}` blocks.
- **Better customer notes**: "Note to customer" is sent with your layout; a tracking link pasted into a note becomes a "Track your package" button.
- **One email instead of two**: mark the order Completed while sending, without WooCommerce's "Order complete" email on top.
- **Tracking for customers**: shown on the order in My account and in WooCommerce's own emails.
- **Every email looks the same**: WooCommerce's own emails (order received, completed, refunded, new account…) and WordPress's plain-text emails (password reset, new user…) are wrapped in the same layout. Each can be switched off.
- **Newsletters**: a subscriber list (checkout checkbox, My account, a sign-up popup and a shortcode), campaigns in the same layout with product cards, batched sending and one-click unsubscribe.
- **Welcome code**: new subscribers get a welcome email with their own single-use code, e.g. 10% off the first order.
- **Brave Popup**: keep your Brave popup designs; their forms can add subscribers here instead of to another newsletter service.
- **Moving from MailPoet**: import its subscribers; their old unsubscribe links keep working.
- **A record of everything**: each send becomes a private order note, appears in the order's history and on the Activity tab.
- **Carriers**: Swiss Post, La Poste/Colissimo, DHL, DPD, GLS, UPS, FedEx, PostNL, Poste Italiane, Correos, InPost, Royal Mail, USPS and a universal tracker; edit or add your own.
- **Delivery**: sent through `wp_mail()`, so your SMTP or email-delivery plugin applies. Plain-text alternative included.
- Updates from GitHub through WordPress's normal "Update now".

No accounts, no tracking pixels, no upsells.

![Template editor with live preview](docs/screenshots/templates-editor.png)

![Templates](docs/screenshots/templates.png)

![The email a customer receives](docs/screenshots/email.png)

## Install

1. Download `store-messages.zip` from the [latest release](https://github.com/tranmani/store-messages/releases/latest).
2. In WordPress: Plugins → Add New → Upload Plugin, choose the zip, activate.
3. WooCommerce → Messages to adjust the layout and templates; open any order to send.

Requires WordPress 6.4+, PHP 8.1+ and WooCommerce. Compatible with WooCommerce's order storage (HPOS).

## Template syntax

| Syntax | Meaning |
|---|---|
| `{first_name}` | A placeholder. Text is HTML-escaped; `{order_items}`, `{message}`, `{note}` and the addresses are HTML. |
| `{if tracking_url}…{/if}` | Shown only when the value is not empty. |
| `{if !message}…{/if}` | Shown only when it is empty. |
| `{if x}…{else}…{/if}` | Either / or. Blocks can be nested. |
| `{bestsellers}` | Four best-selling products as cards. |
| `{products:12,34}` | These products as cards. |
| `{coupon_code}` | In subscriber emails: the subscriber's personal code (created on first use). |

The layout is a full HTML document; `{content}` is where a template's body goes, and `{heading}`, `{preheader}`, `{footer}`, `{logo}`, `{accent}`, `{background}`, `{surface}` and `{text}` are available there.

## For developers

- `stms_placeholders` (filter): add or change placeholder values — `( array $values, ?WC_Order $order, array $extra )`.
- `stms_email_html` (filter): the final HTML.
- `stms_email_headers` (filter): mail headers.
- `stms_sent` (action): after a message is sent for an order.
- `stms_wc_email_styles` (filter): the CSS WooCommerce inlines into its emails inside the layout.
- `stms_style_wp_email` (filter): return false to leave a plain-text WordPress email as it is (receives the `wp_mail()` arguments).
- `stms_update_repository` (filter): point the updater at a fork.
- `stms_subscribed` / `stms_unsubscribed` (actions): a subscriber row.
- `stms_marketing_placeholders`, `stms_marketing_headers` (filters): subscriber email values and headers.
- `stms_campaign_sent` (action): a campaign finished.
- `stms_show_popup` (filter): show the sign-up popup on this page.
- `stms_popup_wait_for` (filter): CSS selectors of cookie banners the popup waits for.
- `stms_current_language` (filter): the visitor's language code.
- `POST /wp-json/stms/v1/subscribe` with `email`, optional `name`: subscribe from your own form.

## License

GPL-2.0-or-later.
