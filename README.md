# Fake Order Shield

Stop fake and risky WooCommerce orders in Bangladesh — before they cost you delivery charges.

**Features**

- Bangladeshi phone number validation (Bangla digits and +88 handled)
- Fraud check by delivery success ratio (store history or a courier-ratio API)
- Block list for phone, IP, device and email; auto block; repeat-order blocker
- Smart order filter, VPN / country block
- Incomplete order capture with call-back list
- Live visitors and session replay
- Steadfast and Pathao courier integration with status webhooks
- Protection levels (Light / Balanced / Strict) and guided settings

## Install

Download the ZIP from the [latest release](../../releases/latest), then in WordPress:
**Plugins → Add New → Upload Plugin → choose the ZIP → Install** (or "Replace current with uploaded" to update).

Requires WordPress 5.8+, PHP 7.4+ and WooCommerce.

## Releasing a new version

1. Bump `Version:` and `OSHIELD_VERSION` in `order-shield.php`, and `Stable tag:` in `readme.txt`.
2. Add a `= x.y.z =` entry under `== Changelog ==` in `readme.txt`.
3. Push to `main`. The **Release** workflow creates the `vx.y.z` tag and a GitHub Release with the installable ZIP.

The plugin folder inside the ZIP stays `order-shield`, so updating replaces the existing install and keeps all settings and data.

## License

GPL-2.0-or-later
