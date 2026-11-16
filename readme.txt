=== Order Shield ===
Contributors: brandingsolutionsbd
Tags: woocommerce, fraud, fake order, live visitors, steadfast, pathao, bangladesh
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
WC requires at least: 6.0
WC tested up to: 10.2
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Fake order protection for Bangladeshi WooCommerce stores.

== Description ==

* Fraud check: delivery success ratio for every order, from your own store history and (optionally) any courier-ratio API you subscribe to.
* Block list: block phone numbers, IP addresses, devices and emails. A blocked device stays blocked in every browser on it (hardware fingerprint). Bulk add, import/export CSV, expiry dates.
* Auto block: block a phone + IP + device that orders too many times in a short window.
* Phone validation: only valid BD mobile numbers; Bangla digits and +88 are converted.
* Repeat order blocker.
* Smart order filter: block the order when the success ratio is too low.
* VPN / proxy block and country restriction (proxycheck.io).
* Incomplete orders: capture abandoned checkouts, call / WhatsApp the customer, create the order in one click, CSV export, daily email summary.
* Live visitors: who is online right now, the page each visitor is on, page views, traffic source (Facebook, Google…), cart value, the pages of every visit, and watching a visitor's screen live.
* Session replay of the whole visit or the checkout page only (rrweb).
* Steadfast and Pathao: send single or bulk orders, live status via webhooks, automatic order status changes, "In Transit" and "Returned" statuses.
* Activity log of every stopped checkout attempt.
* REST API for chatbots: GET /wp-json/order-shield/v1/orders?phone=01XXXXXXXXX (header X-API-Key).
* Works with classic checkout, CartFlows and the block checkout. HPOS compatible.

== Installation ==

1. Upload the zip in Plugins → Add New → Upload Plugin, then activate.
2. Open Order Shield → Settings and switch on what you need, tab by tab.
3. For couriers, add your Steadfast / Pathao keys in Settings → Courier and paste the webhook URL into the courier panel.

== Third-party services ==

Order Shield only contacts outside services you switch on:
* Steadfast (portal.packzy.com) and Pathao (api-hermes.pathao.com) — to create and track parcels.
* proxycheck.io — only when VPN block or country restriction is on, to check the visitor's IP.
* The courier-ratio API URL you enter on the Fraud Check tab, if any.

== Credits ==

Session replay uses rrweb (MIT License), bundled in assets/vendor/rrweb.

== Changelog ==

= 1.2.0 =
* New: watch a visitor's screen live from Live Visitors (updates about every second).
* Device block now covers every browser on the blocked device (hardware fingerprint, optionally tied to the same network).
* Removed: SMS gateway, SMS log, order status SMS and OTP verification.

= 1.1.0 =
* New: Live Visitors screen (online now, page views, pages open right now, visitor journey, watch session, block IP/device).
* Session replay now records the whole visit (or checkout only) with smaller recordings and its own retention setting.

= 1.0.0 =
* First release.
